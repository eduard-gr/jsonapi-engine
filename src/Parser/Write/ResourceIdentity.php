<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Parser\Write;

/**
 * Чем ресурс назван в документе: `id` (`PersistentIdentity`) или `lid` (`LocalIdentity`). Один тип вместо двух
 * свойств, допускающих null: «ровно одно из двух» нельзя нарушить, а вид имени — главное, по чему разбор выбирает
 * операцию (`lid` — ресурс новый, `id` — существующий).
 *
 * Слово «идентификатор» оставлено за объектом `{type, id}` из связи (resource identifier object в JSON:API); это —
 * его значение.
 */
interface ResourceIdentity
{
    /**
     * Имя текстом: `id:3` или `lid:d1`.
     */
    public function key(): string;

    /**
     * Как ресурс называется в `meta` ошибки: `{id: "3"}` или `{lid: "d1"}`.
     *
     * @return array{id: string}|array{lid: string}
     */
    public function toMeta(): array;
}
