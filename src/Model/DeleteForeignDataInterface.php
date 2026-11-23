<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Model;

/**
 * Чужой источник (`ForeignDataSource`) умеет удалять. Потомков он удаляет сам: каскада базы у него нет.
 */
interface DeleteForeignDataInterface
{
    /**
     * @param array<string, mixed> $key   первичный ключ и, у ребёнка, колонка связи с родителем
     * @param array<string, mixed> $arguments
     * @return array<string, mixed> ключи удалённой строки; пусто — строки нет
     */
    public function delete(array $key, array $arguments): array;
}
