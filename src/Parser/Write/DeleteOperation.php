<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Parser\Write;

use Ggs\JsonApiEngine\Resource;

/**
 * Удалить ресурс: корень DELETE или идентификатор `{type, id}` с пометкой `remove`. Данных нет. Имя — только `id`:
 * удалить ресурс с `lid` нельзя по построению (как и оставить — `KeepOperation`).
 */
final readonly class DeleteOperation extends WriteOperation
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
