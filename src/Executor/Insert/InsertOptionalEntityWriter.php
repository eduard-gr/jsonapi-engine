<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor\Insert;

use Ggs\JsonApiEngine\Executor\EntityWriterInterface;

/**
 * Необязательная часть модели при вставке (правая сторона `LeftJoin`): пропускает строку в поддерево, только если
 * в ней есть хотя бы одна колонка модели этого поддерева (`EntityWriterInterface::getColumns()`). Строит
 * `Executor\Write\WriterBuilder` по `InsertOptionalEntityNode`.
 *
 * Поток: строка + `$link` → есть колонки поддерева → `input->push(строка, link)` → его ключи; нет ни одной → ничего
 * не вставляется, ключи пустые (`[]`).
 *
 * Пустые ключи понимает `InsertEntityJoinWriter`: пропущенный родитель не даёт ребёнку колонку связи, и ребёнок пишет
 * её значение по умолчанию (обычно NULL). Колонка связи из `$link` наличие части не доказывает: проверяются только
 * колонки модели в строке.
 */
final readonly class InsertOptionalEntityWriter implements EntityWriterInterface
{
    public function __construct(
        private EntityWriterInterface $input,
    ) {}

    #[\Override]
    public function push(array $row, array $link = []): array
    {
        $present = array_intersect_key($row, array_flip($this->input->getColumns())) !== [];

        return $present ? $this->input->push($row, $link) : [];
    }

    #[\Override]
    public function getColumns(): array
    {
        return $this->input->getColumns();
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
