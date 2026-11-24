<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Parser\Fixture;

use Ggs\JsonApiEngine\Model\DatabaseEntitySource;

/**
 * Ошибка описания: `OPTIONAL` у колонки сущности. Набор полей по умолчанию — свойство ресурса, сущность о клиенте
 * не знает.
 */
final class OptionalColumnEntity extends DatabaseEntitySource
{
    public const string TABLE = 'certificates';
    public const string ID = 'id';
    public const string NOMINAL = 'nominal';

    public const array COLUMNS = [
        self::ID => ['type' => self::INT, 'constraint' => self::PRIMARY],
        self::NOMINAL => ['type' => self::STRING, 'mode' => self::READ | self::OPTIONAL],
    ];
}
