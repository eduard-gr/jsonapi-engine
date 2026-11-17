<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Write;

use Ggs\JsonApiEngine\Plan\Delete\DeleteResourceNode;
use Ggs\JsonApiEngine\Plan\Insert\InsertResourceNode;
use Ggs\JsonApiEngine\Plan\Update\UpdateResourceNode;
use Ggs\JsonApiEngine\Resource;

/**
 * Узел плана записи: место дерева операций — ресурс и ветви по операциям, которые встретились у ресурсов этого места.
 * Исполнитель — `WriteOperationWriter`. Ветви нет (`null`) — операции нет в документе: `add` — поддерево вставки
 * (`Plan\Insert`) для `InsertOperation`, `update` — изменения для `UpdateOperation` (его же исполняет
 * `KeepOperation`), `remove` — удаления для `DeleteOperation`.
 */
final readonly class WriteOperationNode
{
    /**
     * @param class-string<Resource> $resource
     */
    public function __construct(
        public string $resource,
        public ?InsertResourceNode $add,
        public ?UpdateResourceNode $update,
        public ?DeleteResourceNode $remove,
    ) {}
}
