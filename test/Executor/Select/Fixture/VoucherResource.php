<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Executor\Select\Fixture;

use Ggs\JsonApiEngine\Example\Resource\ProviderResource;
use Ggs\JsonApiEngine\Resource;
use Ggs\JsonApiEngine\Resource\ManyToOne;

/**
 * Ресурс для SerializeReaderTest без модели: атрибуты с приведением по type, связь по внешнему ключу `issuer`
 * (ManyToOne) и составной атрибут `validity` (две колонки) без своего `serialize()` — по описанию его не собрать,
 * запросить его в `fields` — LogicException узла.
 */
final class VoucherResource extends Resource
{
    public const string TYPE = 'voucher';

    public const string ID = 'id';
    public const string AMOUNT = 'amount';
    public const string ACTIVE = 'active';
    public const string VALIDITY = 'validity';
    public const string ISSUER = 'issuer';

    public const array ATTRIBUTES = [
        self::ID => ['column' => 'id', 'type' => self::INT, 'constraint' => self::PRIMARY],
        self::AMOUNT => ['column' => 'amount', 'type' => self::INT],
        self::ACTIVE => ['column' => 'active', 'type' => self::BOOL],
        self::VALIDITY => ['columns' => ['valid_from', 'valid_to']],
    ];

    public static function getRelationships(): array
    {
        return [
            self::ISSUER => new ManyToOne(resource: ProviderResource::class, relation: ['issuer_id', 'id']),
        ];
    }
}
