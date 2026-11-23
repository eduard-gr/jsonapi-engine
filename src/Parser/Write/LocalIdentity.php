<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Parser\Write;

/**
 * `lid`: новый ресурс. Имя действует только внутри документа — по нему разбор находит ресурс `included` для
 * идентификатора из связи; в базу оно не попадает. В дереве операций остаётся только для ошибок.
 */
final readonly class LocalIdentity implements ResourceIdentityInterface
{
    public function __construct(
        public string $lid,
    ) {}

    #[\Override]
    public function getKey(): string
    {
        return "lid:{$this->lid}";
    }

    #[\Override]
    public function toMeta(): array
    {
        return ['lid' => $this->lid];
    }
}
