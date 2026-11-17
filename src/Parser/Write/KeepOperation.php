<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Parser\Write;

use Ggs\JsonApiEngine\Resource;

/**
 * Оставить ребёнка как есть: идентификатор `{type, id}` без ресурса в `included`. Данных нет — только проверка, что
 * ребёнок существует и принадлежит этому родителю. Своего узла плана нет: исполняет ветвь изменения.
 */
final readonly class KeepOperation extends WriteOperation
{
    /**
     * @param class-string<Resource> $resource
     */
    public function __construct(
        string $resource,
        public PersistentIdentity $identity,
    ) {
        parent::__construct(
            resource: $resource,
        );
    }
}
