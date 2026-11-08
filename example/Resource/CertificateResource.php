<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Example\Resource;

use Ggs\JsonApiEngine\Document\ResourceRecord;
use Ggs\JsonApiEngine\Example\Entity\CertificateDeliveryMetaEntity;
use Ggs\JsonApiEngine\Example\Entity\CertificateDetailsEntity;
use Ggs\JsonApiEngine\Example\Entity\CertificatesEntity;
use Ggs\JsonApiEngine\Example\Entity\DomainsEntity;
use Ggs\JsonApiEngine\Example\Entity\ProvidersEntity;
use Ggs\JsonApiEngine\Operator;
use Ggs\JsonApiEngine\Resource\ManyToOne;
use Ggs\JsonApiEngine\Resource\OneToMany;
use Ggs\JsonApiEngine\Resource\OneToOne;
use Ggs\JsonApiEngine\Resource;
use Ggs\JsonApiEngine\Resource\Insertable;

/**
 * `certificate`: таблица `certificates`. Связи: `provider` (внешний ключ), `detail` и `delivery` (1:1, обратная
 * сторона), `domains` (1:N).
 *
 * Можно создавать (`Insertable`): вставку см. `Executor\InsertResourceWriter`.
 */
final class CertificateResource extends Resource implements Insertable
{
    public const string TYPE = 'certificate';

    public const string ID = 'id';
    public const string CODE = 'code';
    public const string NOMINAL = 'nominal';
    public const string CURRENCY = 'currency';
    public const string STATUS = 'status';
    public const string CREATED_AT = 'created_at';
    public const string PROVIDER = 'provider';
    public const string DETAIL = 'detail';
    public const string DELIVERY = 'delivery';
    public const string DOMAINS = 'domains';

    public const array ATTRIBUTES = [
        self::ID => ['column' => CertificatesEntity::ID, 'type' => self::INT, 'constraint' => self::PRIMARY],
        self::CODE => ['column' => CertificatesEntity::CODE, 'type' => self::STRING],
        self::NOMINAL => ['column' => CertificatesEntity::NOMINAL, 'type' => self::STRING],
        self::CURRENCY => ['column' => CertificatesEntity::CURRENCY, 'type' => self::STRING],
        self::STATUS => [
            'column' => CertificatesEntity::STATUS,
            'type' => self::STRING,
            'filter' => [Operator::Eq, Operator::Ne],
        ],
        self::CREATED_AT => [
            'column' => CertificatesEntity::CREATED_AT,
            'type' => self::DATETIME,
            'filter' => [Operator::Gte, Operator::Lt],
        ],
    ];

    public static function relationships(): array
    {
        return [
            // внешний ключ: certificates.provider_id → providers.id, пишется из строки сертификата
            self::PROVIDER => new ManyToOne(
                resource: ProviderResource::class,
                relation: [CertificatesEntity::PROVIDER_ID, ProvidersEntity::ID],
            ),
            // из той же строки QUERY1: SharedRowJoinIterator `detail`
            self::DETAIL => new OneToOne(
                resource: DetailResource::class,
                relation: [CertificatesEntity::ID, CertificateDetailsEntity::CERTIFICATE_ID],
            ),
            // из той же строки QUERY1 (LEFT JOIN): SharedRowJoinIterator `delivery`, без доставки — null
            self::DELIVERY => new OneToOne(
                resource: DeliveryResource::class,
                relation: [CertificatesEntity::ID, CertificateDeliveryMetaEntity::CERTIFICATE_ID],
            ),
            // QUERY2: HashNestJoinIterator `domains`
            self::DOMAINS => new OneToMany(
                resource: DomainResource::class,
                relation: [CertificatesEntity::ID, DomainsEntity::CERTIFICATE_ID],
            ),
        ];
    }

    public static function model(): string
    {
        return CertificatesEntity::class;
    }

    /**
     * `nominal` — с двумя знаками после точки (`1000.00`); общая сериализация SerializeIterator вызывает этот метод
     * вместо приведения по `type`.
     *
     * @param array<string, mixed> $values колонки атрибута: `nominal`
     */
    public static function serializeNominal(array $values): string
    {
        return number_format((float) $values[CertificatesEntity::NOMINAL], 2, '.', '');
    }

    /**
     * Сертификат из строки QUERY1 (`certificate_id`, `code`, …). Связь `provider` — по внешнему ключу, из своей
     * строки: есть всегда, даже если провайдеры не загружаются; `provider_id` NULL — пустая связь.
     */
    public static function serialize(array $tuple): ResourceRecord
    {
        return new ResourceRecord(
            id: $tuple['certificate_id'],
            type: self::TYPE,
            attributes: [
                self::CODE => $tuple['code'],
                self::NOMINAL => self::serializeNominal([CertificatesEntity::NOMINAL => $tuple['nominal']]),
                self::CURRENCY => $tuple['currency'],
                self::STATUS => $tuple['status'],
                self::CREATED_AT => $tuple['created_at'],
            ],
            relationships: [
                // Формат — как у ResourceRecord::identifier(): сначала id (строкой, как требует JSON:API), затем type
                self::PROVIDER => [
                    'data' => $tuple['provider_id'] === null
                        ? null
                        : ['id' => (string) $tuple['provider_id'], 'type' => ProviderResource::TYPE],
                ],
            ],
        );
    }
}
