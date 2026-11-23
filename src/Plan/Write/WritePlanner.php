<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Write;

use Ggs\JsonApiEngine\Model\DeleteForeignDataInterface;
use Ggs\JsonApiEngine\Model\ForeignDataSource;
use Ggs\JsonApiEngine\Model\InsertForeignDataInterface;
use Ggs\JsonApiEngine\Model\ModelTree;
use Ggs\JsonApiEngine\Model\UpdateForeignDataInterface;
use Ggs\JsonApiEngine\Parser\Write\DeleteOperation;
use Ggs\JsonApiEngine\Parser\Write\InsertOperation;
use Ggs\JsonApiEngine\Parser\Write\KeepOperation;
use Ggs\JsonApiEngine\Parser\Write\RelationshipOperation;
use Ggs\JsonApiEngine\Parser\Write\UpdateOperation;
use Ggs\JsonApiEngine\Parser\Write\WriteOperation;
use Ggs\JsonApiEngine\Plan\Delete\DeleteEntityNode;
use Ggs\JsonApiEngine\Plan\Delete\DeleteForeignDataNode;
use Ggs\JsonApiEngine\Plan\Insert\InsertForeignDataNode;
use Ggs\JsonApiEngine\Plan\Insert\InsertPlanner;
use Ggs\JsonApiEngine\Plan\Insert\InsertResourceNode;
use Ggs\JsonApiEngine\Plan\Update\UpdateForeignDataNode;
use Ggs\JsonApiEngine\Plan\Update\UpdatePlanner;
use Ggs\JsonApiEngine\Plan\Update\UpdateResourceNode;
use Ggs\JsonApiEngine\Resource;
use Ggs\JsonApiEngine\Resource\InsertableInterface;
use LogicException;

/**
 * Планировщик записи (POST, PATCH, DELETE): ресурс точки входа + дерево операций (`Parser\Write\WriteDocumentParser`)
 * → план (неизменяемые узлы `Plan\Write`, `Plan\Insert`, `Plan\Update`, `Plan\Delete`). Исполнителей по плану строит
 * `Executor\Write\WriterBuilder`.
 *
 * Поток: класс ресурса, корни дерева операций (+ родитель и связь вложенного маршрута) → `WriteRootNode` над
 * `WriteJoinNode|EntityReplaceNode|WriteOperationNode`. Корни — одно место (несколько корней POST — объединение).
 * Вложенный маршрут — форма (`EntityRouteParentNode`: кто родитель, колонки связи), его id — значение, в план не попадает.
 *
 * План строится по форме дерева операций (как план SELECT — по дереву запроса с его `include`): место — ресурс и все
 * его операции этого места (корень; все дети одной связи у всех родителей места). У места:
 *  - `WriteOperationNode` с ветвями только тех операций, что встретились в месте (`InsertOperation` → `add`:
 *    `InsertResourceNode` с таблицами `InsertPlanner::getEntities()`; `UpdateOperation` → `update`:
 *    `UpdateResourceNode` с таблицами `UpdatePlanner::getEntities()`; `KeepOperation` → `keep`: `KeepEntityNode` —
 *    ресурс только ищут, поддерева таблиц у него нет; `DeleteOperation` → `remove`: `DeleteEntityNode`);
 *  - связи, которые есть хотя бы у одного элемента места, — левый глубокий список над ним: связи по владению
 *    (`getRelationships()`, в порядке объявления) — сначала `EntityReplaceNode`, если связь заменяет целиком хотя бы
 *    один элемент места, потом `WriteJoinNode`, если у связи есть хотя бы один ребёнок (его `child` — место из детей
 *    связи). Отсюда порядок выполнения: владелец, удаление неназванных, дети. Замена пустым списком — узел замены
 *    без узла связи; у POST узла замены нет. Связи по внешнему ключу пишет строка ресурса, узла нет.
 *
 * Колонки связи: место ребёнка получит от родителя `relation[1]` связи (корень под вложенным маршрутом — колонку
 * маршрута). Планировщик проверяет их один раз: для ветви `update` — колонка модели ресурса, для `keep` и `remove` —
 * колонка главной таблицы (иначе принадлежность родителю не проверить), несоответствие — LogicException; исполнители
 * проверку не повторяют. Если первичный ключ ресурса и есть колонка связи (`delivery.certificate_id`), ветви
 * `update`, `keep` и `remove` оборачиваются в `WriteLinkedKeyNode`: id операции сверяется с ключом родителя.
 *
 * Вид источника ресурса решает план: у ресурса над чужим источником (`Model\ForeignDataSource`) ветви места те же,
 * а узлы свои — `InsertResourceNode` и `UpdateResourceNode` получают вход `InsertForeignDataNode` и
 * `UpdateForeignDataNode` вместо поддерева сущностей, `keep` — `KeepForeignDataNode`, `remove` —
 * `DeleteForeignDataNode`. Здесь же, один раз, проверяется описание (несоответствие — LogicException):
 *  - источник реализует интерфейс операции ветви (`InsertForeignDataInterface`, `UpdateForeignDataInterface` — он же
 *    нужен ветви `keep`, `DeleteForeignDataInterface`): ресурс объявил возможность, которой у источника нет;
 *  - полная замена связи (`EntityReplaceNode`) не ставится над детьми из чужого источника: она удаляет неназванных
 *    одним SQL-запросом, которого у источника нет;
 *  - вложенный маршрут с чужим источником на любой стороне не планируется: родителя из маршрута проверяет SQL
 *    (`EntityRouteParentNode`), а чужому источнику аргументы маршрута уходят как есть (решение пользователя,
 *    2026-11-23) — значениями при сборке дерева (`WriterBuilder::getRoot(arguments)`).
 *
 * Порядок объявления связей — порядок записи: связь, таблицы которой ссылаются на таблицы другой связи того же
 * владельца, объявляется позже (см. `InsertPlanner`).
 *
 * Форма плана говорит, что в месте бывает; для какого элемента — данные: класс операции выбирает ветвь
 * `WriteOperationNode`, `RelationshipOperation::$replace` — выполнять ли удаление в `EntityReplaceNode`. Поэтому
 * вставка, изменение, поиск и удаление в одной связи — один узел места с четырьмя ветвями. План зависит от формы документа,
 * поэтому строится на каждый запрос, но значений (id, атрибутов) в нём нет: один план годится для любого запроса той
 * же формы. Циклов нет: дерево операций — дерево (это гарантирует парсер). Вход проверен парсером; несоответствие
 * (ресурс не `InsertableInterface` в ветви `add`, неизвестная связь) — LogicException.
 */
