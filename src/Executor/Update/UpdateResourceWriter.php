<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor\Update;

use Ggs\JsonApiEngine\Executor\PushExecutorInterface;
use Ggs\JsonApiEngine\Executor\EntityWriterInterface;
use Ggs\JsonApiEngine\Executor\Write\ResourceDeserializer;
use Ggs\JsonApiEngine\Parser\Write\UpdateOperation;
use Ggs\JsonApiEngine\Parser\Write\WriteOperation;
use Ggs\JsonApiEngine\Resource;
use LogicException;

/**
 * Граница уровней при изменении: операция → строка «колонка модели → значение» → поддерево таблиц (`input`, из
 * `WriterBuilder` по плану `UpdatePlanner::getEntities()`). Пара к `InsertResourceWriter`. Исполняет `UpdateOperation`;
 * другая операция — LogicException (ресурс, который только ищут, `KeepOperation`, — `KeepEntityWriter`).
 *
 * Поток: операция (`identity->id`, `attributes`, `references`) + `$link` → `input->push(строка, link + [pk => id])`
 * → ключи всех таблиц; пусто — строки главной таблицы нет (или она чужая: колонки связи от родителя — условие
 * принадлежности), наверху это 404. Пустая строка (в документе нет ни атрибутов, ни связей по внешнему ключу) —
 * ресурс только ищется, ключи всех таблиц возвращаются.
 *
 * В строку идут только присланные атрибуты; `null` — это NULL в колонку (присутствие ключа, а не значение, решает,
 * что колонку трогать).
 *
 * Колонки связи узел не проверяет — это сделал план: `WritePlanner` один раз убедился, что колонка связи места есть
 * в модели ресурса. Если первичный ключ ресурса и есть колонка связи (`delivery.certificate_id`), id операции
 * подставляется на её место, и значение от родителя здесь теряется: сверяет их сторож `WriteLinkedKeyWriter`,
 * которого план ставит над этим узлом. Дерево, собранное руками без сторожа, для такого ресурса изменит чужую
 * строку молча.
 *
 * Ресурс — экземпляр из контейнера зависимостей (его даёт `WriterBuilder`; дерево, собранное руками, создаёт сам).
 * Строку из операции строит `$deserializer`: `ResourceDeserializer` — по описанию ресурса, `ManualResourceDeserializer`
 * — методом `deserialize()` ресурса (`Resource\DeserializeInterface`); какой из них, решает сборщик дерева.
 */
final readonly class UpdateResourceWriter implements PushExecutorInterface
{
    public function __construct(
        private Resource $resource,
        private ResourceDeserializer $deserializer,
        private EntityWriterInterface $input,
    ) {}

    #[\Override]
    public function push(WriteOperation $operation, array $link = []): array
    {
        if (!$operation instanceof UpdateOperation) {
            throw new LogicException($this->resource::class . ': update needs UpdateOperation, got ' . $operation::class);
        }
        $primary = $this->resource::getPrimaryKeyColumnName();
        $id = $operation->identity->id;
        $row = $this->deserializer->getRow(
            attributes: $operation->attributes,
            references: $operation->references,
            is_insert: false,
            id: $id,
            link: $link,
        );

        return $this->input->push($row, [...$link, $primary => $id]);
    }

    #[\Override]
    public function explain(): array
    {
        return [
            'node' => static::class,
            'resource' => $this->resource::class,
            'deserializer' => $this->deserializer::class,
            'input' => $this->input->explain(),
        ];
    }
}
