<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Delete;

use Ggs\JsonApiEngine\Model\DeleteForeignDataInterface;
use Ggs\JsonApiEngine\Model\ForeignDataSource;
use Ggs\JsonApiEngine\Resource;

/**
 * Узел плана удаления: ресурс над чужим источником (`Model\ForeignDataSource`). Пара к `DeleteEntityNode`; ветвь
 * `remove` места (`Plan\Write\WriteOperationNode`). Исполнитель — `Executor\Delete\DeleteForeignDataWriter`:
 * вызывает `delete()` источника. Потомков источник удаляет сам — каскада базы у него нет.
 *
 * Ставит `Plan\Write\WritePlanner`, он же проверяет, что источник реализует `DeleteForeignDataInterface`.
 * Неизменяемый, без состояния.
 */
final readonly class DeleteForeignDataNode
{
    /**
     * @param class-string<Resource>                                     $resource
     * @param class-string<ForeignDataSource&DeleteForeignDataInterface> $source   класс источника: объект даст контейнер
     */
    public function __construct(
        public string $resource,
        public string $source,
    ) {}
}
