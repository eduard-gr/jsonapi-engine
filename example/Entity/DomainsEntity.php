<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Example\Entity;

use Ggs\JsonApiEngine\Model\Entity;

/**
 * Таблица `domains`: домены сертификата, 1:N от `certificates`.
 */
final class DomainsEntity extends Entity
{
    public const string TABLE = 'domains';

    public const string ID = 'id';
    public const string CERTIFICATE_ID = 'certificate_id';
    public const string DOMAIN_NAME = 'domain_name';
    public const string IS_PRIMARY = 'is_primary';
    public const string CREATED_AT = 'created_at';

    public const array COLUMNS = [
        self::ID => ['type' => self::INT, 'constraint' => self::PRIMARY],
        self::CERTIFICATE_ID => [
            'type' => self::INT,
            'constraint' => self::FOREIGN,
            'references' => CertificatesEntity::class,
        ],
        self::DOMAIN_NAME => ['type' => self::STRING, 'unique' => true],
        self::IS_PRIMARY => ['type' => self::BOOL],
        self::CREATED_AT => ['type' => self::DATETIME],
    ];
}
