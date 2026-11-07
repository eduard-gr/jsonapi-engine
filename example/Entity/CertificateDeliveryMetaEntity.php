<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Example\Entity;

use Ggs\JsonApiEngine\Model\Entity;

/**
 * Таблица `certificate_delivery_meta`: доставка сертификата, 1:1 с `certificate_details` (может отсутствовать).
 *
 * `certificate_id` — первичный ключ и одновременно внешний ключ на `certificate_details.certificate_id`. Колонка
 * описывает только одно ограничение, поэтому записано `PRIMARY`; связь задаётся в модели ресурса.
 */
final class CertificateDeliveryMetaEntity extends Entity
{
    public const string TABLE = 'certificate_delivery_meta';

    public const string CERTIFICATE_ID = 'certificate_id';
    public const string DELIVERY_CHANNEL = 'delivery_channel';
    public const string IS_SENT = 'is_sent';
    public const string SENT_AT = 'sent_at';
    public const string EXTERNAL_PROVIDER_ID = 'external_provider_id';
    public const string DELIVERY_ERROR_LOG = 'delivery_error_log';

    public const array COLUMNS = [
        self::CERTIFICATE_ID => ['type' => self::INT, 'constraint' => self::PRIMARY],
        self::DELIVERY_CHANNEL => ['type' => self::STRING],
        self::IS_SENT => ['type' => self::BOOL],
        self::SENT_AT => ['type' => self::DATETIME, 'nullable' => true],
        self::EXTERNAL_PROVIDER_ID => ['type' => self::STRING, 'nullable' => true],
        self::DELIVERY_ERROR_LOG => ['type' => self::STRING, 'nullable' => true],
    ];
}
