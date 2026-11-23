<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Plan\Write\Fixture;

use Ggs\JsonApiEngine\Model\DeleteForeignDataInterface;
use Ggs\JsonApiEngine\Model\ForeignDataSource;
use Ggs\JsonApiEngine\Model\InsertForeignDataInterface;
use Ggs\JsonApiEngine\Model\UpdateForeignDataInterface;

/**
 * Чужой источник с записью для ForeignDataWriteTest: заметки в памяти (`$rows`: id → строка), каждый вызов
 * запоминается в `$calls` вместе с аргументами маршрута. Ключ `$key` — условие: строка должна совпасть по всем его
 * колонкам, иначе её «нет» (пустые ключи).
 */
final class WritableNotesSource extends ForeignDataSource implements
    InsertForeignDataInterface,
    UpdateForeignDataInterface,
    DeleteForeignDataInterface
{
    public const string ID = 'id';
    public const string CERTIFICATE_ID = 'certificate_id';
    public const string TEXT = 'text';

    public const array COLUMNS = [
        self::ID => ['type' => self::INT, 'constraint' => self::PRIMARY],
        self::CERTIFICATE_ID => ['type' => self::INT, 'nullable' => true],
        self::TEXT => ['type' => self::STRING],
    ];

    /** @var array<int, array<string, mixed>> */
    public array $rows = [
        1 => [self::ID => 1, self::CERTIFICATE_ID => 1, self::TEXT => 'first'],
        2 => [self::ID => 2, self::CERTIFICATE_ID => 2, self::TEXT => 'second'],
    ];

    /** @var list<array{method: string, arguments: array<string, mixed>}> */
    public array $calls = [];

    #[\Override]
    public function insert(array $row, array $arguments): array
    {
        $this->calls[] = ['method' => 'insert', 'arguments' => $arguments];
        $id = max(array_keys($this->rows)) + 1;
        $this->rows[$id] = [self::CERTIFICATE_ID => null, ...$row, self::ID => $id];

        return [self::ID => $id];
    }

    #[\Override]
    public function update(array $key, array $row, array $arguments): array
    {
        $this->calls[] = ['method' => 'update', 'arguments' => $arguments];
        $id = $this->getFoundId(key: $key);
        if ($id === null) {
            return [];
        }
        $this->rows[$id] = [...$this->rows[$id], ...$row];

        return [self::ID => $id];
    }

    #[\Override]
    public function delete(array $key, array $arguments): array
    {
        $this->calls[] = ['method' => 'delete', 'arguments' => $arguments];
        $id = $this->getFoundId(key: $key);
        if ($id === null) {
            return [];
        }
        unset($this->rows[$id]);

        return [self::ID => $id];
    }

    /**
     * @param array<string, mixed> $key
     */
    private function getFoundId(array $key): ?int
    {
        $row = $this->rows[$key[self::ID] ?? 0] ?? null;
        if ($row === null || array_intersect_key($row, $key) != $key) {
            return null;
        }

        return $row[self::ID];
    }
}