final class WritePlanner
{
    /**
     * @param class-string<Resource>      $resource     ресурс точки входа
     * @param list<WriteOperation>        $roots        корни дерева операций: список из `insert()`, `[update()]`
     *                                                  или `[delete()]`
     * @param class-string<Resource>|null $parent       родитель вложенного маршрута (`/certificates/{id}/domains` →
     *                                                  CertificateResource), null — маршрут не вложенный
     * @param string|null                 $relationship связь родителя к ресурсу точки входа (`domains`)
     */
    public function plan(
        string $resource,
        array $roots,
        ?string $parent = null,
        ?string $relationship = null,
    ): WriteRootNode {
        if ($parent !== null && (ModelTree::of($parent)->isForeignData() || ModelTree::of($resource)->isForeignData())) {
            throw new LogicException(
                "Route {$parent} → {$resource}: a route parent is checked by SQL, with a foreign data source the route "
                . 'arguments go to the source as is — plan without the parent',
            );
        }
        $route = $parent === null
            ? null
            : self::getRoute(parent: $parent, relationship: (string) $relationship, resource: $resource);

        return new WriteRootNode(
            input: $this->getPlace(
                resource: $resource,
                operations: $roots,
                // Корень получает колонку связи только от родителя вложенного маршрута
                link_columns: $route === null ? [] : [$route->relation[1]],
            ),
            route: $route,
        );
    }

    /**
     * Связь родителя маршрута: первичный ключ на стороне родителя, цель — ресурс точки входа.
     *
     * @param class-string<Resource> $parent
     * @param class-string<Resource> $resource
     */
    private static function getRoute(string $parent, string $relationship, string $resource): EntityRouteParentNode
    {
        $definition = $parent::getRelationship($relationship)
            ?? throw new LogicException("{$parent}: unknown relationship {$relationship}");
        /** @var array{0: string, 1: string} $relation */
        $relation = $definition->relation;
        if ($definition->resource !== $resource || $relation[0] !== $parent::getPrimaryKeyColumnName()) {
            throw new LogicException("{$parent}::{$relationship} is not an owner-side relationship to {$resource}");
        }

        return new EntityRouteParentNode(
            parent: $parent,
            relation: $relation,
        );
    }

