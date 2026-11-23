<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Example\Resource;

use Ggs\JsonApiEngine\Example\Entity\CertificateDetailsEntity;
use Ggs\JsonApiEngine\Resource;
use Ggs\JsonApiEngine\Resource\InsertableInterface;

/**
 * `detail`: таблица `certificate_details`, идентификатор — её первичный ключ `certificate_id`.
 *
 * Можно создавать (`InsertableInterface`): вставку см. `Executor\Insert\InsertResourceWriter`.
 */
final class DetailResource extends Resource implements InsertableInterface
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

    public static function getRelationships(): array
    {
        return [];
    }

    public static function getModel(): string
    {
        return CertificateDetailsEntity::class;
    }
}
