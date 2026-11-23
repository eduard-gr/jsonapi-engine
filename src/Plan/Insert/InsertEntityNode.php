<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Insert;

use Ggs\JsonApiEngine\Model\DatabaseEntitySource;

/**
 * Узел плана вставки: одна таблица модели ресурса (лист уровня таблиц). Исполнитель —
 * `Executor\Insert\InsertEntityWriter`. Стоит и в плане изменения: вход `insert` у `UpdateOptionalEntityNode`.
 *
 * Неизменяемый, без состояния: только сущность и колонки модели ресурса, которые лежат в её таблице
 * (`ModelTree::getColumnTable()`). Правая колонка `relation` соединения в `columns` не входит: её значение приходит
 * колонкой связи от `InsertEntityJoinNode`.
 */
final readonly class InsertEntityNode
{
    /**
     * @param class-string<DatabaseEntitySource> $entity
     * @param list<string>         $columns колонки модели ресурса в этой таблице
     */
    public function __construct(
        public string $entity,
        public array $columns,
    ) {}
}
