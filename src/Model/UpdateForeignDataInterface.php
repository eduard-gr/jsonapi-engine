<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Model;

/**
 * Чужой источник (`ForeignDataSource`) умеет изменять.
 */
interface UpdateForeignDataInterface
{
    /**
     * Изменение строки. `$key` — первичный ключ и, у ребёнка, колонка связи с родителем (условие принадлежности).
     *
     * @param array<string, mixed> $key
     * @param array<string, mixed> $row   изменяемые колонки; пусто — только проверить, что строка есть
     * @param array<string, mixed> $arguments
     * @return array<string, mixed> ключи строки; пусто — строки нет (движок ответит 404)
     */
    public function update(array $key, array $row, array $arguments): array;
}
