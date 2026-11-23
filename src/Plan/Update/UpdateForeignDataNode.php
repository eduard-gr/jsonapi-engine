<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Update;

use Ggs\JsonApiEngine\Model\ForeignDataSource;
use Ggs\JsonApiEngine\Model\UpdateForeignDataInterface;

/**
 * Узел плана изменения: строка ресурса уходит в чужой источник (`Model\ForeignDataSource`) — его методом `update()`,
 * без SQL. Вход `UpdateResourceNode` вместо поддерева сущностей (`UpdateEntityNode`). Исполнитель —
 * `Executor\Update\UpdateForeignDataWriter`.
 *
 * Ставит `Plan\Write\WritePlanner`, он же проверяет, что источник реализует `UpdateForeignDataInterface`.
 * Неизменяемый, без состояния.
 */
final readonly class UpdateForeignDataNode
{
    /**
     * @param class-string<ForeignDataSource&UpdateForeignDataInterface> $source класс источника: объект даст контейнер
     */
    public function __construct(
        public string $source,
    ) {}
}
