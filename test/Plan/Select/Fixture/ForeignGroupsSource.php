<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Plan\Select\Fixture;

/**
 * Группы — чужой источник в роли родителя таблицы: сертификаты ссылаются на группу колонкой `provider_id`.
 */
final class ForeignGroupsSource extends ForeignRowsSource
{
    public const string ID = 'id';
    public const string NAME = 'name';

    public const array COLUMNS = [
        self::ID => ['type' => self::INT, 'constraint' => self::PRIMARY],
        self::NAME => ['type' => self::STRING],
    ];

    #[\Override]
    public static function getReadOrientation(): array
    {
        return [self::ID];
    }

    #[\Override]
    protected function getRows(): array
    {
        return [
            [self::ID => 1, self::NAME => 'one'],
            [self::ID => 3, self::NAME => 'three'],
            [self::ID => 4, self::NAME => 'four'],
        ];
    }
}
