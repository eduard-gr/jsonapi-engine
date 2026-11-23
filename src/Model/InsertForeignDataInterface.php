<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Model;

/**
 * Чужой источник (`ForeignDataSource`) умеет вставлять.
 */
interface InsertForeignDataInterface
{
    /**
     * Новая строка. `$row` — колонки из документа и колонка связи с родителем, если ресурс вставляется как ребёнок.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $arguments
     * @return array<string, mixed> ключи строки, первичный обязательно
     */
    public function insert(array $row, array $arguments): array;
}
