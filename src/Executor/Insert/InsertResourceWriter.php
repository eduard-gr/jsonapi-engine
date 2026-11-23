<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor\Insert;

use Ggs\JsonApiEngine\Executor\PushExecutorInterface;
use Ggs\JsonApiEngine\Executor\EntityWriterInterface;
use Ggs\JsonApiEngine\Executor\Write\ResourceDeserializer;
use Ggs\JsonApiEngine\Parser\Write\InsertOperation;
use Ggs\JsonApiEngine\Parser\Write\WriteOperation;
use Ggs\JsonApiEngine\Resource;
use Ggs\JsonApiEngine\Resource\InsertableInterface;
use LogicException;

/**
 * Граница уровней при вставке: операция → строка «колонка модели → значение» → поддерево таблиц (`input`, из
 * `WriterBuilder` по плану `InsertPlanner::getEntities()`). Пара к `UpdateResourceWriter`. Исполняет `InsertOperation`;
 * другая операция — `LogicException`.
 *
 * Поток: `InsertOperation` (`attributes`, `references`) + колонки связи → строка (`ResourceDeserializer::getRow()`: атрибуты
 * по колонкам, связи по внешнему ключу — id цели или NULL) → `input->push(строка, link)` → ключи.
 *
 * Связи по владению (`domains`, `delivery`) не трогает: их детей вставляет `WriteJoinWriter`. В строку идут только
 * присланные атрибуты: остальным колонкам значение даёт `DEFAULT` базы. Ресурс без `InsertableInterface` — `LogicException` в
 * конструкторе.
 *
 * Ресурс — экземпляр из контейнера зависимостей (его даёт `WriterBuilder`; дерево, собранное руками, создаёт сам).
 * Строку из операции строит `$deserializer`: `ResourceDeserializer` — по описанию ресурса, `ManualResourceDeserializer`
 * — методом `deserialize()` ресурса (`Resource\DeserializeInterface`); какой из них, решает сборщик дерева.
 */
final readonly class InsertResourceWriter implements PushExecutorInterface
{
    public function __construct(
        private Resource $resource,
        private ResourceDeserializer $deserializer,
        private EntityWriterInterface $input,
    ) {
        if (!$resource instanceof InsertableInterface) {
            throw new LogicException($resource::class . ' is not InsertableInterface');
        }
    }

    #[\Override]
    public function push(WriteOperation $operation, array $link = []): array
    {
        if (!$operation instanceof InsertOperation) {
            throw new LogicException($this->resource::class . ": insert needs InsertOperation, got " . $operation::class);
        }

        return $this->input->push(
            $this->deserializer->getRow(
                attributes: $operation->attributes,
                references: $operation->references,
                is_insert: true,
                id: null,
                link: $link,
            ),
            $link,
        );
    }

    #[\Override]
    public function explain(): array
    {
        return ['node' => static::class, 'resource' => $this->resource::class,
            'deserializer' => $this->deserializer::class,
            'input' => $this->input->explain(),
        ];
    }
}
