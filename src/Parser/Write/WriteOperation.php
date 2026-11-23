<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Parser\Write;

use Ggs\JsonApiEngine\Resource;

/**
 * Операция над одним ресурсом: узел дерева, которое выдаёт разбор (`WriteDocumentParser`). Вид операции — класс:
 * `InsertOperation`, `UpdateOperation`, `KeepOperation`, `DeleteOperation`; у каждого только те свойства, которые у
 * операции бывают.
 *
 * Общее у всех — описание ресурса: по нему строится место плана. Указателя на место в документе у операций нет:
 * ошибка выполнения называет ресурс типом и `id` (в `meta` ошибки), а они у операции есть.
 */
abstract readonly class WriteOperation
{
    /**
     * @param class-string<Resource> $resource
     */
    public function __construct(
        public string $resource,
    ) {}

    /**
     * Связь по владению `name` этой операции; null — связи нет в документе (детей не трогают) или у операции связей
     * не бывает (`KeepOperation`, `DeleteOperation`). Узлы связей плана проходят все операции места, поэтому
     * спрашивают так, а не по классу операции.
     */
    public function getRelationship(string $name): ?RelationshipOperation
    {
        return null;
    }
}
