<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Parser\Write;

use Ggs\JsonApiEngine\Resource;

/**
 * Изменить ресурс: корень PATCH или идентификатор `{type, id}` с ресурсом в `included`. id — `identity->id`: имя
 * всегда `id`, изменить ресурс с `lid` нельзя по построению.
 *
 * Атрибуты — только присланные: присутствие ключа, а не значение, решает, что колонку трогать (`null` — это NULL в
 * колонку). Их может не быть вовсе (изменяются только связи) — тогда строка ресурса только ищется.
 */
final readonly class UpdateOperation extends WriteOperation
{
    /**
     * @param class-string<Resource>                 $resource      описание ресурса (по `type` документа)
     * @param array<string, mixed>                   $attributes    присланные атрибуты: имя → значение
     * @param array<string, PersistentIdentity|null> $references    связи по внешнему ключу: имя → цель (только
     *                                                              существующий ресурс), null — снять связь
     * @param array<string, RelationshipOperation>   $relationships связи по владению: имя → операции над детьми
     */
    public function __construct(
        string $resource,
        public PersistentIdentity $identity,
        public array $attributes,
        public array $references,
        public array $relationships,
    ) {
        parent::__construct(
            resource: $resource,
        );
    }

    #[\Override]
    public function getRelationship(string $name): ?RelationshipOperation
    {
        return $this->relationships[$name] ?? null;
    }
}
