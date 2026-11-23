<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\FixedColumn\Fixture;

use Ggs\JsonApiEngine\Model\DatabaseEntitySource;

/**
 * Компонент «настройки трекинга»: строки общей таблицы `components` с `component_id = 1200`. Вид компонента — постоянная колонка
 * (`FIXED`); `user_profile_id` уникален только среди строк своего вида, поэтому объявлен `PRIMARY`.
 */
final class TrackingComponentEntity extends DatabaseEntitySource
{
    public const string TABLE = 'components';
    public const string USER_PROFILE_ID = 'user_profile_id';
    public const string COMPONENT_ID = 'component_id';
    public const string DATA = 'data';

    public const array COLUMNS = [
        self::USER_PROFILE_ID => ['type' => self::INT, 'constraint' => self::PRIMARY],
        self::COMPONENT_ID => ['type' => self::INT, 'mode' => self::READ | self::FIXED, 'value' => 1200],
        self::DATA => ['type' => self::STRING],
    ];
}
