<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Example\Resource;

use Ggs\JsonApiEngine\Document\ResourceRecord;
use Ggs\JsonApiEngine\Example\Entity\DomainLogsEntity;
use Ggs\JsonApiEngine\Example\Entity\DomainsEntity;
use Ggs\JsonApiEngine\Resource\OneToMany;
use Ggs\JsonApiEngine\Resource;
use Ggs\JsonApiEngine\Resource\Insertable;

/**
 * `domain`: таблица `domains` (QUERY2). Связь `logs` (1:N).
 *
 * Можно создавать (`Insertable`): вставку см. `Executor\Insert\InsertResourceWriter`.
 */
final class DomainResource extends Resource implements Insertable
{
    public const string TYPE = 'domain';

    public const string ID = 'id';
    public const string DOMAIN_NAME = 'domain_name';
    public const string IS_PRIMARY = 'is_primary';
    public const string CREATED_AT = 'created_at';
    public const string LOGS = 'logs';

    public const array ATTRIBUTES = [
        self::ID => ['column' => DomainsEntity::ID, 'type' => self::INT, 'constraint' => self::PRIMARY],
        self::DOMAIN_NAME => ['column' => DomainsEntity::DOMAIN_NAME, 'type' => self::STRING],
        self::IS_PRIMARY => ['column' => DomainsEntity::IS_PRIMARY, 'type' => self::BOOL],
        self::CREATED_AT => ['column' => DomainsEntity::CREATED_AT, 'type' => self::DATETIME],
    ];

    public static function relationships(): array
    {
        return [
            // QUERY3: MergeNestJoinIterator `logs`
            self::LOGS => new OneToMany(
                resource: LogResource::class,
                relation: [DomainsEntity::ID, DomainLogsEntity::DOMAIN_ID],
            ),
        ];
    }

    public static function model(): string
    {
        return DomainsEntity::class;
    }

    /**
     * Домен из строки QUERY2: идентификатор — алиас `domain_id`. Служебный ключ `certificate_id` — ключ соединения
     * с сертификатом для HashNestJoinIterator, в JSON не выводится.
     */
    public static function serialize(array $tuple): ResourceRecord
    {
        return new ResourceRecord(
            id: $tuple['domain_id'],
            type: self::TYPE,
            attributes: [
                self::DOMAIN_NAME => $tuple['domain_name'],
                self::IS_PRIMARY => (bool) $tuple['is_primary'],
                self::CREATED_AT => $tuple['created_at'],
            ],
            relationships: [],
            keys: ['certificate_id' => $tuple['certificate_id']],
        );
    }
}
