<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor\Select;

use Generator;
use Ggs\JsonApiEngine\Executor\PullExecutorInterface;
use Ggs\JsonApiEngine\Model\SelectForeignDataInterface;

/**
 * Лист дерева читателей: строки чужого источника (`Model\ForeignDataSource`). Пара к `EntityScanReader`, только
 * вместо SQL — `select()` объекта источника. Узел плана — `Plan\Select\ForeignDataScanNode`: колонки, условия и
 * сортировку считает он, объект источника и аргументы маршрута даёт `ReaderBuilder`.
 *
 * Поток: вход — нет → выход — строки array «колонка модели → значение». Над ним, как над запросом, стоят
 * `SerializeReader` и `RegisterReader`.
 */
final class ForeignDataScanReader implements PullExecutorInterface
{
    /**
     * @param SelectForeignDataInterface              $source
     * @param list<string>               $columns
     * @param list<array<string, mixed>> $conditions
     * @param list<array<string, mixed>> $order
     * @param array<string, mixed>       $arguments
     */
    public function __construct(
        private SelectForeignDataInterface $source,
        private array $columns,
        private array $conditions,
        private array $order,
        private array $arguments,
    ) {}

    #[\Override]
    public function explain(): array
    {
        return ['node' => static::class, 'source' => $this->source::class, 'columns' => $this->columns];
    }

    #[\Override]
    public function getIterator(): Generator
    {
        yield from $this->source->select(
            columns: $this->columns,
            conditions: $this->conditions,
            order: $this->order,
            arguments: $this->arguments,
        );
    }
}
