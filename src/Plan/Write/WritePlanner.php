<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Write;

use Ggs\JsonApiEngine\Parser\Write\DeleteOperation;
use Ggs\JsonApiEngine\Parser\Write\InsertOperation;
use Ggs\JsonApiEngine\Parser\Write\KeepOperation;
use Ggs\JsonApiEngine\Parser\Write\RelationshipOperation;
use Ggs\JsonApiEngine\Parser\Write\UpdateOperation;
use Ggs\JsonApiEngine\Parser\Write\WriteOperation;
use Ggs\JsonApiEngine\Plan\Delete\DeleteResourceNode;
use Ggs\JsonApiEngine\Plan\Insert\InsertPlanner;
use Ggs\JsonApiEngine\Plan\Insert\InsertResourceNode;
use Ggs\JsonApiEngine\Plan\Update\UpdatePlanner;
use Ggs\JsonApiEngine\Plan\Update\UpdateResourceNode;
use Ggs\JsonApiEngine\Resource;
use Ggs\JsonApiEngine\Resource\Insertable;
use LogicException;

/**
 * Планировщик записи (POST, PATCH, DELETE): ресурс точки входа + дерево операций (`Parser\Write\WriteDocumentParser`)
 * → план (неизменяемые узлы `Plan\Write`, `Plan\Insert`, `Plan\Update`, `Plan\Delete`). Исполнителей по плану строит
 * `Executor\Write\WriterBuilder`.
 *
 * Поток: класс ресурса, корни дерева операций (+ родитель и связь вложенного маршрута) → `WriteRootNode` над
 * `WriteJoinNode|WriteReplaceNode|WriteOperationNode`. Корни — одно место (несколько корней POST — объединение).
 * Вложенный маршрут — форма (`RouteParentNode`: кто родитель, колонки связи), его id — значение, в план не попадает.
 *
 * План строится по форме дерева операций (как план SELECT — по дереву запроса с его `include`): место — ресурс и все
 * его операции этого места (корень; все дети одной связи у всех родителей места). У места:
 *  - `WriteOperationNode` с ветвями только тех операций, что встретились в месте (`InsertOperation` → `add`:
 *    `InsertResourceNode` с таблицами `InsertPlanner::tables()`; `UpdateOperation`/`KeepOperation` → `update`:
 *    `UpdateResourceNode` с таблицами `UpdatePlanner::tables()`; `DeleteOperation` → `remove`: `DeleteResourceNode`);
 *  - связи, которые есть хотя бы у одного элемента места, — левый глубокий список над ним: связи по владению
 *    (`relationships()`, в порядке объявления) — сначала `WriteReplaceNode`, если связь заменяет целиком хотя бы
 *    один элемент места, потом `WriteJoinNode`, если у связи есть хотя бы один ребёнок (его `child` — место из детей
 *    связи). Отсюда порядок выполнения: владелец, удаление неназванных, дети. Замена пустым списком — узел замены
 *    без узла связи; у POST узла замены нет. Связи по внешнему ключу пишет строка ресурса, узла нет.
 *
 * Порядок объявления связей — порядок записи: связь, таблицы которой ссылаются на таблицы другой связи того же
 * владельца, объявляется позже (см. `InsertPlanner`).
 *
 * Форма плана говорит, что в месте бывает; для какого элемента — данные: класс операции выбирает ветвь
 * `WriteOperationNode`, `RelationshipOperation::$replace` — выполнять ли удаление в `WriteReplaceNode`. Поэтому
 * вставка, удаление и изменение в одной связи — один узел места с тремя ветвями. План зависит от формы документа,
 * поэтому строится на каждый запрос, но значений (id, атрибутов) в нём нет: один план годится для любого запроса той
 * же формы. Циклов нет: дерево операций — дерево (это гарантирует парсер). Вход проверен парсером; несоответствие
 * (ресурс не `Insertable` в ветви `add`, неизвестная связь) — LogicException.
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
        return new WriteRootNode(
            input: $this->place(resource: $resource, operations: $roots),
            route: $parent === null
                ? null
                : self::route(parent: $parent, relationship: (string) $relationship, resource: $resource),
        );
    }

    /**
     * Связь родителя маршрута: первичный ключ на стороне родителя, цель — ресурс точки входа.
     *
     * @param class-string<Resource> $parent
     * @param class-string<Resource> $resource
     */
    private static function route(string $parent, string $relationship, string $resource): RouteParentNode
    {
        $definition = $parent::relationship($relationship)
            ?? throw new LogicException("{$parent}: unknown relationship {$relationship}");
        /** @var array{0: string, 1: string} $relation */
        $relation = $definition->relation;
        if ($definition->resource !== $resource || $relation[0] !== $parent::primaryColumn()) {
            throw new LogicException("{$parent}::{$relationship} is not an owner-side relationship to {$resource}");
        }

        return new RouteParentNode(
            parent: $parent,
            relation: $relation,
        );
    }

    /**
     * @param class-string<Resource> $resource
     * @param list<WriteOperation>   $operations операции этого места
     */
    private function place(string $resource, array $operations): WriteJoinNode|WriteReplaceNode|WriteOperationNode
    {
        $inserts = array_any(
            $operations,
            static fn(WriteOperation $operation): bool => $operation instanceof InsertOperation,
        );
        $updates = array_any(
            $operations,
            static fn(WriteOperation $operation): bool => $operation instanceof UpdateOperation
                || $operation instanceof KeepOperation,
        );
        $deletes = array_any(
            $operations,
            static fn(WriteOperation $operation): bool => $operation instanceof DeleteOperation,
        );
        $node = new WriteOperationNode(
            resource: $resource,
            add: $inserts
                ? new InsertResourceNode(
                    resource: self::insertable(resource: $resource),
                    input: new InsertPlanner()->tables(resource: $resource),
                )
                : null,
            update: $updates
                ? new UpdateResourceNode(
                    resource: $resource,
                    input: new UpdatePlanner()->tables(resource: $resource),
                )
                : null,
            remove: $deletes
                ? new DeleteResourceNode(
                    resource: $resource,
                )
                : null,
        );

        foreach (array_keys($resource::relationships()) as $name) {
            $name = (string) $name;
            // Только связи по владению: связь по внешнему ключу лежит в `references`, её пишет строка ресурса
            $present = array_values(array_filter(
                array_map(
                    static fn(WriteOperation $operation): ?RelationshipOperation => $operation->relationship(
                        name: $name,
                    ),
                    $operations,
                ),
                static fn(?RelationshipOperation $relationship): bool => $relationship !== null,
            ));
            if ($present === []) {
                continue;
            }
            $relationship = $resource::relationship($name)
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
            if ($replaces) {
                $node = new WriteReplaceNode(
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
                    child: $this->place(resource: $relationship->resource, operations: $children),
                );
            }
        }

        return $node;
    }

    /**
     * @param class-string<Resource> $resource
     * @return class-string<Resource&Insertable>
     */
    private static function insertable(string $resource): string
    {
        if (!is_a($resource, Insertable::class, true)) {
            throw new LogicException("{$resource} is not Insertable");
        }

        return $resource;
    }
}
