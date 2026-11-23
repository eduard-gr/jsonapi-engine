<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Update;

use Ggs\JsonApiEngine\Resource;

/**
 * Узел плана изменения: граница уровней — ресурс и поддерево таблиц его модели (`input`, из
 * `UpdatePlanner::getEntities()`). Исполнитель — `Executor\Update\UpdateResourceWriter` (операция → строка «колонка
 * модели → значение» → поддерево таблиц). Ветвь `update` места (`Plan\Write\WriteOperationNode`): исполняет
 * `UpdateOperation`; ресурс, который только ищут (`KeepOperation`), — отдельная ветвь `keep`
 * (`Plan\Write\KeepEntityNode`).
 *
 * У ресурса над чужим источником (`Model\ForeignDataSource`) вход — `UpdateForeignDataNode`: строка уходит в метод
 * `update()` источника (`Executor\Update\UpdateForeignDataWriter`).
 *
 * Неизменяемый, без состояния.
 */
final readonly class UpdateResourceNode
{
    /**
     * @param class-string<Resource> $resource
     */
    public function __construct(
        public string $resource,
        public UpdateEntityJoinNode|UpdateEntityNode|UpdateForeignDataNode $input,
    ) {}
}
