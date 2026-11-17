<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Delete;

use Ggs\JsonApiEngine\Resource;

/**
 * Узел плана удаления: ресурс. Исполнитель — `Executor\Delete\DeleteResourceWriter`: удаляет строку главной
 * таблицы модели, всё зависимое — каскад базы. Ветвь `remove` места (`Plan\Write\WriteOperationNode`).
 *
 * Поддерева таблиц нет: присоединённые таблицы модели движок не удаляет. Неизменяемый, без состояния.
 */
final readonly class DeleteResourceNode
{
    /**
     * @param class-string<Resource> $resource
     */
    public function __construct(
        public string $resource,
    ) {}
}
