<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Executor\Write\Fixture;

use Ggs\JsonApiEngine\Document\ResourceRecord;
use Ggs\JsonApiEngine\Example\Entity\CertificatesEntity;
use Ggs\JsonApiEngine\Resource;

/**
 * Ресурс для ResourceDeserializerTest: объявил `serialize()`, но не реализует `SerializeInterface` — такой метод никто
 * не вызовет, `Resource::validate()` обязан это заметить.
 */
final class SerializeWithoutInterfaceResource extends Resource
{
    public const string TYPE = 'forgotten-certificate';

    public const string ID = 'id';

    public const array ATTRIBUTES = [
        self::ID => ['column' => CertificatesEntity::ID, 'type' => self::INT, 'constraint' => self::PRIMARY],
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

    /**
     * @param array<string, mixed> $tuple
     */
    public function serialize(array $tuple): ResourceRecord
    {
        return new ResourceRecord(
            id: (int) $tuple[CertificatesEntity::ID],
            type: self::TYPE,
            attributes: [],
            relationships: [],
        );
    }
}