    /**
     * @param class-string<Resource> $resource
     * @param list<WriteOperation>   $operations   операции этого места
     * @param list<string>           $link_columns колонки связи, которые место получит от родителя (`relation[1]`
     *                                             связи или вложенного маршрута); пусто — родителя нет
     * @throws LogicException если колонка связи не годится ветвям места (см. `checkLinkColumns()`)
     */
    private function getPlace(
        string $resource,
        array $operations,
        array $link_columns,
    ): WriteJoinNode|EntityReplaceNode|WriteOperationNode {
        $inserts = array_any(
            $operations,
            static fn(WriteOperation $operation): bool => $operation instanceof InsertOperation,
        );
        $updates = array_any(
            $operations,
            static fn(WriteOperation $operation): bool => $operation instanceof UpdateOperation,
        );
        $keeps = array_any(
            $operations,
            static fn(WriteOperation $operation): bool => $operation instanceof KeepOperation,
        );
        $deletes = array_any(
            $operations,
            static fn(WriteOperation $operation): bool => $operation instanceof DeleteOperation,
        );
        self::checkLinkColumns(
            resource: $resource,
            link_columns: $link_columns,
            model: $updates,
            main_table: $keeps || $deletes,
        );
        // Первичный ключ ресурса — сама колонка связи: id операции обязан совпасть с ключом родителя
        $linked_key = \in_array($resource::getPrimaryKeyColumnName(), $link_columns, true) ? $resource::getPrimaryKeyColumnName() : null;

        // Вид источника ресурса известен по описанию: узлы ветвей выбираются здесь, а не в сборщике дерева
        $is_foreign = ModelTree::of($resource)->isForeignData();
        $node = new WriteOperationNode(
            resource: $resource,
            add: $inserts
                ? new InsertResourceNode(
                    resource: self::getInsertable(resource: $resource),
                    input: $is_foreign
                        ? new InsertForeignDataNode(
                            source: self::getForeignDataSource(resource: $resource, capability: InsertForeignDataInterface::class),
                        )
                        : new InsertPlanner()->getEntities(resource: $resource),
                )
                : null,
            update: $updates
                ? self::getGuardedBranch(
                    linked_key: $linked_key,
                    branch: new UpdateResourceNode(
                        resource: $resource,
                        input: $is_foreign
                            ? new UpdateForeignDataNode(
                                source: self::getForeignDataSource(resource: $resource, capability: UpdateForeignDataInterface::class),
                            )
                            : new UpdatePlanner()->getEntities(resource: $resource),
                    ),
                )
                : null,
            keep: $keeps
                ? self::getGuardedBranch(
                    linked_key: $linked_key,
                    branch: $is_foreign
                        ? new KeepForeignDataNode(
                            resource: $resource,
                            source: self::getForeignDataSource(resource: $resource, capability: UpdateForeignDataInterface::class),
                        )
                        : new KeepEntityNode(
                            resource: $resource,
                        ),
                )
                : null,
            remove: $deletes
                ? self::getGuardedBranch(
                    linked_key: $linked_key,
                    branch: $is_foreign
                        ? new DeleteForeignDataNode(
                            resource: $resource,
                            source: self::getForeignDataSource(resource: $resource, capability: DeleteForeignDataInterface::class),
                        )
                        : new DeleteEntityNode(
                            resource: $resource,
                        ),
                )
                : null,
        );

        foreach (array_keys($resource::getRelationships()) as $name) {
            $name = (string) $name;
            // Только связи по владению: связь по внешнему ключу лежит в `references`, её пишет строка ресурса
            $present = array_values(array_filter(
                array_map(
                    static fn(WriteOperation $operation): ?RelationshipOperation => $operation->getRelationship(
                        name: $name,
                    ),
                    $operations,
                ),
                static fn(?RelationshipOperation $relationship): bool => $relationship !== null,
            ));
            if ($present === []) {
                continue;
            }
            $relationship = $resource::getRelationship($name)
                ?? throw new LogicException("{$resource}: unknown relationship {$name}");
            /** @var array{0: string, 1: string} $relation */
            $relation = $relationship->relation;
            $children = array_merge(...array_map(
                static fn(RelationshipOperation $present_relationship): array => $present_relationship->children,
                $present,
            ));
            $replaces = array_any(
                $present,
                static fn(RelationshipOperation $present_relationship): bool => $present_relationship->replace,
            );
            if ($replaces && ModelTree::of($relationship->resource)->isForeignData()) {
                throw new LogicException(
                    "{$resource}::{$name}: full replacement of children kept in a foreign data source is not supported",
                );
            }
            if ($replaces) {
                $node = new EntityReplaceNode(
                    name: $name,
                    relation: $relation,
                    child_resource: $relationship->resource,
                    input: $node,
                );
            }
            if ($children !== []) {
                $node = new WriteJoinNode(
                    name: $name,
                    relation: $relation,
                    parent: $node,
                    child: $this->getPlace(
                        resource: $relationship->resource,
                        operations: $children,
                        link_columns: [$relation[1]],
                    ),
                );
            }
        }

        return $node;
    }

