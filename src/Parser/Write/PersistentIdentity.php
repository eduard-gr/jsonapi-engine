<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Parser\Write;

/**
 * `id`: ресурс существует в базе. Значение — целое, проверенное при чтении документа (в документе это строка цифр).
 */
final readonly class PersistentIdentity implements ResourceIdentity
{
    public function __construct(
        public int $id,
    ) {}

    #[\Override]
    public function key(): string
    {
        return "id:{$this->id}";
    }

    #[\Override]
    public function toMeta(): array
    {
        return ['id' => (string) $this->id];
    }
}
