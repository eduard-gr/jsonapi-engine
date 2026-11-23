<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor\Write;

use Ggs\JsonApiEngine\Executor\PushExecutorInterface;
use Ggs\JsonApiEngine\Model\ForeignDataSource;
use Ggs\JsonApiEngine\Model\UpdateForeignDataInterface;
use Ggs\JsonApiEngine\Parser\Write\KeepOperation;
use Ggs\JsonApiEngine\Parser\Write\WriteOperation;
use Ggs\JsonApiEngine\Resource;
use LogicException;

/**
 * Поиск ресурса в источнике без изменения (`KeepOperation`): пара к `KeepEntityWriter`, вместо `SELECT` —
 * `update()` объекта источника с пустой строкой: отдельного метода поиска у источника нет, пустое изменение
 * возвращает ключи найденной строки или пусто.
 */
final readonly class KeepForeignDataWriter implements PushExecutorInterface
{
    /**
     * @param class-string<Resource> $resource
     * @param array<string, mixed>   $arguments
     */
    public function __construct(
        private string $resource,
        private ForeignDataSource&UpdateForeignDataInterface $source,
        private array $arguments,
    ) {}

    #[\Override]
    public function push(WriteOperation $operation, array $link = []): array
    {
        if (!$operation instanceof KeepOperation) {
            throw new LogicException("{$this->resource}: keep needs KeepOperation, got " . $operation::class);
        }

        return $this->source->update(
            key: [...$link, $this->resource::getPrimaryKeyColumnName() => $operation->identity->id],
            row: [],
            arguments: $this->arguments,
        );
    }

    #[\Override]
    public function explain(): array
    {
        return ['node' => static::class, 'source' => $this->source::class];
    }
}
