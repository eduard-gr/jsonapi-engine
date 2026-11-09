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
 * Планировщик вставки: корневой ресурс точки входа + дерево документа (`Parser\Insert\DocumentParser::parse()`) → план
 * (неизменяемые узлы `Plan\Insert`). Исполнителей по плану строит `Executor\Insert\WriterBuilder`.
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
 *  - связи владельца — левый глубокий список `InsertJoinNode(name, relation, parent: владелец…, child: дети)`
 *    в порядке объявления в `relationships()` ресурса. Порядок объявления — порядок вставки: связь, таблицы которой
 *    ссылаются на таблицы другой связи того же владельца, объявляется позже (`delivery` после `detail`:
 *    `certificate_delivery_meta.certificate_id` ссылается на `certificate_details`). Сущности такую ссылку не описывают
 *    (колонка-первичный ключ не несёт `references`), поэтому планировщик её не видит и не проверяет;
 *  - в дерево идут только связи, у которых первичный ключ на стороне владельца (`relation[0]` — первичный ключ
 *    владельца: `OneToMany`, обратная `OneToOne`). Связь по внешнему ключу ресурса (`provider`) пишет сама строка
 *    ресурса (`InsertResourceWriter`), узла у неё нет. Связь, у которой ни то ни другое, — `LogicException`.
 *
 * Уровень таблиц (`tables()`): сущность → `InsertTableNode` со своими колонками модели (`ModelTree::columnTable()`),
 * соединение → `InsertTableJoinNode(relation: [колонка родителя, колонка ребёнка], parent, child)`.
 * Родитель — сторона, от которой зависит другая, определяется по метаданным сущностей: для `relation`
 * `[left_column, right_column]` модели родитель левая сторона, если `left_column` — первичный ключ левой сущности или
 * `right_column` ссылается на неё (`references`); правая, если наоборот; иначе `LogicException`. Сущность колонки
 * `relation` ищется по имени в своём поддереве (первая таблица слева, где она есть), как в `ModelTree`. Правая сторона
 * `LeftJoin` необязательная: она оборачивается в `InsertOptionalTableNode` и родителем может оказаться так же, как
 * и ребёнком.
 *
 * Вход проверен парсером; несоответствие (ресурс не `Insertable`, неизвестная связь) — `LogicException`.
 *
 * Параметр маршрута вложенного ресурса — значение, а не форма плана: `routeLink()` превращает его в колонки связи,
 * которые получает `WriterBuilder::root()`.
 */
final class InsertPlanner
{
    /**
     * @param class-string<Resource> $resource корневой ресурс точки входа
     * @param list<array>            $data     ресурсы `data` документа из `DocumentParser::parse()`
     */
    public function plan(string $resource, array $data): InsertJoinNode|InsertResourceNode
    {
        return $this->resource(resource: $resource, items: $data);
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
        $node = new InsertResourceNode(
            resource: self::insertable($resource),
            input: $this->tables(resource: $resource));

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
                parent: $node,
                child: $this->resource(resource: $target, items: $children),
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

        $left_subtree = $this->table(model: $model->left, columns: $columns, leaf: $leaf);
        $right_subtree = $this->table(model: $model->right, columns: $columns, leaf: $leaf);
        // Правая сторона LeftJoin необязательна: её может не быть в строке, как в SELECT. Она может оказаться и
        // родителем, и ребёнком — обёртка остаётся на ней в любом случае
        if ($model instanceof LeftJoin) {
            $right_subtree = new InsertOptionalTableNode(input: $right_subtree);
        }

        /** @var array{0: string, 1: string} $relation */
        $relation = $model->relation;
        [$left_column, $right_column] = $relation;
        $left_entity = self::owner(model: $model->left, column: $left_column);
        $right_entity = self::owner(model: $model->right, column: $right_column);
        // Родитель — сторона, от которой зависит другая: её колонка — первичный ключ, или на неё ссылается колонка
        // другой стороны. Родитель вставляется первым, его ключ уходит ребёнку колонкой связи
        $left_is_parent = $left_entity::primaryColumn() === $left_column
            || ($right_entity::COLUMNS[$right_column]['references'] ?? null) === $left_entity;
        $right_is_parent = $right_entity::primaryColumn() === $right_column
            || ($left_entity::COLUMNS[$left_column]['references'] ?? null) === $right_entity;

        [$parent_column, $child_column, $parent_subtree, $child_subtree] = match (true) {
            $left_is_parent => [$left_column, $right_column, $left_subtree, $right_subtree],
            $right_is_parent => [$right_column, $left_column, $right_subtree, $left_subtree],
            default => throw new LogicException(
                "Can not order join on [{$left_column}, {$right_column}]: no primary key or reference between the tables",
            ),
        };

        return new InsertTableJoinNode(
            relation: [$parent_column, $child_column],
            parent: $parent_subtree,
            child: $child_subtree,
        );
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
