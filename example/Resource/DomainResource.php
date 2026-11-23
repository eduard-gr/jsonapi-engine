<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Example\Resource;

use Ggs\JsonApiEngine\Example\Entity\DomainLogsEntity;
use Ggs\JsonApiEngine\Example\Entity\DomainsEntity;
use Ggs\JsonApiEngine\Resource\OneToMany;
use Ggs\JsonApiEngine\Resource;
use Ggs\JsonApiEngine\Resource\DeletableInterface;
use Ggs\JsonApiEngine\Resource\InsertableInterface;
use Ggs\JsonApiEngine\Resource\UpdatableInterface;

/**
 * `domain`: таблица `domains` (QUERY2). Связь `logs` (1:N).
 *
 * Можно создавать (`InsertableInterface`): вставку см. `Executor\Insert\InsertResourceWriter`; изменять и удалять
 * (`UpdatableInterface`, `DeletableInterface`).
 */
final class DomainResource extends Resource implements InsertableInterface, UpdatableInterface, DeletableInterface
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

    public static function getRelationships(): array
    {
        return [
            // QUERY3: MergeNestJoinReader `logs`
            self::LOGS => new OneToMany(
                resource: LogResource::class,
                relation: [DomainsEntity::ID, DomainLogsEntity::DOMAIN_ID],
            ),
        ];
    }

    public static function getModel(): string
    {
        return DomainsEntity::class;
    }
}
