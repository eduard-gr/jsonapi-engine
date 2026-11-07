<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Model\Fixture\Entity;

use Ggs\JsonApiEngine\Model\Entity;

final class CertificatesEntity extends Entity
{
    public const string TABLE = 'certificates';

    public const string ID = 'id';
    public const string DOMAIN_NAME = 'domain_name';
    public const string STATUS = 'status';
    public const string EXPIRES_AT = 'expires_at';
    public const string DATE_CREATED = 'date_created';
    public const string PROVIDER_ID = 'provider_id';
    public const string USER_ID = 'user_id';

    public const array COLUMNS = [
        self::ID => ['type' => self::INT, 'constraint' => self::PRIMARY],
        self::DOMAIN_NAME => ['type' => self::STRING],
        self::STATUS => ['type' => self::STRING],
        self::EXPIRES_AT => ['type' => self::DATE, 'nullable' => true],
        self::DATE_CREATED => ['type' => self::DATETIME],
        self::PROVIDER_ID => [
            'type' => self::INT,
            'constraint' => self::FOREIGN,
            'references' => ProvidersEntity::class,
            'nullable' => true,
        ],
        self::USER_ID => ['type' => self::INT],
    ];
}
