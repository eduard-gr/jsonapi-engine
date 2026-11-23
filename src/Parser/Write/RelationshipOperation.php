<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Parser\Write;

/**
 * Связь по владению: что сделать с детьми одного владельца. Связи нет в документе — нет и ключа в `relationships`
 * владельца, детей не трогают; связь прислана пустой — ключ есть, `children` пуст, это не одно и то же.
 *
 * `replace` — полная замена: дети владельца, которых нет среди `children`, удаляются до остальных операций
 * (`EntityReplaceNode` плана). `children` — уже в порядке выполнения: удаления, затем оставленные и изменённые, затем
 * вставки — освобождённое значение уникальной колонки может занять новый ребёнок. To-one (`delivery`) — тот же
 * список: одна операция или пусто.
 *
 * Как разбор получает операцию из идентификатора связи to-many (правила PATCH):
 *
 *     пометка   идентификатор   ресурс в included   операция
 *     нет       {lid}           есть                InsertOperation
 *     нет       {id}            есть                UpdateOperation
 *     нет       {id}            нет                 KeepOperation
 *     add       {lid}           есть                InsertOperation
 *     update    {id}            есть                UpdateOperation
 *     remove    {id}            нет                 DeleteOperation
 *
 * Остальные сочетания — ошибка документа. Пометка `meta.op` в дерево не попадает: она нужна только для этого выбора
 * и для `replace`. `replace` у to-many — в списке нет ни одной пометки; у to-one (пометок у него нет) — прислан
 * `{lid}` или `null`. У нового владельца (POST, вставленный ребёнок) замены не бывает: удалять нечего.
 */
final readonly class RelationshipOperation
{
    /**
     * @param list<WriteOperation> $children
     */
    public function __construct(
        public bool $replace,
        public array $children,
    ) {}
}
