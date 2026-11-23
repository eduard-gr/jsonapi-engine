<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Example\Resource;

use Ggs\JsonApiEngine\Example\Entity\CertificateDeliveryMetaEntity;
use Ggs\JsonApiEngine\Operator;
use Ggs\JsonApiEngine\Resource;
use Ggs\JsonApiEngine\Resource\DeletableInterface;
use Ggs\JsonApiEngine\Resource\InsertableInterface;
use Ggs\JsonApiEngine\Resource\UpdatableInterface;

/**
 * `delivery`: таблица `certificate_delivery_meta`, идентификатор — её первичный ключ `certificate_id`.
 *
 * Можно создавать (`InsertableInterface`): вставку см. `Executor\Insert\InsertResourceWriter`; изменять и удалять
 * (`UpdatableInterface`, `DeletableInterface`).
 */
final class DeliveryResource extends Resource implements InsertableInterface, UpdatableInterface, DeletableInterface
{
    public const string TYPE = 'delivery';

    public const string ID = 'id';
    public const string DELIVERY_CHANNEL = 'delivery_channel';
    public const string IS_SENT = 'is_sent';
    public const string EXTERNAL_PROVIDER_ID = 'external_provider_id';

    public const array ATTRIBUTES = [
        self::ID => [
            'column' => CertificateDeliveryMetaEntity::CERTIFICATE_ID,
            'type' => self::INT,
            'constraint' => self::PRIMARY,
        ],
        self::DELIVERY_CHANNEL => [
            'column' => CertificateDeliveryMetaEntity::DELIVERY_CHANNEL,
            'type' => self::STRING,
        ],
        self::IS_SENT => [
            'column' => CertificateDeliveryMetaEntity::IS_SENT,
            'type' => self::BOOL,
            'filter' => [Operator::Eq],
        ],
        self::EXTERNAL_PROVIDER_ID => [
            'column' => CertificateDeliveryMetaEntity::EXTERNAL_PROVIDER_ID,
            'type' => self::STRING,
            'nullable' => true,
        ],
    ];

    public static function getRelationships(): array
    {
        return [];
    }

    public static function getModel(): string
    {
        return CertificateDeliveryMetaEntity::class;
    }
}
