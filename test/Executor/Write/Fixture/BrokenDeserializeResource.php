<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Executor\Write\Fixture;

use Ggs\JsonApiEngine\Example\Entity\CertificatesEntity;
use Ggs\JsonApiEngine\Resource;
use Ggs\JsonApiEngine\Resource\DeserializeInterface;

/**
 * Ресурс для ResourceDeserializerTest: его `deserialize()` отдаёт колонку, которой нет в модели, — ошибка ресурса,
 * которую `ManualResourceDeserializer` обязан заметить.
 */
final class BrokenDeserializeResource extends Resource implements DeserializeInterface
{
    public const string TYPE = 'broken-certificate';

    public const string ID = 'id';
    public const string CODE = 'code';

    public const array ATTRIBUTES = [
        self::ID => ['column' => CertificatesEntity::ID, 'type' => self::INT, 'constraint' => self::PRIMARY],
        self::CODE => ['column' => CertificatesEntity::CODE, 'type' => self::STRING],
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
    public function deserialize(array $attributes, array $references, bool $is_insert, ?int $id, array $link): array
    {
        return ['no_such_column' => 1];
    }
}
