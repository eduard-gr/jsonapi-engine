<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Insert;

use Ggs\JsonApiEngine\Resource;
use Ggs\JsonApiEngine\Resource\InsertableInterface;

/**
 * Узел плана вставки: граница уровней — ресурс и поддерево таблиц его модели (`input`). Исполнитель —
 * `Executor\Insert\InsertResourceWriter` (`InsertOperation` → строка «колонка модели → значение» → поддерево таблиц).
 *
 * У ресурса над чужим источником (`Model\ForeignDataSource`) вход — `InsertForeignDataNode`: строка уходит в метод
 * `insert()` источника (`Executor\Insert\InsertForeignDataWriter`).
 *
 * Неизменяемый, без состояния. Ресурс — `InsertableInterface` (проверяет планировщик).
 */
final readonly class InsertResourceNode
{
    /**
     * @param class-string<Resource&InsertableInterface> $resource
     */
    public function __construct(
        public string $resource,
        public InsertEntityJoinNode|InsertEntityNode|InsertForeignDataNode $input,
    ) {}
}
