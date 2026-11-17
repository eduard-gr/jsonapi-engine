<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Update;

use Ggs\JsonApiEngine\Model\Entity;

/**
 * Узел плана изменения: одна таблица. Исполнитель — `UpdateTableWriter`.
 */
final readonly class UpdateTableNode
{
    /**
     * @param class-string<Entity> $entity
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
