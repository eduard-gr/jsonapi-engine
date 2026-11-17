<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Write;

use Ggs\JsonApiEngine\Plan\Delete\DeleteResourceNode;
use Ggs\JsonApiEngine\Plan\Insert\InsertResourceNode;
use Ggs\JsonApiEngine\Plan\Update\UpdateResourceNode;
use Ggs\JsonApiEngine\Resource;

/**
 * Узел плана записи: место дерева операций — ресурс и ветви по операциям, которые встретились у ресурсов этого места.
 * Исполнитель — `Executor\Write\WriteOperationWriter`.
 *
 * Ветви нет (`null`) — такой операции в месте нет: `add` — вставка (`Plan\Insert\InsertResourceNode`) для
 * `InsertOperation`, `update` — изменение (`Plan\Update\UpdateResourceNode`) для `UpdateOperation` (его же
 * исполняет `KeepOperation`), `remove` — удаление (`Plan\Delete\DeleteResourceNode`) для `DeleteOperation`. Какую
 * ветвь выполнять для конкретного элемента, решает класс его операции при выполнении.
 *
 * Неизменяемый, без состояния.
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
