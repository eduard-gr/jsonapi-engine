<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Example\Resource;

use Ggs\JsonApiEngine\Document\ResourceRecord;
use Ggs\JsonApiEngine\Example\Entity\ProvidersEntity;
use Ggs\JsonApiEngine\Resource;

/**
 * `provider`: справочник `providers` (QUERY4), загружается PreloadIterator.
 */
final class ProviderResource extends Resource
{
    public const string TYPE = 'provider';

    public const string ID = 'id';
    public const string NAME = 'name';
    public const string WEBSITE = 'website';
    public const string CREATED_AT = 'created_at';

    public const array ATTRIBUTES = [
        self::ID => ['column' => ProvidersEntity::ID, 'type' => self::INT, 'constraint' => self::PRIMARY],
        self::NAME => ['column' => ProvidersEntity::NAME, 'type' => self::STRING],
        self::WEBSITE => ['column' => ProvidersEntity::WEBSITE, 'type' => self::STRING, 'nullable' => true],
        self::CREATED_AT => ['column' => ProvidersEntity::CREATED_AT, 'type' => self::DATETIME],
    ];

    public static function relationships(): array
    {
        return [];
    }

    public static function model(): string
    {
        return ProvidersEntity::class;
    }

    /**
     * Провайдер из строки QUERY4: идентификатор — алиас `provider_id`.
     */
    public static function serialize(array $tuple): ResourceRecord
    {
        return new ResourceRecord(
            id: $tuple['provider_id'],
            type: self::TYPE,
            attributes: [
                self::NAME => $tuple['name'],
                self::WEBSITE => $tuple['website'],
                self::CREATED_AT => $tuple['created_at'],
            ],
            relationships: [],
        );
    }
}
