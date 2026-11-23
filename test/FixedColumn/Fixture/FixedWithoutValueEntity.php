<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\FixedColumn\Fixture;

use Ggs\JsonApiEngine\Model\DatabaseEntitySource;

/**
 * Ошибка описания постоянной колонки: `FIXED` без `value`.
 */
final class FixedWithoutValueEntity extends DatabaseEntitySource
{
    public const string TABLE = 'components';
    public const string USER_PROFILE_ID = 'user_profile_id';
    public const string COMPONENT_ID = 'component_id';

    public const array COLUMNS = [
        self::USER_PROFILE_ID => ['type' => self::INT, 'constraint' => self::PRIMARY],
        self::COMPONENT_ID => ['type' => self::INT, 'mode' => self::READ | self::FIXED],
    ];
}
