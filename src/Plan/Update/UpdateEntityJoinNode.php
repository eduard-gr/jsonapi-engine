<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Update;

/**
 * Узел плана изменения: соединение двух частей модели ресурса (`LeftJoin`/`InnerJoin` из `getModel()`). Исполнитель —
 * `Executor\Update\UpdateEntityJoinWriter`.
 *
 * `main` — левая сторона, с главной таблицей: её ключ известен, она идёт первой. `joined` — правая: её строка ищется
 * по колонке `relation[1]`, значение которой даёт ключ `relation[0]` из `main`. Порядок не зависит от направления
 * внешнего ключа (у вставки наоборот, см. `InsertEntityJoinNode`). Правая сторона `LeftJoin` обёрнута в
 * `UpdateOptionalEntityNode`.
 *
 * Неизменяемый, без состояния.
 */
final readonly class UpdateEntityJoinNode
{
    /**
     * @param array{0: string, 1: string} $relation [колонка стороны main, колонка стороны joined]
     */
    public function __construct(
        public array $relation,
        public UpdateEntityJoinNode|UpdateEntityNode $main,
        public UpdateEntityJoinNode|UpdateEntityNode|UpdateOptionalEntityNode $joined,
    ) {}
}
