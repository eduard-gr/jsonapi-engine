<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Update;

use Ggs\JsonApiEngine\Plan\Insert\InsertEntityNode;

/**
 * Узел плана изменения: необязательная часть модели (правая сторона `LeftJoin`, одна таблица) — изменение, а если
 * строки нет, вставка. Исполнитель — `Executor\Update\UpdateOptionalEntityWriter`.
 *
 * Два входа на одну таблицу с одними колонками: `update` — её изменение, `insert` — вставка отсутствующей строки
 * (узел плана вставки). Без колонок этой части в строке ресурса не выполняется ни один.
 *
 * Неизменяемый, без состояния.
 */
final readonly class UpdateOptionalEntityNode
{
    public function __construct(
        public UpdateEntityNode $update,
        public InsertEntityNode $insert,
    ) {}
}
