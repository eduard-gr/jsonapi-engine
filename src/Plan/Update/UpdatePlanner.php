<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Update;

use Ggs\JsonApiEngine\Model\Entity;
use Ggs\JsonApiEngine\Model\Join;
use Ggs\JsonApiEngine\Model\LeftJoin;
use Ggs\JsonApiEngine\Model\ModelTree;
use Ggs\JsonApiEngine\Plan\Insert\InsertTableNode;
use Ggs\JsonApiEngine\Resource;
use LogicException;

/**
 * Планировщик изменения уровня таблиц: модель ресурса → поддерево таблиц изменения (неизменяемые узлы `Plan\Update`).
 * Пара к `InsertPlanner`: уровень ресурсов общий и строится `Plan\Write\WritePlanner`, он ставит результат
 * `tables()` под `UpdateResourceNode` в ветви `update` места. Исполнителей строит `Executor\Write\WriterBuilder`.
 *
 * Поток: класс ресурса → `UpdateTableJoinNode|UpdateTableNode`.
 *
 * `tables()`: сначала сторона, ключ которой уже известен (левая, с главной таблицей; её ключ — id ресурса), потом
 * правая — по ключу, который вернула левая, независимо от направления внешнего ключа (у вставки наоборот: первым
 * родитель по внешнему ключу). Соединение → `UpdateTableJoinNode(relation, main: левая, joined: правая)`; правая
 * сторона `LeftJoin` → `UpdateOptionalTableNode(UpdateTableNode, InsertTableNode)`: строки может не быть, тогда она
 * вставляется; таблица → `UpdateTableNode(key, columns, returning)`: `key` главной — первичный ключ ресурса, `key`
 * правой — `relation[1]`; `returning` — колонки `relation[0]` соединений, которые в ней лежат.
 *
 * Ограничения: `key` правой стороны — в её крайней левой таблице; необязательная правая сторона — одна таблица
 * (иначе LogicException).
 */
final class UpdatePlanner
{
    /**
     * Поддерево таблиц изменения по `model()` ресурса.
     *
     * @param class-string<Resource> $resource
     */
    public function tables(string $resource): UpdateTableJoinNode|UpdateTableNode
    {
        $tree = ModelTree::of($resource);
        $columns = [];
        foreach ($tree->columns() as $column) {
            $columns[(int) $tree->columnTable($column)][] = $column;
        }
        // model() объявляют наследники Resource, базовый класс его не знает
        /**
         * @var Join|class-string<Entity> $model
         * @psalm-suppress UndefinedMethod
         */
        $model = $resource::model();
        $returning = [];
        self::returning(model: $model, tree: $tree, returning: $returning);
        $leaf = 0;

        return self::table(
            model: $model,
            key: $resource::primaryColumn(),
            columns: $columns,
            returning: $returning,
            leaf: $leaf,
        );
    }

    /**
     * @param Join|class-string<Entity> $model
     * @param array<int, list<string>>  $columns   номер таблицы → её колонки модели
     * @param array<int, list<string>>  $returning номер таблицы → колонки для правых сторон
     * @param int                       $leaf      номер следующей таблицы слева направо
     */
    private static function table(
        Join|string $model,
        string $key,
        array $columns,
        array $returning,
        int &$leaf,
    ): UpdateTableJoinNode|UpdateTableNode {
        if (is_string($model)) {
            if (!isset($model::COLUMNS[$key])) {
                throw new LogicException("{$model}: key {$key} must be a column of the leftmost table of its side");
            }
            $number = $leaf++;

            return new UpdateTableNode(
                entity: $model,
                columns: $columns[$number] ?? [],
                key: $key,
                returning: $returning[$number] ?? [],
            );
        }

        /** @var array{0: string, 1: string} $relation */
        $relation = $model->relation;
        $main = self::table(model: $model->left, key: $key, columns: $columns, returning: $returning, leaf: $leaf);
        $joined = self::table(
            model: $model->right,
            key: $relation[1],
            columns: $columns,
            returning: $returning,
            leaf: $leaf,
        );
        if ($model instanceof LeftJoin) {
            if (!$joined instanceof UpdateTableNode) {
                throw new LogicException('an optional side of several tables is not supported');
            }
            $joined = new UpdateOptionalTableNode(
                update: $joined,
                insert: new InsertTableNode(
                    entity: $joined->entity,
                    columns: $joined->columns,
                ),
            );
        }

        return new UpdateTableJoinNode(
            relation: $relation,
            main: $main,
            joined: $joined,
        );
    }

    /**
     * Колонка `relation[0]` каждого соединения → в `returning` таблицы, где она лежит.
     *
     * @param Join|class-string<Entity> $model
     * @param array<int, list<string>>  $returning
     */
    private static function returning(Join|string $model, ModelTree $tree, array &$returning): void
    {
        if (is_string($model)) {
            return;
        }
        $column = (string) $model->relation[0];
        $table = $tree->columnTable($column) ?? throw new LogicException("{$column} is not a model column");
        $returning[$table][] = $column;
        self::returning(model: $model->left, tree: $tree, returning: $returning);
        self::returning(model: $model->right, tree: $tree, returning: $returning);
    }
}
