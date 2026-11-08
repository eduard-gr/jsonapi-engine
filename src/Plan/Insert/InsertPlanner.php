<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Insert;

use Ggs\JsonApiEngine\Model\Entity;
use Ggs\JsonApiEngine\Model\Join;
use Ggs\JsonApiEngine\Model\LeftJoin;
use Ggs\JsonApiEngine\Model\ModelTree;
use Ggs\JsonApiEngine\Resource;
use Ggs\JsonApiEngine\Resource\Insertable;
use LogicException;

/**
 * Планировщик вставки: корневой ресурс точки входа + дерево документа (`Parser\DocumentParser::parse()`) → план
 * (неизменяемые узлы `Plan\Insert`). Исполнителей по плану строит `Executor\WriterBuilder`.
 *
 * Поток: класс корневого ресурса, `list<ресурс>` корней → `InsertJoinNode|InsertResourceNode`.
 *
 * План строится по форме документа (как план SELECT — по дереву запроса с его `include`): в него попадают только связи,
 * которые встретились у ресурсов этого места хотя бы раз с непустыми данными (объединение по всем ресурсам места:
 * по всем корням, по всем детям связи). Связь, которой у части ресурсов нет, у них просто не вызывается. Циклов нет:
 * дерево документа — дерево (это гарантирует парсер). План зависит от документа, поэтому строится на каждый запрос.
 *
 * Уровень ресурсов:
 *  - ресурс → `InsertResourceNode(resource, input)`, `input` — поддерево таблиц его модели (`tables()`);
 *  - связи владельца — левый глубокий список `InsertJoinNode(name, relation, first: владелец…, then: дети)`
 *    в порядке объявления в `relationships()` ресурса. Порядок объявления — порядок вставки: связь, таблицы которой
 *    ссылаются на таблицы другой связи того же владельца, объявляется позже (`delivery` после `detail`:
 *    `certificate_delivery_meta.certificate_id` ссылается на `certificate_details`). Сущности такую ссылку не описывают
 *    (колонка-первичный ключ не несёт `references`), поэтому планировщик её не видит и не проверяет;
 *  - в дерево идут только связи, у которых первичный ключ на стороне владельца (`relation[0]` — первичный ключ
 *    владельца: `OneToMany`, обратная `OneToOne`). Связь по внешнему ключу ресурса (`provider`) пишет сама строка
 *    ресурса (`InsertResourceWriter`), узла у неё нет. Связь, у которой ни то ни другое, — `LogicException`.
 *
 * Уровень таблиц (`tables()`): сущность → `InsertTableNode` со своими колонками модели (`ModelTree::columnTable()`),
 * соединение → `InsertTableJoinNode`. Порядок сторон — по метаданным сущностей: для `relation` `[l, r]` левая первой,
 * если `l` — первичный ключ левой сущности или `r` ссылается на неё (`references`); правая первой, если `r` — первичный
 * ключ правой или `l` ссылается на неё; иначе `LogicException`. Сущность колонки `relation` ищется по имени в своём
 * поддереве (первая таблица слева, где она есть), как в `ModelTree`. Правая сторона `LeftJoin` необязательная.
 *
 * Вход проверен парсером; несоответствие (ресурс не `Insertable`, неизвестная связь) — `LogicException`.
 *
 * Параметр маршрута вложенного ресурса — значение, а не форма плана: `routeLink()` превращает его в колонки связи,
 * которые получает `WriterBuilder::document()`.
 */
final class InsertPlanner
{
    /**
     * @param class-string<Resource> $resource корневой ресурс точки входа
     * @param list<array>            $roots    корни из `DocumentParser::parse()`
     */
    public function plan(string $resource, array $roots): InsertJoinNode|InsertResourceNode
    {
        return $this->resource(resource: $resource, items: $roots);
    }

    /**
     * Колонки связи для вложенного маршрута: `POST /certificates/1/domains` → `routeLink(CertificateResource::class,
     * 'domains', 1)` → `['certificate_id' => 1]` (`relation[1]` связи родителя). Связь должна быть с первичным ключом
     * на стороне родителя, как в дереве плана.
     *
     * @param class-string<Resource> $parent ресурс родителя из маршрута
     * @return array<string, int>
     */
    public function routeLink(string $parent, string $relationship, int $id): array
    {
        return [$this->ownerRelation(resource: $parent, name: $relationship)[1] => $id];
    }

    /**
     * Ресурс места и его связи по ресурсам этого места.
     *
     * @param class-string<Resource> $resource
     * @param list<array>            $items    ресурсы этого места (объекты из дерева документа)
     */
    private function resource(string $resource, array $items): InsertJoinNode|InsertResourceNode
    {
        $node = new InsertResourceNode(resource: self::insertable($resource), input: $this->tables(resource: $resource));

        foreach (array_keys($resource::relationships()) as $name) {
            $name = (string) $name;
            if ($resource::foreignRelationship($name) !== null) {
                continue;
            }
            $children = self::children(items: $items, name: $name);
            if ($children === []) {
                continue;
            }
            $relation = $this->ownerRelation(resource: $resource, name: $name);
            /** @var class-string<Resource> $target */
            $target = $resource::relationship($name)?->resource;
            $node = new InsertJoinNode(
                name: $name,
                relation: $relation,
                first: $node,
                then: $this->resource(resource: $target, items: $children),
            );
        }

        return $node;
    }

