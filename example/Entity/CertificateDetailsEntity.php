<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Example\Entity;

use Ggs\JsonApiEngine\Model\Entity;

/**
 * Таблица `certificate_details`: детали сертификата, 1:1 с `certificates`.
 *
 * `certificate_id` — первичный ключ и одновременно внешний ключ на `certificates.id`. Колонка описывает только одно
 * ограничение, поэтому записано `PRIMARY`; связь с сертификатом задаётся в модели ресурса.
 */
final class CertificateDetailsEntity extends Entity
{
    public const string TABLE = 'certificate_details';

    public const string CERTIFICATE_ID = 'certificate_id';
    public const string BUYER_EMAIL = 'buyer_email';
    public const string RECIPIENT_NAME = 'recipient_name';
    public const string RECIPIENT_EMAIL = 'recipient_email';
    public const string GREETING_TEXT = 'greeting_text';
    public const string DESIGN_TEMPLATE_ID = 'design_template_id';

    public const array COLUMNS = [
        self::CERTIFICATE_ID => ['type' => self::INT, 'constraint' => self::PRIMARY],
        self::BUYER_EMAIL => ['type' => self::STRING],
        self::RECIPIENT_NAME => ['type' => self::STRING, 'nullable' => true],
        self::RECIPIENT_EMAIL => ['type' => self::STRING, 'nullable' => true],
        self::GREETING_TEXT => ['type' => self::STRING, 'nullable' => true],
        self::DESIGN_TEMPLATE_ID => ['type' => self::INT, 'nullable' => true],
    ];
}
