<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Plan\Select\Fixture;

use Ggs\JsonApiEngine\Example\Entity\CertificatesEntity;
use Ggs\JsonApiEngine\Example\Entity\DomainsEntity;
use Ggs\JsonApiEngine\Example\Resource\DomainResource;
use Ggs\JsonApiEngine\Operator;
use Ggs\JsonApiEngine\Resource;
use Ggs\JsonApiEngine\Resource\OneToMany;
use Ggs\JsonApiEngine\Resource\OneToOne;

/**
 * Сертификат из таблицы примера со связями на чужие источники: `score` (1:1), `notes` (1:N) — и на таблицу
 * `domains` (1:N).
 */
final class ForeignCertificateResource extends Resource
{
    public const string TYPE = 'certificate';
    public const string ID = 'id';
    public const string CODE = 'code';
    public const string SCORE = 'score';
    public const string NOTES = 'notes';
    public const string DOMAINS = 'domains';

    public const array ATTRIBUTES = [
        self::ID => ['column' => CertificatesEntity::ID, 'type' => self::INT, 'constraint' => self::PRIMARY, 'filter' => [Operator::Eq, Operator::Lte]],
        self::CODE => ['column' => CertificatesEntity::CODE, 'type' => self::STRING],
    ];

    #[\Override]
    public static function getRelationships(): array
    {
        return [
            self::SCORE => new OneToOne(
                resource: ForeignScoreResource::class,
                relation: [CertificatesEntity::ID, ForeignScoresSource::CERTIFICATE_ID],
            ),
            self::NOTES => new OneToMany(
                resource: ForeignNoteResource::class,
                relation: [CertificatesEntity::ID, ForeignNotesSource::CERTIFICATE_ID],
            ),
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
}
