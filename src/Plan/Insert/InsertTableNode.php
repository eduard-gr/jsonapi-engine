<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Insert;

use Ggs\JsonApiEngine\Model\Entity;

/**
 * Узел плана вставки: одна таблица модели ресурса (лист уровня таблиц). Исполнитель — `Executor\InsertTableWriter`.
 *
 * Неизменяемый, без состояния: только сущность и колонки модели ресурса, которые лежат в её таблице
 * (`ModelTree::columnTable()`). Правая колонка `relation` соединения в `columns` не входит: её значение приходит колонкой
 * связи от `InsertTableJoinNode`.
 */
final readonly class InsertTableNode
{
    /**
     * @param class-string<Entity> $entity
     * @param list<string>         $columns колонки модели ресурса в этой таблице
     */
    public function __construct(
        public string $entity,
        public array $columns,
    ) {}
}
