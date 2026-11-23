<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Executor\Select\Fixture;

use Ggs\JsonApiEngine\Document\ResourceRecord;
use Ggs\JsonApiEngine\Resource;
use Ggs\JsonApiEngine\Resource\SerializeInterface;

/**
 * Ресурс для SerializeReaderTest без модели, с ручной сериализацией: свой `serialize()` выбирает тип ресурса по
 * колонке `kind` (её нет среди атрибутов — заказана в `getRequiredColumns()`), выводит только атрибуты из
 * `$fields`, и отдаёт null для `kind = 'hidden'`. Ресурс — экземпляр с зависимостью конструктора
 * (префикс типа): метод не статический и пользуется ею. Вызовы запоминаются в `$calls` объекта.
 */
final class ManualVoucherResource extends Resource implements SerializeInterface
{
    public const string TYPE = 'voucher';

    public const string ID = 'id';
    public const string AMOUNT = 'amount';
    public const string ACTIVE = 'active';

    public const array ATTRIBUTES = [
        self::ID => ['column' => 'id', 'type' => self::INT, 'constraint' => self::PRIMARY],
        self::AMOUNT => ['column' => 'amount', 'type' => self::INT],
        self::ACTIVE => ['column' => 'active', 'type' => self::BOOL],
    ];

    /** @var list<array<string, mixed>> строки, с которыми вызывался serialize() */
    public array $calls = [];

    /** @var list<string> fields последнего вызова serialize() */
    public array $fields = [];

    /**
     * @param string $type_prefix зависимость ресурса: начало JSON:API type, к нему дописывается `kind` строки
     */
    public function __construct(
        private readonly string $type_prefix,
    ) {}

    #[\Override]
    public static function getRequiredColumns(array $fields): array
    {
        return ['kind'];
    }

    /**
     * @param array<string, mixed> $tuple  колонка модели → значение
     * @param list<string>         $fields запрошенные атрибуты
     */
    #[\Override]
    public function serialize(array $tuple, array $fields): ?ResourceRecord
    {
        $this->calls[] = $tuple;
        $this->fields = $fields;
        if ($tuple['kind'] === 'hidden') {
            return null;
        }

        $attributes = [];
        foreach ($fields as $attribute) {
            $attributes[$attribute] = $tuple[$attribute];
        }

        return new ResourceRecord(
            id: (int) $tuple['id'],
            type: $this->type_prefix . $tuple['kind'],
            attributes: $attributes,
            relationships: [],
        );
    }
}
