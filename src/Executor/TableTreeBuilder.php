<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor;

use Ggs\JsonApiEngine\Model\Entity;
use Ggs\JsonApiEngine\Model\Join;
use Ggs\JsonApiEngine\Model\LeftJoin;
use Ggs\JsonApiEngine\Model\ModelTree;
use Ggs\JsonApiEngine\Resource;
use Ggs\JsonApiEngine\Resource\Insertable;
use LogicException;
use PDO;

/**
 * Разворачивает модель ресурса (`model()`: сущность или дерево `LeftJoin`/`InnerJoin`) в поддерево вставки уровня
 * таблиц. Прообраз части будущего планировщика вставки; дерево уровня ресурсов (`InsertJoinWriter`) пока собирается
 * руками.
 *
 * Поток: класс `Insertable`-ресурса → TableWriter: сущность → `InsertTableWriter` со своими колонками модели
 * (`ModelTree::columnTable()`; номера таблиц слева направо, как в `ModelTree`), соединение → `InsertTableJoinWriter`.
 *
 * Порядок сторон — по метаданным сущностей. Для `relation` `[l, r]`: левая первой, если `l` — первичный ключ левой
 * сущности или `r` ссылается на неё (`references`); правая первой, если `r` — первичный ключ правой или `l` ссылается
 * на неё; иначе `LogicException`. Сущность колонки `relation` ищется по имени в своём поддереве (первая таблица слева,
 * где она есть), как в `ModelTree`. `LeftJoin` делает правую сторону необязательной.
 *
 * Пишутся все таблицы модели: возможности задаёт ресурс (`Insertable`), у сущностей флагов нет. Ресурс без
 * `Insertable` или с некорректной моделью — `LogicException` (`ModelTree::of()`).
 */
final readonly class TableTreeBuilder
{
    public function __construct(
        private PDO $pdo,
    ) {}

    /**
     * @param class-string<Resource> $resource
     */
    public function build(string $resource): TableWriter
    {
        if (!is_a($resource, Insertable::class, true)) {
            throw new LogicException("{$resource} is not Insertable");
        }
        $tree = ModelTree::of($resource);
        $columns = [];
        foreach ($tree->columns() as $column) {
            $columns[(int) $tree->columnTable($column)][] = $column;
        }
        $leaf = 0;

        // model() объявляют наследники Resource, базовый класс его не знает (как в ModelTree::build())
        /** @var callable(): (Join|class-string<Entity>) $model */
        $model = [$resource, 'model'];

        return $this->writer(model: $model(), columns: $columns, leaf: $leaf);
    }

    /**
     * @param Join|class-string<Entity> $model
     * @param array<int, list<string>>  $columns номер таблицы → её колонки модели
     * @param int                       $leaf    номер следующей таблицы слева направо
     */
    private function writer(Join|string $model, array $columns, int &$leaf): TableWriter
    {
        if (is_string($model)) {
            $number = $leaf++;

            return new InsertTableWriter(pdo: $this->pdo, entity: $model, columns: $columns[$number] ?? []);
        }

        $left = $this->writer(model: $model->left, columns: $columns, leaf: $leaf);
        $right = $this->writer(model: $model->right, columns: $columns, leaf: $leaf);

        /** @var array{0: string, 1: string} $relation */
        $relation = $model->relation;
        [$l, $r] = $relation;
        $leftEntity = self::owner(model: $model->left, column: $l);
        $rightEntity = self::owner(model: $model->right, column: $r);
        $optional = $model instanceof LeftJoin;

        if ($leftEntity::primaryColumn() === $l || ($rightEntity::COLUMNS[$r]['references'] ?? null) === $leftEntity) {
            return new InsertTableJoinWriter(relation: [$l, $r], first: $left, then: $right, optional: $optional ? 'then' : null);
        }
        if ($rightEntity::primaryColumn() === $r || ($leftEntity::COLUMNS[$l]['references'] ?? null) === $rightEntity) {
            return new InsertTableJoinWriter(relation: [$r, $l], first: $right, then: $left, optional: $optional ? 'first' : null);
        }

        throw new LogicException("Can not order join on [{$l}, {$r}]: no primary key or reference between the tables");
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
