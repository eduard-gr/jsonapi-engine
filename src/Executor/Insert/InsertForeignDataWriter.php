<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor\Insert;

use Ggs\JsonApiEngine\Executor\EntityWriterInterface;
use Ggs\JsonApiEngine\Model\ForeignDataSource;
use Ggs\JsonApiEngine\Model\InsertForeignDataInterface;

/**
 * Уровень таблиц для источника при вставке: пара к `InsertEntityWriter`, вместо `INSERT` — `insert()` объекта источника.
 *
 * Поток: строка ресурса + колонки связи → `insert()` → ключи строки.
 */
final readonly class InsertForeignDataWriter implements EntityWriterInterface
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        private ForeignDataSource&InsertForeignDataInterface $source,
        private array $arguments,
    ) {}

    #[\Override]
    public function push(array $row, array $link = []): array
    {
        return $this->source->insert(
            row: [...$row, ...array_intersect_key($link, $this->source::COLUMNS)],
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
