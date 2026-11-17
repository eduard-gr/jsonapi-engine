<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Update;

use Ggs\JsonApiEngine\Resource;

/**
 * Узел плана изменения: граница уровней — ресурс и поддерево таблиц его модели (`input`, из
 * `UpdatePlanner::tables()`). Исполнитель — `Executor\Update\UpdateResourceWriter` (операция → строка «колонка
 * модели → значение» → поддерево таблиц). Ветвь `update` места (`Plan\Write\WriteOperationNode`): исполняет
 * `UpdateOperation` и `KeepOperation`.
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
        public UpdateTableJoinNode|UpdateTableNode $input,
    ) {}
}
