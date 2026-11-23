<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\FixedColumn\Fixture;

use Ggs\JsonApiEngine\Resource;
use Ggs\JsonApiEngine\Resource\OneToOne;
use Ggs\JsonApiEngine\Resource\UpdatableInterface;

/**
 * Профиль с двумя компонентами из одной таблицы (связи 1-1): `map` и `tracking` читаются тем же запросом, что профиль.
 */
final class ProfileResource extends Resource implements UpdatableInterface
{
    public const string TYPE = 'profile';
    public const string ID = 'id';
    public const string LOGIN = 'login';
    public const string MAP = 'map';
    public const string TRACKING = 'tracking';

    public const array ATTRIBUTES = [
        self::ID => ['column' => ProfilesEntity::ID, 'type' => self::INT, 'constraint' => self::PRIMARY],
        self::LOGIN => ['column' => ProfilesEntity::LOGIN, 'type' => self::STRING],
    ];

    #[\Override]
    public static function getRelationships(): array
    {
        return [
            self::MAP => new OneToOne(
                resource: MapResource::class,
                relation: [ProfilesEntity::ID, MapComponentEntity::USER_PROFILE_ID],
            ),
            self::TRACKING => new OneToOne(
                resource: TrackingResource::class,
                relation: [ProfilesEntity::ID, TrackingComponentEntity::USER_PROFILE_ID],
            ),
        ];
    }

    public static function getModel(): string
    {
        return ProfilesEntity::class;
    }
}
