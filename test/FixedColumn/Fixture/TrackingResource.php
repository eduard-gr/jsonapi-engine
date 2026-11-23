<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\FixedColumn\Fixture;

use Ggs\JsonApiEngine\Resource;
use Ggs\JsonApiEngine\Resource\DeletableInterface;
use Ggs\JsonApiEngine\Resource\InsertableInterface;
use Ggs\JsonApiEngine\Resource\UpdatableInterface;

/**
 * `tracking` над сущностью с постоянной колонкой `TrackingComponentEntity`: id — id профиля, `kind` — вид компонента, только чтение.
 */
final class TrackingResource extends Resource implements InsertableInterface, UpdatableInterface, DeletableInterface
{
    public const string TYPE = 'tracking';
    public const string ID = 'id';
    public const string DATA = 'data';
    public const string KIND = 'kind';

    public const array ATTRIBUTES = [
        self::ID => ['column' => TrackingComponentEntity::USER_PROFILE_ID, 'type' => self::INT, 'constraint' => self::PRIMARY],
        self::DATA => ['column' => TrackingComponentEntity::DATA, 'type' => self::STRING],
        self::KIND => ['column' => TrackingComponentEntity::COMPONENT_ID, 'type' => self::INT, 'mode' => self::READ],
    ];

    public static function getModel(): string
    {
        return TrackingComponentEntity::class;
    }
}
