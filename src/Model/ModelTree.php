<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Model;

use sad_spirit\pg_builder\nodes\ColumnReference;
use sad_spirit\pg_builder\nodes\expressions\OperatorExpression;
use sad_spirit\pg_builder\nodes\Identifier;
use sad_spirit\pg_builder\nodes\QualifiedName;
use sad_spirit\pg_builder\nodes\range\FromElement;
use sad_spirit\pg_builder\nodes\range\JoinExpression;
use sad_spirit\pg_builder\nodes\range\RelationReference;

/**
 * Разобранная модель данных: таблицы, колонки модели и соединения с найденными таблицами `relation`.
 *
 * Таблицы (листья дерева) нумеруются слева направо: крайняя левая — основная таблица, она получает алиас места
 * в запросе (`t0`), остальные — `<алиас>_<номер>` (`t0_1`, `t0_2`). Колонки модели — колонки всех сущностей, кроме правой
 * колонки `relation` каждого соединения: она равна левой. Колонка `relation` ищется по имени в своём поддереве.
 *
 * Разбор зависит только от класса, поэтому `of()` кеширует его на класс; ошибки собирает конструктор
 * (их показывает `validate()` класса с моделью).
 *
 * SQL модели отдаётся узлами pg_builder (`from()`, `expression()`), а не текстом. Узел pg_builder принадлежит одному
 * дереву запроса, поэтому каждый вызов строит новые узлы: их можно сразу вставлять в запрос, не клонируя. Имена таблиц
 * и колонок берутся только из констант сущностей; кавычки, если нужны, ставит pg_builder при выводе SQL.
 */
final class ModelTree
{
    /** @var array<class-string, ModelTree> */
    private static array $cache = [];

    /** @var list<class-string<Entity>> таблицы по номеру */
    private array $entities = [];

    /** @var array<string, array{int, string}> колонка модели → [номер таблицы, колонка таблицы] */
    private array $columns = [];

    /** @var list<string> */
    private array $errors = [];

    /** @var array<string, mixed>|null */
    private ?array $root;

    public function __construct(mixed $model)
    {
        [$this->root, $visible] = $this->analyze($model, 'root');
        foreach ($visible as $column => $leaf) {
            $this->columns[$column] = [$leaf, $this->entities[$leaf]::column($column)];
        }
    }

    /**
     * Разобранная модель класса со статическим `model(): Join|string` (в этом проекте — ресурса).
     *
     * @param class-string $class
     *
     * @throws \LogicException если модель с ошибками
     */
    public static function of(string $class): self
    {
        if (!isset(self::$cache[$class])) {
            $tree = self::build($class);
            if ($tree->errors !== []) {
                throw new \LogicException("{$class}: invalid model:\n- " . implode("\n- ", $tree->errors));
            }
            self::$cache[$class] = $tree;
        }

        return self::$cache[$class];
    }

    /**
     * Колонки модели.
     *
     * @return list<string>
     */
    public function columns(): array
    {
        return array_keys($this->columns);
    }

    public function hasColumn(string $column): bool
    {
        return isset($this->columns[$column]);
    }

    /**
     * Номер таблицы колонки модели (0 — основная) или null, если колонки нет.
     */
    public function columnTable(string $column): ?int
    {
        return $this->columns[$column][0] ?? null;
    }

    /**
     * `type` колонки модели из её сущности или null, если колонки нет.
     */
    public function columnType(string $column): ?string
    {
        if (!isset($this->columns[$column])) {
            return null;
        }

        return $this->entities[$this->columns[$column][0]]::COLUMNS[$column]['type'] ?? null;
    }

    /**
     * Уникальна ли колонка модели в строках модели: `PRIMARY` или `unique` основной таблицы. Соединения модели —
     * «к одному», поэтому строка модели — строка основной таблицы.
     */
    public function isUnique(string $column): bool
    {
        return $this->columnTable($column) === 0 && $this->entities[0]::isUnique($column);
    }

    /**
     * Ошибки модели; пусто, если модель корректна.
     *
     * @return list<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Разбирает модель класса без кеша и без исключения: ошибки — в `errors()`.
     *
     * @param class-string $class
     */
    public static function build(string $class): self
    {
        /** @var callable(): mixed $model */
        $model = [$class, 'model'];

        return new self($model());
    }

    /**
     * Элемент `FROM` с таблицами, которые нужны для колонок `$needed`: `RelationReference`, если подключена одна
     * таблица, иначе `JoinExpression`. Каждый вызов — новые узлы.
     *
     * `LEFT JOIN` без нужных колонок справа не подключается; `INNER JOIN` подключается всегда. Правое поддерево
     * из нескольких таблиц — вложенный `JoinExpression` справа; скобки вокруг него ставит pg_builder при выводе SQL.
     *
     * @param string               $alias  алиас места в запросе: алиас основной таблицы, остальные — `<alias>_<номер>`
     * @param array<string, mixed> $needed нужные колонки модели (ключи)
     *
     * @throws \LogicException если модель с ошибками
     */
    public function from(string $alias, array $needed): FromElement
    {
        if ($this->root === null) {
            throw new \LogicException('invalid model: ' . implode('; ', $this->errors));
        }

        return $this->render($this->root, $alias, $needed);
    }

    /**
     * Выражение колонки модели — `ColumnReference` `<алиас таблицы>.<колонка таблицы>`. Каждый вызов — новый узел.
     *
     * @param string $column колонка модели
     * @param string $alias  алиас места в запросе, тот же, что в `from()`
     *
     * @throws \LogicException если такой колонки в модели нет
     */
    public function expression(string $column, string $alias): ColumnReference
    {
        if (!isset($this->columns[$column])) {
            throw new \LogicException("column {$column} is not a model column");
        }
        [$leaf, $table_column] = $this->columns[$column];

        return new ColumnReference(self::alias($alias, $leaf), $table_column);
    }

