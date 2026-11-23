<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\FixedColumn\Fixture;

use Ggs\JsonApiEngine\Resource;

/**
 * Ошибка описания: атрибут над постоянной колонкой объявлен записываемым (режим по умолчанию — `READ | WRITE`).
 */
final class WritableKindResource extends Resource
{
    public const string TYPE = 'writable-kind';
    public const string ID = 'id';
    public const string KIND = 'kind';

    public const array ATTRIBUTES = [
        self::ID => ['column' => MapComponentEntity::USER_PROFILE_ID, 'type' => self::INT, 'constraint' => self::PRIMARY],
        self::KIND => ['column' => MapComponentEntity::COMPONENT_ID, 'type' => self::INT],
    ];

    public static function getModel(): string
    {
        return MapComponentEntity::class;
    }
}
