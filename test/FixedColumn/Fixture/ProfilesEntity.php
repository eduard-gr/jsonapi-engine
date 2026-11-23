<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\FixedColumn\Fixture;

use Ggs\JsonApiEngine\Model\DatabaseEntitySource;

/**
 * Профили — обычная таблица для FixedColumnTest.
 */
final class ProfilesEntity extends DatabaseEntitySource
{
    public const string TABLE = 'profiles';
    public const string ID = 'id';
    public const string LOGIN = 'login';

    public const array COLUMNS = [
        self::ID => ['type' => self::INT, 'constraint' => self::PRIMARY],
        self::LOGIN => ['type' => self::STRING],
    ];
}
