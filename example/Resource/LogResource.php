<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Example\Resource;

use Ggs\JsonApiEngine\Document\ResourceRecord;
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

    public static function relationships(): array
    {
        return [];
    }

    public static function model(): string
    {
        return DomainLogsEntity::class;
    }

    /**
     * Лог из строки QUERY3: идентификатор — алиас `log_id`. Служебный ключ `domain_id` — ключ соединения
     * с доменом для MergeNestJoinIterator, в JSON не выводится.
     */
    public static function serialize(array $tuple): ResourceRecord
    {
        return new ResourceRecord(
            id: $tuple['log_id'],
            type: self::TYPE,
            attributes: [
                self::LOG_LEVEL => $tuple['log_level'],
                self::MESSAGE => $tuple['message'],
                self::CREATED_AT => $tuple['created_at'],
            ],
            relationships: [],
            keys: ['domain_id' => $tuple['domain_id']],
        );
    }
}
