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
 * Планировщик вставки уровня таблиц: модель ресурса → поддерево таблиц вставки (неизменяемые узлы `Plan\Insert`).
 * Уровень ресурсов (связи, развилка по операциям, корень) общий для записи и строится `Plan\Write\WritePlanner`: он
 * ставит результат `tables()` под `InsertResourceNode` в ветви `add` места. Исполнителей по плану строит
 * `Executor\Write\WriterBuilder`.
 *
 * Поток: класс ресурса → `InsertTableJoinNode|InsertTableNode`.
 *
 * `tables()`: сущность → `InsertTableNode` со своими колонками модели (`ModelTree::columnTable()`), соединение →
 * `InsertTableJoinNode(relation: [колонка родителя, колонка ребёнка], parent, child)`. Родитель — сторона, от которой
 * зависит другая, определяется по метаданным сущностей: для `relation` `[left_column, right_column]` модели родитель
 * левая сторона, если `left_column` — первичный ключ левой сущности или `right_column` ссылается на неё
 * (`references`); правая, если наоборот; иначе `LogicException`. Сущность колонки `relation` ищется по имени в своём
 * поддереве (первая таблица слева, где она есть), как в `ModelTree`. Правая сторона `LeftJoin` необязательная: она
 * оборачивается в `InsertOptionalTableNode` и родителем может оказаться так же, как и ребёнком.
 *
 * Порядок между ресурсами (владелец раньше детей, связи владельца в порядке объявления) задаёт `WritePlanner`.
 * Ссылку между таблицами разных связей одного владельца (`certificate_delivery_meta.certificate_id` →
 * `certificate_details`) сущности не описывают: колонка-первичный ключ не несёт `references`, поэтому связь, таблицы
 * которой ссылаются на таблицы другой связи, объявляется в `relationships()` позже.
 *
 * Ресурс не `Insertable` — `LogicException`.
 */
final class InsertPlanner
{
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
