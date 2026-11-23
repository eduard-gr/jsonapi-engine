<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\FixedColumn\Fixture;

use Ggs\JsonApiEngine\Resource;
use Ggs\JsonApiEngine\Resource\DeletableInterface;
use Ggs\JsonApiEngine\Resource\InsertableInterface;
use Ggs\JsonApiEngine\Resource\UpdatableInterface;

/**
 * `map` над сущностью с постоянной колонкой `MapComponentEntity`: id — id профиля, `kind` — вид компонента, только чтение.
 */
final class MapResource extends Resource implements InsertableInterface, UpdatableInterface, DeletableInterface
{
    public const string TYPE = 'map';
    public const string ID = 'id';
    public const string DATA = 'data';
    public const string KIND = 'kind';

    public const array ATTRIBUTES = [
        self::ID => ['column' => MapComponentEntity::USER_PROFILE_ID, 'type' => self::INT, 'constraint' => self::PRIMARY],
        self::DATA => ['column' => MapComponentEntity::DATA, 'type' => self::STRING],
        self::KIND => ['column' => MapComponentEntity::COMPONENT_ID, 'type' => self::INT, 'mode' => self::READ],
    ];

    public static function getModel(): string
    {
        return MapComponentEntity::class;
    }
}
