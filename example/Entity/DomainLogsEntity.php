<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Example\Entity;

use Ggs\JsonApiEngine\Model\Entity;

/**
 * Таблица `domain_logs`: логи домена, 1:N от `domains` (у домена может не быть ни одного).
 */
final class DomainLogsEntity extends Entity
{
    public const string TABLE = 'domain_logs';

    public const string ID = 'id';
    public const string DOMAIN_ID = 'domain_id';
    public const string LOG_LEVEL = 'log_level';
    public const string MESSAGE = 'message';
    public const string CREATED_AT = 'created_at';

    public const array COLUMNS = [
        self::ID => ['type' => self::INT, 'constraint' => self::PRIMARY],
        self::DOMAIN_ID => [
            'type' => self::INT,
            'constraint' => self::FOREIGN,
            'references' => DomainsEntity::class,
        ],
        self::LOG_LEVEL => ['type' => self::STRING],
        self::MESSAGE => ['type' => self::STRING],
        self::CREATED_AT => ['type' => self::DATETIME],
    ];
}
