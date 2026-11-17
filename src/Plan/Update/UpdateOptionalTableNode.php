<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Update;

use Ggs\JsonApiEngine\Plan\Insert\InsertTableNode;

/**
 * Узел плана изменения: необязательная часть модели (правая сторона `LeftJoin`, одна таблица) — изменение, а если
 * строки нет, вставка. Исполнитель — `UpdateOptionalTableWriter`.
 */
final readonly class UpdateOptionalTableNode
{
    public function __construct(
        public UpdateTableNode $update,
        public InsertTableNode $insert,
    ) {}
}
