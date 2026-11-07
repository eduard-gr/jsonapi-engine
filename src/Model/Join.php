<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Model;

use sad_spirit\pg_builder\enums\JoinType;

/**
 * Узел модели данных: соединение двух поддеревьев, каждое — сущность или другое соединение.
 *
 * `relation` — `[колонка левого поддерева, колонка правого поддерева]`, имена колонок сущностей. Правая колонка —
 * колонка основной (крайней левой) таблицы правого поддерева, первичный ключ или `unique`: соединение to-one.
 */
abstract readonly class Join
{
    /**
     * @param Join|class-string<Entity>  $left
     * @param Join|class-string<Entity>  $right
     * @param list<string>              $relation `[колонка левого поддерева, колонка правого поддерева]`
     */
    public function __construct(
        public Join|string $left,
        public Join|string $right,
        public array $relation,
    ) {}

    /**
     * Вид соединения в pg_builder `JoinExpression` (`ModelTree::from()`).
     */
    abstract public function type(): JoinType;
}