    /**
     * Поддерево таблиц модели ресурса.
     *
     * @param class-string<Resource> $resource
     */
    public function tables(string $resource): InsertTableJoinNode|InsertTableNode
    {
        self::insertable($resource);
        $tree = ModelTree::of($resource);
        $columns = [];
        foreach ($tree->columns() as $column) {
            $columns[(int) $tree->columnTable($column)][] = $column;
        }
        $leaf = 0;

        // model() объявляют наследники Resource, базовый класс его не знает (как в ModelTree::build())
        /** @var callable(): (Join|class-string<Entity>) $model */
        $model = [$resource, 'model'];

        return $this->table(model: $model(), columns: $columns, leaf: $leaf);
    }

    /**
     * @param Join|class-string<Entity> $model
     * @param array<int, list<string>>  $columns номер таблицы → её колонки модели
     * @param int                       $leaf    номер следующей таблицы слева направо
     */
    private function table(Join|string $model, array $columns, int &$leaf): InsertTableJoinNode|InsertTableNode
    {
        if (is_string($model)) {
            $number = $leaf++;

            return new InsertTableNode(entity: $model, columns: $columns[$number] ?? []);
        }

        $left = $this->table(model: $model->left, columns: $columns, leaf: $leaf);
        $right = $this->table(model: $model->right, columns: $columns, leaf: $leaf);

        /** @var array{0: string, 1: string} $relation */
        $relation = $model->relation;
        [$l, $r] = $relation;
        $leftEntity = self::owner(model: $model->left, column: $l);
        $rightEntity = self::owner(model: $model->right, column: $r);
        $optional = $model instanceof LeftJoin;

        if ($leftEntity::primaryColumn() === $l || ($rightEntity::COLUMNS[$r]['references'] ?? null) === $leftEntity) {
            return new InsertTableJoinNode(relation: [$l, $r], first: $left, then: $right, optional: $optional ? 'then' : null);
        }
        if ($rightEntity::primaryColumn() === $r || ($leftEntity::COLUMNS[$l]['references'] ?? null) === $rightEntity) {
            return new InsertTableJoinNode(relation: [$r, $l], first: $right, then: $left, optional: $optional ? 'first' : null);
        }

        throw new LogicException("Can not order join on [{$l}, {$r}]: no primary key or reference between the tables");
    }

    /**
     * `relation` связи, у которой первичный ключ на стороне владельца (`relation[0]` — первичный ключ владельца).
     *
     * @param class-string<Resource> $resource
     * @return array{0: string, 1: string}
     */
    private function ownerRelation(string $resource, string $name): array
    {
        $relationship = $resource::relationship($name)
            ?? throw new LogicException("{$resource}: unknown relationship {$name}");
        /** @var array{0: string, 1: string} $relation */
        $relation = $relationship->relation;
        if ($resource::foreignRelationship($name) !== null || $relation[0] !== $resource::primaryColumn()) {
            throw new LogicException(
                "{$resource}: relationship {$name} has no primary key on the owner side, it can not get new children",
            );
        }

        return $relation;
    }

    /**
     * Новые дети связи у всех ресурсов места: одиночный ресурс или список, `null` и пустой список пропускаются.
     *
     * @param list<array> $items
     * @return list<array>
     */
    private static function children(array $items, string $name): array
    {
        $children = [];
        foreach ($items as $item) {
            /** @var array|null $data */
            $data = $item['relationships'][$name]['data'] ?? null;
            if ($data === null || $data === []) {
                continue;
            }
            /** @var list<array> $list */
            $list = array_is_list($data) ? $data : [$data];
            array_push($children, ...$list);
        }

        return $children;
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

    /**
     * Сущность колонки в поддереве: первая таблица слева, где колонка есть.
     *
     * @param Join|class-string<Entity> $model
     * @return class-string<Entity>
     */
    private static function owner(Join|string $model, string $column): string
    {
        foreach (self::leaves($model) as $entity) {
            if (isset($entity::COLUMNS[$column])) {
                return $entity;
            }
        }

        throw new LogicException("Column {$column} is not in the join subtree");
    }

    /**
     * Таблицы модели слева направо.
     *
     * @param Join|class-string<Entity> $model
     * @return list<class-string<Entity>>
     */
    private static function leaves(Join|string $model): array
    {
        /** @var list<class-string<Entity>> */
        return is_string($model) ? [$model] : [...self::leaves($model->left), ...self::leaves($model->right)];
    }
}
