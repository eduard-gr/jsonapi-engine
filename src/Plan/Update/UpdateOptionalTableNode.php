<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Update;

use Ggs\JsonApiEngine\Plan\Insert\InsertTableNode;

/**
 * Узел плана изменения: необязательная часть модели (правая сторона `LeftJoin`, одна таблица) — изменение, а если
 * строки нет, вставка. Исполнитель — `Executor\Update\UpdateOptionalTableWriter`.
 *
 * Два входа на одну таблицу с одними колонками: `update` — её изменение, `insert` — вставка отсутствующей строки
 * (узел плана вставки). Без колонок этой части в строке ресурса не выполняется ни один.
 *
 * Неизменяемый, без состояния.
 */
final readonly class UpdateOptionalTableNode
{
    public function __construct(
        public UpdateTableNode $update,
        public InsertTableNode $insert,
    ) {}
}
