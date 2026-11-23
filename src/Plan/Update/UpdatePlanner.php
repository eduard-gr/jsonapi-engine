<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Update;

use Ggs\JsonApiEngine\Model\DatabaseEntitySource;
use Ggs\JsonApiEngine\Model\Join;
use Ggs\JsonApiEngine\Model\LeftJoin;
use Ggs\JsonApiEngine\Model\ModelTree;
use Ggs\JsonApiEngine\Plan\Insert\InsertEntityNode;
use Ggs\JsonApiEngine\Resource;
use LogicException;

/**
 * Планировщик изменения уровня таблиц: модель ресурса → поддерево таблиц изменения (неизменяемые узлы `Plan\Update`).
 * Пара к `InsertPlanner`: уровень ресурсов общий и строится `Plan\Write\WritePlanner`, он ставит результат
 * `getEntities()` под `UpdateResourceNode` в ветви `update` места. Исполнителей строит `Executor\Write\WriterBuilder`.
 *
 * Поток: класс ресурса → `UpdateEntityJoinNode|UpdateEntityNode`.
 *
 * `getEntities()`: сначала сторона, ключ которой уже известен (левая, с главной таблицей; её ключ — id ресурса), потом
 * правая — по ключу, который вернула левая, независимо от направления внешнего ключа (у вставки наоборот: первым
 * родитель по внешнему ключу). Соединение → `UpdateEntityJoinNode(relation, main: левая, joined: правая)`; правая
 * сторона `LeftJoin` → `UpdateOptionalEntityNode(UpdateEntityNode, InsertEntityNode)`: строки может не быть, тогда она
 * вставляется; таблица → `UpdateEntityNode(key, columns, returning)`: `key` главной — первичный ключ ресурса, `key`
 * правой — `relation[1]`; `returning` — колонки `relation[0]` соединений, которые в ней лежат.
 *
 * Ограничения: `key` правой стороны — в её крайней левой таблице; необязательная правая сторона — одна таблица
 * (иначе LogicException).
 */
final class UpdatePlanner
{
    /**
     * Поддерево таблиц изменения по `getModel()` ресурса.
     *
     * @param class-string<Resource> $resource
     */
    public function getEntities(string $resource): UpdateEntityJoinNode|UpdateEntityNode
    {
        $tree = ModelTree::of($resource);
        $columns = [];
        foreach ($tree->getColumns() as $column) {
            $columns[(int) $tree->getColumnTable($column)][] = $column;
        }
        // getModel() объявляют наследники Resource, базовый класс его не знает
        /**
         * @var Join|class-string<DatabaseEntitySource> $model
         * @psalm-suppress UndefinedMethod
         */
        $model = $resource::getModel();
        $returning = [];
        self::returning(model: $model, tree: $tree, returning: $returning);
        $leaf = 0;

        return self::getEntityNode(
            model: $model,
            key: $resource::getPrimaryKeyColumnName(),
            columns: $columns,
            returning: $returning,
            leaf: $leaf,
        );
    }

    /**
     * @param Join|class-string<DatabaseEntitySource> $model
     * @param array<int, list<string>>  $columns   номер таблицы → её колонки модели
     * @param array<int, list<string>>  $returning номер таблицы → колонки для правых сторон
     * @param int                       $leaf      номер следующей таблицы слева направо
     */
    private static function getEntityNode(
        Join|string $model,
        string $key,
        array $columns,
        array $returning,
        int &$leaf,
    ): UpdateEntityJoinNode|UpdateEntityNode {
        if (is_string($model)) {
            if (!isset($model::COLUMNS[$key])) {
                throw new LogicException("{$model}: key {$key} must be a column of the leftmost table of its side");
            }
            $number = $leaf++;

            return new UpdateEntityNode(
                entity: $model,
                columns: $columns[$number] ?? [],
                key: $key,
                returning: $returning[$number] ?? [],
            );
        }

        /** @var array{0: string, 1: string} $relation */
        $relation = $model->relation;
        $main = self::getEntityNode(model: $model->left, key: $key, columns: $columns, returning: $returning, leaf: $leaf);
        $joined = self::getEntityNode(
            model: $model->right,
            key: $relation[1],
            columns: $columns,
            returning: $returning,
            leaf: $leaf,
        );
        if ($model instanceof LeftJoin) {
            if (!$joined instanceof UpdateEntityNode) {
                throw new LogicException('an optional side of several tables is not supported');
            }
            $joined = new UpdateOptionalEntityNode(
                update: $joined,
                insert: new InsertEntityNode(
                    entity: $joined->entity,
                    columns: $joined->columns,
                ),
            );
        }

        return new UpdateEntityJoinNode(
            relation: $relation,
            main: $main,
            joined: $joined,
        );
    }

    /**
     * Колонка `relation[0]` каждого соединения → в `returning` таблицы, где она лежит.
     *
     * @param Join|class-string<DatabaseEntitySource> $model
     * @param array<int, list<string>>  $returning
     */
    private static function returning(Join|string $model, ModelTree $tree, array &$returning): void
    {
        if (is_string($model)) {
            return;
        }
        $column = (string) $model->relation[0];
        $table = $tree->getColumnTable($column) ?? throw new LogicException("{$column} is not a model column");
        $returning[$table][] = $column;
        self::returning(model: $model->left, tree: $tree, returning: $returning);
        self::returning(model: $model->right, tree: $tree, returning: $returning);
    }
}
