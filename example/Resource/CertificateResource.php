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
use Ggs\JsonApiEngine\Resource\InsertableInterface;
use Ggs\JsonApiEngine\Resource\SerializeInterface;

/**
 * `certificate`: таблица `certificates`. Связи: `provider` (внешний ключ), `detail` и `delivery` (1:1, обратная
 * сторона), `domains` (1:N).
 *
 * Свой `serialize()` (ручная сериализация, интерфейс `SerializeInterface`) — ради формата `nominal`.
 *
 * Можно создавать (`InsertableInterface`): вставку см. `Executor\Insert\InsertResourceWriter`.
 */
final class CertificateResource extends Resource implements InsertableInterface, SerializeInterface
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

    public static function getRelationships(): array
    {
        return [
            // внешний ключ: certificates.provider_id → providers.id, пишется из строки сертификата
            self::PROVIDER => new ManyToOne(
                resource: ProviderResource::class,
                relation: [CertificatesEntity::PROVIDER_ID, ProvidersEntity::ID],
            ),
            // из той же строки QUERY1: EntitySharedJoinReader `detail`
            self::DETAIL => new OneToOne(
                resource: DetailResource::class,
                relation: [CertificatesEntity::ID, CertificateDetailsEntity::CERTIFICATE_ID],
            ),
            // из той же строки QUERY1 (LEFT JOIN): EntitySharedJoinReader `delivery`, без доставки — null
            self::DELIVERY => new OneToOne(
                resource: DeliveryResource::class,
                relation: [CertificatesEntity::ID, CertificateDeliveryMetaEntity::CERTIFICATE_ID],
            ),
            // QUERY2: HashNestJoinReader `domains`
            self::DOMAINS => new OneToMany(
                resource: DomainResource::class,
                relation: [CertificatesEntity::ID, DomainsEntity::CERTIFICATE_ID],
            ),
        ];
    }

    public static function getModel(): string
    {
        return CertificatesEntity::class;
    }

    /**
     * Ручная сериализация: `nominal` выводится с двумя знаками после точки (`1000.00`), а своего формата у
     * отдельного атрибута нет — ресурс собирает себя целиком. Метод на каждой строке вызывает
     * `ManualSerializeReader` (его вместо `SerializeReader` ставит `ReaderBuilder`); `$tuple` — «колонка
     * модели → значение».
     *
     * Выводится только запрошенное — `$fields`, в его порядке: атрибуты и связь `provider` (по внешнему ключу).
     * Наличие колонки в `$tuple` запросом не считается: `provider_id` бывает в строке и как ключ соединения.
     * Значения — как пришли из базы, без приведения по `type`.
     */
    #[\Override]
    public function serialize(array $tuple, array $fields): ResourceRecord
    {
        $attributes = [];
        $relationships = [];
        foreach ($fields as $name) {
            if ($name === self::PROVIDER) {
                $provider_id = $tuple[CertificatesEntity::PROVIDER_ID];
                $relationships[self::PROVIDER] = [
                    'data' => $provider_id === null ? null : ['id' => (string) (int) $provider_id, 'type' => ProviderResource::TYPE],
                ];
                continue;
            }
            $value = $tuple[self::ATTRIBUTES[$name]['column']];
            $attributes[$name] = $name === self::NOMINAL ? number_format((float) $value, 2, '.', '') : $value;
        }

        return new ResourceRecord(
            id: (int) $tuple[CertificatesEntity::ID],
            type: self::TYPE,
            attributes: $attributes,
            relationships: $relationships,
        );
    }
}
