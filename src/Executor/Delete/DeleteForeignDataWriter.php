<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor\Delete;

use Ggs\JsonApiEngine\Executor\PushExecutorInterface;
use Ggs\JsonApiEngine\Model\ForeignDataSource;
use Ggs\JsonApiEngine\Model\DeleteForeignDataInterface;
use Ggs\JsonApiEngine\Parser\Write\DeleteOperation;
use Ggs\JsonApiEngine\Parser\Write\WriteOperation;
use Ggs\JsonApiEngine\Resource;
use LogicException;

/**
 * Удаление ресурса из источника: пара к `DeleteEntityWriter`, вместо `DELETE` — `delete()` объекта источника.
 */
final readonly class DeleteForeignDataWriter implements PushExecutorInterface
{
    /**
     * @param class-string<Resource> $resource
     * @param array<string, mixed>   $arguments
     */
    public function __construct(
        private string $resource,
        private ForeignDataSource&DeleteForeignDataInterface $source,
        private array $arguments,
    ) {}

    #[\Override]
    public function push(WriteOperation $operation, array $link = []): array
    {
        if (!$operation instanceof DeleteOperation) {
            throw new LogicException("{$this->resource}: delete needs DeleteOperation, got " . $operation::class);
        }

        return $this->source->delete(
            key: [...$link, $this->resource::getPrimaryKeyColumnName() => $operation->identity->id],
            arguments: $this->arguments,
        );
    }

    #[\Override]
    public function explain(): array
    {
        return ['node' => static::class, 'source' => $this->source::class];
    }
}
