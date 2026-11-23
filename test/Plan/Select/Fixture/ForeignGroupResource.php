<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Plan\Select\Fixture;

use Ggs\JsonApiEngine\Example\Entity\CertificatesEntity;
use Ggs\JsonApiEngine\Resource;
use Ggs\JsonApiEngine\Resource\OneToMany;

/**
 * `group` над чужим источником `ForeignGroupsSource` — родитель сертификатов из таблицы.
 */
final class ForeignGroupResource extends Resource
{
    public const string TYPE = 'group';
    public const string ID = 'id';
    public const string NAME = 'name';
    public const string CERTIFICATES = 'certificates';

    public const array ATTRIBUTES = [
        self::ID => ['column' => ForeignGroupsSource::ID, 'type' => self::INT, 'constraint' => self::PRIMARY],
        self::NAME => ['column' => ForeignGroupsSource::NAME, 'type' => self::STRING],
    ];

    #[\Override]
    public static function getRelationships(): array
    {
        return [
            self::CERTIFICATES => new OneToMany(
                resource: ForeignCertificateResource::class,
                relation: [ForeignGroupsSource::ID, CertificatesEntity::PROVIDER_ID],
            ),
        ];
    }

    public static function getModel(): string
    {
        return ForeignGroupsSource::class;
    }
}
