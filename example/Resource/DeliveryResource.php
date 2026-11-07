<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Example\Resource;

use Ggs\JsonApiEngine\Document\ResourceRecord;
use Ggs\JsonApiEngine\Example\Entity\CertificateDeliveryMetaEntity;
use Ggs\JsonApiEngine\Operator;
use Ggs\JsonApiEngine\Resource;

/**
 * `delivery`: таблица `certificate_delivery_meta`, идентификатор — её первичный ключ `certificate_id`.
 */
final class DeliveryResource extends Resource
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

    public static function relationships(): array
    {
        return [];
    }

    public static function model(): string
    {
        return CertificateDeliveryMetaEntity::class;
    }

    /**
     * Доставка из строки QUERY1: идентификатор — алиас `meta_id` (`certificate_delivery_meta.certificate_id`).
     * `meta_id` NULL — LEFT JOIN не нашёл запись доставки: ресурса нет.
     */
    public static function serialize(array $tuple): ?ResourceRecord
    {
        if ($tuple['meta_id'] === null) {
            return null;
        }

        return new ResourceRecord(
            id: $tuple['meta_id'],
            type: self::TYPE,
            attributes: [
                self::DELIVERY_CHANNEL => $tuple['delivery_channel'],
                self::IS_SENT => (bool) $tuple['is_sent'],
                self::EXTERNAL_PROVIDER_ID => $tuple['external_provider_id'],
            ],
            relationships: [],
        );
    }
}
