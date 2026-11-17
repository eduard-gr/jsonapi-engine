<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Parser\Write;

use Ggs\JsonApiEngine\Resource;

/**
 * Вставить ресурс: корень POST или идентификатор `{type, lid}` с ресурсом в `included`.
 *
 * Своего id нет: его даст база. Имя — только `lid` (у корня POST его может не быть): вставить ресурс с `id` нельзя
 * по построению. Атрибуты — только присланные: остальным колонкам значение даёт `DEFAULT` базы. У нового ресурса
 * все дети тоже новые, а полной замены в связях не бывает: удалять нечего.
 */
final readonly class InsertOperation extends WriteOperation
{
    /**
     * @param class-string<Resource>                 $resource      описание ресурса (по `type` документа)
     * @param LocalIdentity|null                     $identity      `lid`; null — корень POST без `lid`
     * @param array<string, mixed>                   $attributes    присланные атрибуты: имя → значение
     * @param array<string, PersistentIdentity|null> $references    связи по внешнему ключу: имя → цель (только
     *                                                              существующий ресурс), null — без связи
     * @param array<string, RelationshipOperation>   $relationships связи по владению: имя → операции над детьми
     */
    public function __construct(
        string $resource,
        public ?LocalIdentity $identity,
        public array $attributes,
        public array $references,
        public array $relationships,
    ) {
        parent::__construct(
            resource: $resource,
        );
    }

    #[\Override]
    public function relationship(string $name): ?RelationshipOperation
    {
        return $this->relationships[$name] ?? null;
    }
}
