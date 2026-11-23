<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Plan\Write\Fixture;

use Ggs\JsonApiEngine\Example\Entity\CertificatesEntity;
use Ggs\JsonApiEngine\Resource;
use Ggs\JsonApiEngine\Resource\OneToMany;
use Ggs\JsonApiEngine\Resource\UpdatableInterface;

/**
 * Сертификат из таблицы примера с детьми в чужом источнике: `notes` (1:N) — `WritableNoteResource`.
 */
final class NotedCertificateResource extends Resource implements UpdatableInterface
{
    public const string TYPE = 'certificate';
    public const string ID = 'id';
    public const string CODE = 'code';
    public const string NOTES = 'notes';

    public const array ATTRIBUTES = [
        self::ID => ['column' => CertificatesEntity::ID, 'type' => self::INT, 'constraint' => self::PRIMARY],
        self::CODE => ['column' => CertificatesEntity::CODE, 'type' => self::STRING],
    ];

    #[\Override]
    public static function getRelationships(): array
    {
        return [
            self::NOTES => new OneToMany(
                resource: WritableNoteResource::class,
                relation: [CertificatesEntity::ID, WritableNotesSource::CERTIFICATE_ID],
            ),
        ];
    }

    public static function getModel(): string
    {
        return CertificatesEntity::class;
    }
}
