<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor\Insert;

/**
 * Необязательная часть модели при вставке (правая сторона `LeftJoin`): пропускает строку в поддерево, только если
 * в ней есть хотя бы одна колонка модели этого поддерева (`TableWriter::columns()`). Строит `WriterBuilder` по
 * `InsertOptionalTableNode`.
 *
 * Поток: строка + `$link` → есть колонки поддерева → `input->push(строка, link)` → его ключи; нет ни одной → ничего
 * не вставляется, ключи пустые (`[]`).
 *
 * Пустые ключи понимает `InsertTableJoinWriter`: пропущенный родитель не даёт ребёнку колонку связи, и ребёнок пишет
 * её значение по умолчанию (обычно NULL). Колонка связи из `$link` наличие части не доказывает: проверяются только
 * колонки модели в строке.
 */
final readonly class InsertOptionalTableWriter implements TableWriter
{
    public function __construct(
        private TableWriter $input,
    ) {}

    #[\Override]
    public function push(array $row, array $link = []): array
    {
        $present = array_intersect_key($row, array_flip($this->input->columns())) !== [];

        return $present ? $this->input->push($row, $link) : [];
    }

    #[\Override]
    public function columns(): array
    {
        return $this->input->columns();
    }

    #[\Override]
    public function explain(): array
    {
        return [
            'node' => static::class,
            'input' => $this->input->explain(),
        ];
    }
}
