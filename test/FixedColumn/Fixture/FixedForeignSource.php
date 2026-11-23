<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\FixedColumn\Fixture;

use Ggs\JsonApiEngine\Model\ForeignDataSource;

/**
 * Ошибка описания: `FIXED` у чужого источника — движок SQL для него не строит, подставлять значение некому.
 */
final class FixedForeignSource extends ForeignDataSource
{
    public const string ID = 'id';
    public const string KIND = 'kind';

    public const array COLUMNS = [
        self::ID => ['type' => self::INT, 'constraint' => self::PRIMARY],
        self::KIND => ['type' => self::INT, 'mode' => self::READ | self::FIXED],
    ];
}
