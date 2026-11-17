<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Update;

use Ggs\JsonApiEngine\Resource;

/**
 * Узел плана изменения: граница уровней — ресурс и поддерево таблиц его модели. Исполнитель — `UpdateResourceWriter`.
 */
final readonly class UpdateResourceNode
{
    /**
     * @param class-string<Resource> $resource
     */
    public function __construct(
        public string $resource,
        public UpdateTableJoinNode|UpdateTableNode $input,
    ) {}
}
