<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Example\Resource;

use Ggs\JsonApiEngine\Document\ResourceRecord;
use Ggs\JsonApiEngine\Example\Entity\CertificateDetailsEntity;
use Ggs\JsonApiEngine\Resource;
use Ggs\JsonApiEngine\Resource\Insertable;

/**
 * `detail`: таблица `certificate_details`, идентификатор — её первичный ключ `certificate_id`.
 *
 * Можно создавать (`Insertable`): вставку см. `Executor\Insert\InsertResourceWriter`.
 */
final class DetailResource extends Resource implements Insertable
{
    public const string TYPE = 'detail';

    public const string ID = 'id';
    public const string BUYER_EMAIL = 'buyer_email';
    public const string RECIPIENT_NAME = 'recipient_name';
    public const string RECIPIENT_EMAIL = 'recipient_email';
    public const string GREETING_TEXT = 'greeting_text';
    public const string DESIGN_TEMPLATE_ID = 'design_template_id';

    public const array ATTRIBUTES = [
        self::ID => [
            'column' => CertificateDetailsEntity::CERTIFICATE_ID,
            'type' => self::INT,
            'constraint' => self::PRIMARY,
        ],
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

    public static function relationships(): array
    {
        return [];
    }

    public static function model(): string
    {
        return CertificateDetailsEntity::class;
    }

    /**
     * Детали из строки QUERY1: идентификатор — алиас `detail_id` (`certificate_details.certificate_id`).
     */
    public static function serialize(array $tuple): ResourceRecord
    {
        return new ResourceRecord(
            id: $tuple['detail_id'],
            type: self::TYPE,
            attributes: [
                self::BUYER_EMAIL => $tuple['buyer_email'],
                self::RECIPIENT_NAME => $tuple['recipient_name'],
                self::RECIPIENT_EMAIL => $tuple['recipient_email'],
                self::GREETING_TEXT => $tuple['greeting_text'],
                self::DESIGN_TEMPLATE_ID => $tuple['design_template_id'],
            ],
            relationships: [],
        );
    }
}
