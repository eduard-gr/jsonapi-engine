<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor;

use LogicException;

/**
 * Соединение таблиц модели при вставке: строка ресурса делится между двумя сторонами; сторона с первичным ключом
 * связи (`first`) вставляется первой, вторая (`then`) получает колонку связи из её ключей. Строит `WriterBuilder` по `InsertTableJoinNode`
 * из `LeftJoin`/`InnerJoin` модели ресурса.
 *
 * Поток: строка + `$link` → `first->push()` → ключи → `then->push(строка, link + [relation[1] => keys[relation[0]]])`
 * → объединённые ключи обеих сторон.
 *
 * `optional` — сторона справа в `LeftJoin` (`first` или `then`, смотря какая вставляется первой): если в строке нет ни
 * одной колонки модели этой стороны (`TableWriter::columns()`), она не вставляется — в SELECT это отсутствующая часть
 * модели (`null`). Пропущенная `first` не даёт колонку связи: `then` пишет её значение по умолчанию (обычно NULL).
 * `InnerJoin` (`optional` null) вставляет обе стороны всегда. Входящий `$link` уходит в обе стороны: лист берёт из него
 * только колонки своей сущности.
 *
 * Ограничение: ключи сторон сливаются в одну карту; колонка с одним именем в двух таблицах — значение `then`
 * перезапишет значение `first` (для пары колонок `relation` значение одно и то же).
 */
final readonly class InsertTableJoinWriter implements TableWriter
{
    /**
     * @param array{0: string, 1: string} $relation [колонка `first`, колонка `then`]
     * @param 'first'|'then'|null         $optional необязательная сторона (`LeftJoin`); null — `InnerJoin`
     */
    public function __construct(
        private array $relation,
        private TableWriter $first,
        private TableWriter $then,
        private ?string $optional,
    ) {}

    #[\Override]
    public function push(array $row, array $link = []): array
    {
        $first = $this->present(writer: $this->first, side: 'first', row: $row) ? $this->first->push($row, $link) : [];

        $thenLink = $link;
        if ($first !== []) {
            $thenLink[$this->relation[1]] = $first[$this->relation[0]]
                ?? throw new LogicException("No key {$this->relation[0]} after insert");
        }
        $then = $this->present(writer: $this->then, side: 'then', row: $row) ? $this->then->push($row, $thenLink) : [];

        return [...$first, ...$then];
    }

    /**
     * Вставлять ли сторону: обязательная — всегда, необязательная — если в строке есть её колонка.
     */
    private function present(TableWriter $writer, string $side, array $row): bool
    {
        return $this->optional !== $side || array_intersect_key($row, array_flip($writer->columns())) !== [];
    }

    #[\Override]
    public function columns(): array
    {
        return [...$this->first->columns(), ...$this->then->columns()];
    }

    #[\Override]
    public function explain(): array
    {
        return [
            'node' => static::class,
            'relation' => $this->relation,
            'optional' => $this->optional,
            'first' => $this->first->explain(),
            'then' => $this->then->explain(),
        ];
    }
}
