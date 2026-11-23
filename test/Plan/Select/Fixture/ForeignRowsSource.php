<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Plan\Select\Fixture;

use Ggs\JsonApiEngine\Model\ForeignDataSource;
use Ggs\JsonApiEngine\Model\SelectForeignDataInterface;
use Ggs\JsonApiEngine\Operator;

/**
 * Общая часть чужих источников для ForeignDataPlanTest: строки в памяти (`getRows()` наследника), условия `eq`,
 * аргумент маршрута с именем колонки — условие равенства. Каждый вызов `select()` запоминается в `$calls`.
 */
abstract class ForeignRowsSource extends ForeignDataSource implements SelectForeignDataInterface
{
    /** @var list<array{columns: list<string>, conditions: list<array<string, mixed>>, order: list<array<string, mixed>>, arguments: array<string, mixed>}> */
    public array $calls = [];

    /**
     * @return list<array<string, mixed>>
     */
    abstract protected function getRows(): array;

    #[\Override]
    public function select(array $columns, array $conditions, array $order, array $arguments): iterable
    {
        $this->calls[] = ['columns' => $columns, 'conditions' => $conditions, 'order' => $order, 'arguments' => $arguments];
        foreach ($this->getRows() as $row) {
            if (!$this->isSelected(row: $row, conditions: $conditions, arguments: $arguments)) {
                continue;
            }
            yield array_intersect_key($row, array_flip($columns));
        }
    }

    /**
     * @param array<string, mixed>       $row
     * @param list<array<string, mixed>> $conditions
     * @param array<string, mixed>       $arguments
     */
    private function isSelected(array $row, array $conditions, array $arguments): bool
    {
        foreach ($conditions as $condition) {
            if ($condition['operator'] !== Operator::Eq || $row[$condition['column']] !== $condition['value']) {
                return false;
            }
        }
        foreach ($arguments as $column => $value) {
            if (\array_key_exists($column, $row) && $row[$column] !== $value) {
                return false;
            }
        }

        return true;
    }
}
