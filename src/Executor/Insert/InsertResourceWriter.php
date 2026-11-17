<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor\Insert;

use Ggs\JsonApiEngine\Executor\PushExecutor;
use Ggs\JsonApiEngine\Executor\TableWriter;
use Ggs\JsonApiEngine\Executor\Write\ResourceRow;
use Ggs\JsonApiEngine\Parser\Write\InsertOperation;
use Ggs\JsonApiEngine\Parser\Write\WriteOperation;
use Ggs\JsonApiEngine\Resource;
use Ggs\JsonApiEngine\Resource\Insertable;
use LogicException;

/**
 * Граница уровней при вставке: операция → строка «колонка модели → значение» → поддерево таблиц (`input`, из
 * `WriterBuilder` по плану `InsertPlanner::tables()`). Пара к `UpdateResourceWriter`. Исполняет `InsertOperation`;
 * другая операция — `LogicException`.
 *
 * Поток: `InsertOperation` (`attributes`, `references`) + колонки связи → строка (`ResourceRow::columns()`: атрибуты
 * по колонкам, связи по внешнему ключу — id цели или NULL) → `input->push(строка, link)` → ключи.
 *
 * Связи по владению (`domains`, `delivery`) не трогает: их детей вставляет `WriteJoinWriter`. В строку идут только
 * присланные атрибуты: остальным колонкам значение даёт `DEFAULT` базы. Ресурс без `Insertable` — `LogicException` в
 * конструкторе.
 */
final readonly class InsertResourceWriter implements PushExecutor
{
    /**
     * @param class-string<Resource> $resource
     */
    public function __construct(
        private string $resource,
        private TableWriter $input,
    ) {
        if (!is_a($resource, Insertable::class, true)) {
            throw new LogicException("{$resource} is not Insertable");
        }
    }

    #[\Override]
    public function push(WriteOperation $operation, array $link = []): array
    {
        if (!$operation instanceof InsertOperation) {
            throw new LogicException("{$this->resource}: insert needs InsertOperation, got " . $operation::class);
        }

        return $this->input->push(
            ResourceRow::columns(
                resource: $this->resource,
                attributes: $operation->attributes,
                references: $operation->references,
            ),
            $link,
        );
    }

    #[\Override]
    public function explain(): array
    {
        return ['node' => static::class, 'resource' => $this->resource, 'input' => $this->input->explain()];
    }
}
