<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Example\Entity;

use Ggs\JsonApiEngine\Model\Entity;

/**
 * Таблица `certificates`: главная таблица.
 *
 * `nominal` — `DECIMAL(10, 2)`: в словаре типов нет десятичного, а `FLOAT` терял бы точность денежной суммы,
 * поэтому `STRING` — точное значение строкой, как pg-wrapper отдаёт `numeric`.
 */
final class CertificatesEntity extends Entity
{
    public const string TABLE = 'certificates';

    public const string ID = 'id';
    public const string PROVIDER_ID = 'provider_id';
    public const string CODE = 'code';
    public const string NOMINAL = 'nominal';
    public const string CURRENCY = 'currency';
    public const string STATUS = 'status';
    public const string CREATED_AT = 'created_at';

    public const array COLUMNS = [
        self::ID => ['type' => self::INT, 'constraint' => self::PRIMARY],
        self::PROVIDER_ID => [
            'type' => self::INT,
            'constraint' => self::FOREIGN,
            'references' => ProvidersEntity::class,
        ],
        self::CODE => ['type' => self::STRING, 'unique' => true],
        self::NOMINAL => ['type' => self::STRING],
        self::CURRENCY => ['type' => self::STRING],
        self::STATUS => ['type' => self::STRING],
        self::CREATED_AT => ['type' => self::DATETIME],
    ];
}
