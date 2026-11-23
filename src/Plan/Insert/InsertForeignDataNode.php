<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Insert;

use Ggs\JsonApiEngine\Model\ForeignDataSource;
use Ggs\JsonApiEngine\Model\InsertForeignDataInterface;

/**
 * Узел плана вставки: строка ресурса уходит в чужой источник (`Model\ForeignDataSource`) — его методом `insert()`,
 * без SQL. Вход `InsertResourceNode` вместо поддерева сущностей (`InsertEntityNode`): чужой источник — всегда модель
 * целиком, поэтому поддерева у него нет. Исполнитель — `Executor\Insert\InsertForeignDataWriter`.
 *
 * Ставит `Plan\Write\WritePlanner`, он же проверяет, что источник реализует `InsertForeignDataInterface`.
 * Неизменяемый, без состояния.
 */
final readonly class InsertForeignDataNode
{
    /**
     * @param class-string<ForeignDataSource&InsertForeignDataInterface> $source класс источника: объект даст контейнер
     */
    public function __construct(
        public string $source,
    ) {}
}
