<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor\Update;

use Ggs\JsonApiEngine\Executor\EntityWriterInterface;
use Ggs\JsonApiEngine\Model\ForeignDataSource;
use Ggs\JsonApiEngine\Model\UpdateForeignDataInterface;

/**
 * Уровень таблиц для источника при изменении: пара к `UpdateEntityWriter`, вместо `UPDATE` — `update()` объекта
 * источника.
 *
 * Поток: изменяемые колонки + ключ и условие принадлежности (`$link`) → `update()` → ключи; пусто — строки нет.
 */
final readonly class UpdateForeignDataWriter implements EntityWriterInterface
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        private ForeignDataSource&UpdateForeignDataInterface $source,
        private array $arguments,
    ) {}

    #[\Override]
    public function push(array $row, array $link = []): array
    {
        return $this->source->update(
            key: $link,
            row: $row,
            arguments: $this->arguments,
        );
    }

    #[\Override]
    public function getColumns(): array
    {
        return array_keys($this->source::COLUMNS);
    }

    #[\Override]
    public function explain(): array
    {
        return ['node' => static::class, 'source' => $this->source::class];
    }
}
