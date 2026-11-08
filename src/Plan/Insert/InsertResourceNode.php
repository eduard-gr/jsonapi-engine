<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Insert;

use Ggs\JsonApiEngine\Resource;
use Ggs\JsonApiEngine\Resource\Insertable;

/**
 * Узел плана вставки: граница уровней — ресурс и поддерево таблиц его модели (`input`). Исполнитель —
 * `Executor\InsertResourceWriter` (ресурс JSON:API → строка «колонка модели → значение» → поддерево таблиц).
 *
 * Неизменяемый, без состояния. Ресурс — `Insertable` (проверяет планировщик).
 */
final readonly class InsertResourceNode
{
    /**
     * @param class-string<Resource&Insertable> $resource
     */
    public function __construct(
        public string $resource,
        public InsertTableJoinNode|InsertTableNode $input,
    ) {}
}