    /**
     * Сущность основной таблицы или null, если модель с ошибками.
     *
     * @return class-string<Entity>|null
     */
    public function rootEntity(): ?string
    {
        return $this->entities[0] ?? null;
    }

    private static function alias(string $alias, int $leaf): string
    {
        return $leaf === 0 ? $alias : "{$alias}_{$leaf}";
    }

    /**
     * Поддерево модели в узлы pg_builder: лист — `RelationReference` с алиасом, соединение — `JoinExpression`
     * с `ON <правая колонка relation> = <левая колонка relation>`.
     *
     * @param array<string, mixed> $node
     * @param array<string, mixed> $needed
     */
    private function render(array $node, string $alias, array $needed): FromElement
    {
        if (isset($node['leaf'])) {
            // TABLE может быть со схемой (`schema.table`): каждая часть — отдельный идентификатор
            $table = new RelationReference(new QualifiedName(...explode('.', $this->entities[$node['leaf']]::TABLE)));
            $table->setAlias(new Identifier(self::alias($alias, $node['leaf'])));

            return $table;
        }

        /** @var Join $join */
        $join = $node['join'];
        if ($join instanceof LeftJoin && array_intersect_key($node['columns'], $needed) === []) {
            return $this->render($node['left'], $alias, $needed);
        }
        // соединению нужна левая колонка relation: её таблица в левом поддереве должна остаться
        $left = $this->render($node['left'], $alias, $needed + [$node['relation'] => true]);
        $right = $this->render($node['right'], $alias, $needed);
        [$left_leaf, $left_column, $right_leaf, $right_column] = $node['on'];

        $expression = new JoinExpression($left, $right, $join->type());
        $expression->on = new OperatorExpression(
            '=',
            new ColumnReference(self::alias($alias, $right_leaf), $right_column),
            new ColumnReference(self::alias($alias, $left_leaf), $left_column),
        );

        return $expression;
    }

    /**
     * Разбирает поддерево: нумерует таблицы, находит таблицы `relation`, собирает ошибки.
     *
     * @return array{array<string, mixed>|null, array<string, int>, int|null} узел, видимые колонки поддерева
     *                                                                        (колонка → номер таблицы) и номер
     *                                                                        его основной таблицы
     */
    private function analyze(mixed $model, string $location): array
    {
        if (\is_string($model)) {
            if (!is_subclass_of($model, Entity::class)) {
                $this->errors[] = "model {$location}: {$model} is not a subclass of " . Entity::class;

                return [null, [], null];
            }
            $leaf = \count($this->entities);
            $this->entities[] = $model;

            return [['leaf' => $leaf], array_fill_keys(array_keys($model::COLUMNS), $leaf), $leaf];
        }
        if (!$model instanceof Join) {
            $this->errors[] = "model {$location}: expected an entity class or a Join, got " . get_debug_type($model);

            return [null, [], null];
        }

        [$left, $left_visible, $left_main] = $this->analyze($model->left, "{$location}.left");
        [$right, $right_visible, $right_main] = $this->analyze($model->right, "{$location}.right");

        $relation = $model->relation;
        if (\count($relation) !== 2) {
            $this->errors[] = "model {$location}: relation must be [left column, right column]";

            return [null, $left_visible + $right_visible, $left_main];
        }

        [$left_column, $right_column] = $relation;
        $valid = $left !== null && $right !== null;
        if (!isset($left_visible[$left_column]) && $left !== null) {
            $this->errors[] = "model {$location}: column {$left_column} is missing in the left subtree";
            $valid = false;
        }
        if (!isset($right_visible[$right_column]) && $right !== null) {
            $this->errors[] = "model {$location}: column {$right_column} is missing in the right subtree";
            $valid = false;
        } elseif ($right !== null && $right_visible[$right_column] !== $right_main) {
            $this->errors[] = "model {$location}: column {$right_column} must belong to the main table of the right subtree";
            $valid = false;
        }

        if ($valid) {
            $left_entity = $this->entities[$left_visible[$left_column]];
            $right_entity = $this->entities[$right_visible[$right_column]];
            if (!$right_entity::isUnique($right_column)) {
                $this->errors[] = "model {$location}: column {$right_column} must be PRIMARY or unique in {$right_entity}"
                    . ' (relation must be to-one)';
            }
            if (($left_entity::COLUMNS[$left_column]['type'] ?? null)
                !== ($right_entity::COLUMNS[$right_column]['type'] ?? null)) {
                $this->errors[] = "model {$location}: columns {$left_column} and {$right_column} must have the same type";
            }
        }

        $right_leaf = $right_visible[$right_column] ?? $right_main;
        // правая колонка relation равна левой: колонкой модели она не считается
        unset($right_visible[$right_column]);
        foreach (array_intersect_key($right_visible, $left_visible) as $column => $leaf) {
            $this->errors[] = "model {$location}: column {$column} is defined in both "
                . $this->entities[$left_visible[$column]] . ' and ' . $this->entities[$leaf];
        }

        if (!$valid) {
            return [null, $left_visible + $right_visible, $left_main];
        }

        return [
            [
                'join' => $model,
                'left' => $left,
                'right' => $right,
                'on' => [
                    $left_visible[$left_column],
                    $this->entities[$left_visible[$left_column]]::column($left_column),
                    $right_leaf,
                    $this->entities[$right_leaf]::column($right_column),
                ],
                'relation' => $left_column,
                'columns' => $right_visible,
            ],
            $left_visible + $right_visible,
            $left_main,
        ];
    }
}
