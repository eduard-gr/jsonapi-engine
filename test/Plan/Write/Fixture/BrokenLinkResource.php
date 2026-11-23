<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Plan\Write\Fixture;

use Ggs\JsonApiEngine\Example\Entity\CertificatesEntity;
use Ggs\JsonApiEngine\Example\Resource\LogResource;
use Ggs\JsonApiEngine\Resource;
use Ggs\JsonApiEngine\Resource\OneToMany;

/**
 * Ресурс для WritePlannerTest с ошибкой в описании связи: дети `logs` привязаны колонкой `certificate_id`, которой
 * в модели `LogResource` (таблица `domain_logs`) нет. Проверить по ней, что лог принадлежит сертификату, нельзя —
 * планировщик записи обязан отказать при построении плана.
 */
final class BrokenLinkResource extends Resource
{
    public const string TYPE = 'broken-link';

    public const string ID = 'id';
    public const string LOGS = 'logs';

    public const array ATTRIBUTES = [
        self::ID => ['column' => CertificatesEntity::ID, 'type' => self::INT, 'constraint' => self::PRIMARY],
    ];

    #[\Override]
    public static function getRelationships(): array
    {
        return [
            self::LOGS => new OneToMany(
                resource: LogResource::class,
                relation: [CertificatesEntity::ID, 'certificate_id'],
            ),
        ];
    }

    public static function getModel(): string
    {
        return CertificatesEntity::class;
    }
}
