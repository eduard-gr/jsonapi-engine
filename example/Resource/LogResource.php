<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Example\Resource;

use Ggs\JsonApiEngine\Example\Entity\DomainLogsEntity;
use Ggs\JsonApiEngine\Resource;

/**
 * `log`: таблица `domain_logs` (QUERY3).
 */
final class LogResource extends Resource
{
    public const string TYPE = 'log';

    public const string ID = 'id';
    public const string LOG_LEVEL = 'log_level';
    public const string MESSAGE = 'message';
    public const string CREATED_AT = 'created_at';

    public const array ATTRIBUTES = [
        self::ID => ['column' => DomainLogsEntity::ID, 'type' => self::INT, 'constraint' => self::PRIMARY],
        self::LOG_LEVEL => ['column' => DomainLogsEntity::LOG_LEVEL, 'type' => self::STRING],
        self::MESSAGE => ['column' => DomainLogsEntity::MESSAGE, 'type' => self::STRING],
        self::CREATED_AT => ['column' => DomainLogsEntity::CREATED_AT, 'type' => self::DATETIME],
    ];

    public static function getRelationships(): array
    {
        return [];
    }

    public static function getModel(): string
    {
        return DomainLogsEntity::class;
    }
}
