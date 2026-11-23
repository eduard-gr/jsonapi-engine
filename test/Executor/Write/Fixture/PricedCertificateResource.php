<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Executor\Write\Fixture;

use Ggs\JsonApiEngine\Document\ResourceRecord;
use Ggs\JsonApiEngine\Example\Entity\CertificatesEntity;
use Ggs\JsonApiEngine\Resource;
use Ggs\JsonApiEngine\Resource\DeserializeInterface;
use Ggs\JsonApiEngine\Resource\InvalidAttributeException;
use Ggs\JsonApiEngine\Resource\SerializeInterface;
use Ggs\JsonApiEngine\Resource\UpdatableInterface;

/**
 * Ресурс для ResourceDeserializerTest: сертификат с составным атрибутом `price` из двух колонок таблицы
 * `certificates` — `{"amount": "10.00", "currency": "RUB"}` ↔ `nominal`, `currency`. По описанию составной атрибут
 * не читается и не пишется, поэтому ресурс реализует оба интерфейса ручной обработки: `SerializeInterface` собирает
 * `price` из строки, `DeserializeInterface` раскладывает его обратно по колонкам.
 */
final class PricedCertificateResource extends Resource implements UpdatableInterface, SerializeInterface, DeserializeInterface
{
    public const string TYPE = 'priced-certificate';

    public const string ID = 'id';
    public const string CODE = 'code';
    public const string PRICE = 'price';

    public const array ATTRIBUTES = [
        self::ID => ['column' => CertificatesEntity::ID, 'type' => self::INT, 'constraint' => self::PRIMARY],
        self::CODE => ['column' => CertificatesEntity::CODE, 'type' => self::STRING],
        self::PRICE => ['columns' => [CertificatesEntity::NOMINAL, CertificatesEntity::CURRENCY]],
    ];

    #[\Override]
    public static function getRelationships(): array
    {
        return [];
    }

    public static function getModel(): string
    {
        return CertificatesEntity::class;
    }

    #[\Override]
    public function serialize(array $tuple, array $fields): ResourceRecord
    {
        $attributes = [];
        foreach ($fields as $name) {
            if ($name === self::PRICE) {
                $attributes[self::PRICE] = [
                    'amount' => number_format((float) $tuple[CertificatesEntity::NOMINAL], 2, '.', ''),
                    'currency' => $tuple[CertificatesEntity::CURRENCY],
                ];
                continue;
            }
            $attributes[$name] = $tuple[self::ATTRIBUTES[$name]['column']];
        }

        return new ResourceRecord(
            id: (int) $tuple[CertificatesEntity::ID],
            type: self::TYPE,
            attributes: $attributes,
            relationships: [],
        );
    }

    /**
     * В строку идёт только присланное: `code` — в свою колонку, `price` — в две. Контекст операции: при создании
     * без `price` валюта получает значение по умолчанию — правило, которое действует только для вставки.
     */
    #[\Override]
    public function deserialize(array $attributes, array $references, bool $is_insert, ?int $id, array $link): array
    {
        $row = [];
        if ($is_insert && !\array_key_exists(self::PRICE, $attributes)) {
            // валюта нового сертификата, если цена не прислана (строковая константа класса считалась бы именем атрибута)
            $row[CertificatesEntity::CURRENCY] = 'RUB';
        }
        if (\array_key_exists(self::CODE, $attributes)) {
            $row[CertificatesEntity::CODE] = $attributes[self::CODE];
        }
        if (\array_key_exists(self::PRICE, $attributes)) {
            $price = $attributes[self::PRICE];
            // Форму значения парсер не проверяет: плохое значение — ошибка клиента, а не ресурса
            if (!\is_array($price) || !isset($price['amount'], $price['currency'])) {
                throw new InvalidAttributeException(
                    attribute: self::PRICE,
                    detail: 'price must be an object with amount and currency',
                );
            }
            $row[CertificatesEntity::NOMINAL] = $price['amount'];
            $row[CertificatesEntity::CURRENCY] = $price['currency'];
        }

        return $row;
    }
}
