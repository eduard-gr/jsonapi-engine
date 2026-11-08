<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Example\Resource;

use Ggs\JsonApiEngine\Example\Entity\CertificateDetailsEntity;
use Ggs\JsonApiEngine\Example\Entity\CertificatesEntity;
use Ggs\JsonApiEngine\Model\LeftJoin;
use Ggs\JsonApiEngine\Resource;
use Ggs\JsonApiEngine\Resource\Insertable;

/**
 * `certificate` из двух таблиц: `LeftJoin(certificates, certificate_details, [id, certificate_id])`; атрибуты деталей
 * (`buyer_email`, …) — атрибуты сертификата, а не отдельный ресурс `detail`. Связи — как у `CertificateResource`,
 * без `detail` (детали внутри модели).
 *
 * Пример ресурса из нескольких таблиц для вставки (`example/insert-document.php`): `TableTreeBuilder` разворачивает
 * модель в `InsertTableJoinWriter(certificates → certificate_details)`, строка деталей не вставляется, если ни одной
 * её колонки не прислали. Для SELECT (`serialize()` по `QUERY1`) не предназначен.
 *
 * Можно создавать (`Insertable`).
 */
final class CertificateWithDetailResource extends Resource implements Insertable
{
    public const string TYPE = CertificateResource::TYPE;

    public const string ID = 'id';
    public const string CODE = 'code';
    public const string NOMINAL = 'nominal';
    public const string CURRENCY = 'currency';
    public const string STATUS = 'status';
    public const string CREATED_AT = 'created_at';
    public const string BUYER_EMAIL = 'buyer_email';
    public const string RECIPIENT_NAME = 'recipient_name';
    public const string RECIPIENT_EMAIL = 'recipient_email';
    public const string GREETING_TEXT = 'greeting_text';
    public const string DESIGN_TEMPLATE_ID = 'design_template_id';
    public const string PROVIDER = 'provider';
    public const string DELIVERY = 'delivery';
    public const string DOMAINS = 'domains';

    public const array ATTRIBUTES = [
        ...CertificateResource::ATTRIBUTES,
        self::BUYER_EMAIL => ['column' => CertificateDetailsEntity::BUYER_EMAIL, 'type' => self::STRING],
        self::RECIPIENT_NAME => [
            'column' => CertificateDetailsEntity::RECIPIENT_NAME,
            'type' => self::STRING,
            'nullable' => true,
        ],
        self::RECIPIENT_EMAIL => [
            'column' => CertificateDetailsEntity::RECIPIENT_EMAIL,
            'type' => self::STRING,
            'nullable' => true,
        ],
        self::GREETING_TEXT => [
            'column' => CertificateDetailsEntity::GREETING_TEXT,
            'type' => self::STRING,
            'nullable' => true,
        ],
        self::DESIGN_TEMPLATE_ID => [
            'column' => CertificateDetailsEntity::DESIGN_TEMPLATE_ID,
            'type' => self::INT,
            'nullable' => true,
        ],
    ];

    #[\Override]
    public static function relationships(): array
    {
        $relationships = CertificateResource::relationships();
        unset($relationships[CertificateResource::DETAIL]);

        return $relationships;
    }

    public static function model(): LeftJoin
    {
        return new LeftJoin(
            left: CertificatesEntity::class,
            right: CertificateDetailsEntity::class,
            relation: [CertificatesEntity::ID, CertificateDetailsEntity::CERTIFICATE_ID],
        );
    }
}
