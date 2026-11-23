<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Model\Fixture\Source;

use Ggs\JsonApiEngine\Model\DataSource;
use Ggs\JsonApiEngine\Model\ForeignDataSource;

/**
 * Неверный чужой источник: ключ `column` (имя колонки в БД) есть только у сущности.
 */
final class DatabaseColumnSource extends ForeignDataSource
{
    public const string ID = 'id';

    public const array COLUMNS = [
        self::ID => ['column' => 'source_id', 'type' => self::INT, 'constraint' => self::PRIMARY],
    ];
}