    /**
     * Ветвь места как есть или под сторожем `WriteLinkedKeyNode`, если первичный ключ ресурса — колонка связи.
     *
     * @template TBranch of UpdateResourceNode|KeepEntityNode|KeepForeignDataNode|DeleteEntityNode|DeleteForeignDataNode
     * @param TBranch $branch
     * @return TBranch|WriteLinkedKeyNode
     */
    private static function getGuardedBranch(
        ?string $linked_key,
        UpdateResourceNode|KeepEntityNode|KeepForeignDataNode|DeleteEntityNode|DeleteForeignDataNode $branch,
    ): UpdateResourceNode|KeepEntityNode|KeepForeignDataNode|DeleteEntityNode|DeleteForeignDataNode|WriteLinkedKeyNode {
        if ($linked_key === null) {
            return $branch;
        }

        return new WriteLinkedKeyNode(
            key: $linked_key,
            input: $branch,
        );
    }

    /**
     * Колонки связи места годятся его ветвям: по ним исполнители проверяют, что ресурс принадлежит родителю.
     * Имена колонок известны по связи, поэтому проверка одна, при построении плана, — исполнители её не повторяют.
     *
     * @param class-string<Resource> $resource
     * @param list<string>           $link_columns
     * @param bool                   $model      есть ветвь `update`: колонка должна быть колонкой модели ресурса
     *                                           (любой её таблицы — изменение идёт по всем таблицам)
     * @param bool                   $main_table есть ветвь `keep` или `remove`: колонка должна лежать в главной
     *                                           таблице — поиск и удаление работают только с ней
     * @throws LogicException
     */
    private static function checkLinkColumns(string $resource, array $link_columns, bool $model, bool $main_table): void
    {
        if ($link_columns === [] || (!$model && !$main_table)) {
            return;
        }
        $tree = ModelTree::of($resource);
        $main = $tree->getRootDataSource() ?? throw new LogicException("{$resource}: invalid model");
        foreach ($link_columns as $column) {
            if ($model && !$tree->hasColumn($column)) {
                throw new LogicException("{$resource}: link column {$column} is not a model column");
            }
            if ($main_table && !isset($main::COLUMNS[$column])) {
                throw new LogicException("{$main}: link column {$column} is not in the main table of {$resource}");
            }
        }
    }

    /**
     * Класс чужого источника ресурса с проверкой, что источник поддерживает операцию.
     *
     * @template TCapability of object
     * @param class-string<Resource>    $resource
     * @param class-string<TCapability> $capability интерфейс операции (`InsertForeignDataInterface`, …)
     * @return class-string<ForeignDataSource&TCapability>
     * @throws LogicException если ресурс не над чужим источником или источник не реализует интерфейс операции
     */
    private static function getForeignDataSource(string $resource, string $capability): string
    {
        $source = ModelTree::of($resource)->getRootDataSource();
        if ($source === null || !is_subclass_of($source, ForeignDataSource::class)) {
            throw new LogicException("{$resource} is not kept in a foreign data source");
        }
        if (!is_subclass_of($source, $capability)) {
            throw new LogicException("{$resource}: source {$source} does not implement {$capability}");
        }

        return $source;
    }

    /**
     * @param class-string<Resource> $resource
     * @return class-string<Resource&InsertableInterface>
     */
    private static function getInsertable(string $resource): string
    {
        if (!is_a($resource, InsertableInterface::class, true)) {
            throw new LogicException("{$resource} is not InsertableInterface");
        }

        return $resource;
    }
}
