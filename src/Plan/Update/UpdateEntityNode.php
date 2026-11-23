<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Update;

use Ggs\JsonApiEngine\Model\DatabaseEntitySource;

/**
 * Узел плана изменения: одна таблица модели ресурса (лист уровня таблиц). Исполнитель —
 * `Executor\Update\UpdateEntityWriter`.
 *
 * `key` — колонка, по которой ищется строка: первичный ключ ресурса у главной таблицы, колонка `relation[1]`
 * соединения у присоединённой. `returning` — колонки, которые нужны правым сторонам соединений (`relation[0]`),
 * кроме самого `key`.
 *
 * Неизменяемый, без состояния.
 */
final readonly class UpdateEntityNode
{
    /**
     * @param class-string<DatabaseEntitySource> $entity
     * @param list<string>         $columns   колонки модели ресурса в этой таблице
     * @param string               $key       колонка поиска строки
     * @param list<string>         $returning колонки, которые нужны правым сторонам
     */
    public function __construct(
        public string $entity,
        public array $columns,
        public string $key,
        public array $returning,
    ) {}
}
