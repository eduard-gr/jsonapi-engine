<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Update;

/**
 * Узел плана изменения: соединение двух частей модели, `main` (её ключ известен) раньше `joined`. Исполнитель —
 * `UpdateTableJoinWriter`.
 */
final readonly class UpdateTableJoinNode
{
    /**
     * @param array{0: string, 1: string} $relation [колонка стороны main, колонка стороны joined]
     */
    public function __construct(
        public array $relation,
        public UpdateTableJoinNode|UpdateTableNode $main,
        public UpdateTableJoinNode|UpdateTableNode|UpdateOptionalTableNode $joined,
    ) {}
}
