<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Write;

use Ggs\JsonApiEngine\Resource;

/**
 * Узел плана записи: ресурс, который в этом месте только ищут — ничего в нём не меняя. Ветвь `keep` места
 * (`WriteOperationNode`): исполняет `KeepOperation` (ресурс назван в документе ради его детей или как элемент,
 * который остаётся при полной замене). Исполнитель — `Executor\Write\KeepEntityWriter`: один SELECT главной
 * таблицы модели.
 *
 * Поддерева таблиц у узла нет (как у `Plan\Delete\DeleteEntityNode`): нужна только строка главной таблицы — в ней
 * первичный ключ, а детям от места нужен только он (связь по владению держится за первичный ключ владельца).
 *
 * Неизменяемый, без состояния.
 */
final readonly class KeepEntityNode
{
    /**
     * @param class-string<Resource> $resource
     */
    public function __construct(
        public string $resource,
    ) {}
}
