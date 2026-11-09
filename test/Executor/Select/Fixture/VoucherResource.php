<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Executor\Select\Fixture;

use Ggs\JsonApiEngine\Example\Resource\ProviderResource;
use Ggs\JsonApiEngine\Resource;
use Ggs\JsonApiEngine\Resource\ManyToOne;

/**
 * Ресурс для SerializeIteratorTest без модели: составной атрибут `validity` (две колонки, свой метод),
 * атрибуты с приведением по type и связь по внешнему ключу `issuer` (ManyToOne).
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

    public static function relationships(): array
    {
        return [
            self::ISSUER => new ManyToOne(resource: ProviderResource::class, relation: ['issuer_id', 'id']),
        ];
    }

    /**
     * @param array<string, mixed> $values `valid_from`, `valid_to`
     */
    public static function serializeValidity(array $values): array
    {
        return ['from' => $values['valid_from'], 'to' => $values['valid_to']];
    }
}
