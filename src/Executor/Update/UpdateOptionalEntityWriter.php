<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor\Update;

use Ggs\JsonApiEngine\Executor\Insert\InsertEntityWriter;
use Ggs\JsonApiEngine\Executor\EntityWriterInterface;

/**
 * Необязательная часть модели при изменении (правая сторона `LeftJoin`): изменить строку, а если её нет —
 * вставить. Строит `WriterBuilder` по `UpdateOptionalEntityNode`.
 *
 * Поток: строка + `$link` → нет колонок этой части → `[]`; есть → `update->push()` → ключи; строки нет → все
 * присланные значения NULL → `[]` (вставлять нечего: SELECT и так покажет null); иначе `insert->push(строка, link)`.
 *
 * Строка есть, а все её колонки прислали NULL — в колонки пишется NULL, строка не удаляется. Вставка отсутствующей
 * строки без её NOT NULL колонки (`buyer_email`) — ошибка базы как есть.
 */
final readonly class UpdateOptionalEntityWriter implements EntityWriterInterface
{
    public function __construct(
        private UpdateEntityWriter $update,
        private InsertEntityWriter $insert,
    ) {}

    #[\Override]
    public function push(array $row, array $link = []): array
    {
        $own = array_intersect_key($row, array_flip($this->update->getColumns()));
        if ($own === []) {
            return [];
        }
        $keys = $this->update->push($row, $link);
        if ($keys !== [] || array_filter($own, static fn(mixed $value): bool => $value !== null) === []) {
            return $keys;
        }

        return $this->insert->push($row, $link);
    }

    #[\Override]
    public function getColumns(): array
    {
        return $this->update->getColumns();
    }

    #[\Override]
    public function explain(): array
    {
        return [
            'node' => static::class,
            'update' => $this->update->explain(),
            'insert' => $this->insert->explain(),
        ];
    }
}
