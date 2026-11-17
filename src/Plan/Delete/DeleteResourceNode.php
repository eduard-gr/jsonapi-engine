<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Delete;

use Ggs\JsonApiEngine\Resource;

/**
 * Узел плана удаления: ресурс (строка главной таблицы). Исполнитель — `DeleteResourceWriter`.
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
