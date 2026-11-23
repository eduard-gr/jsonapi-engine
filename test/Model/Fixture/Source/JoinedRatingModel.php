<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Model\Fixture\Source;

use Ggs\JsonApiEngine\Model\Join;
use Ggs\JsonApiEngine\Model\LeftJoin;
use Ggs\JsonApiEngine\Test\Model\Fixture\Entity\CertificatesEntity;

/**
 * Неверная модель: чужой источник стоит листом соединения.
 */
final class JoinedRatingModel
{
    public static function getModel(): Join
    {
        return new LeftJoin(
            left: CertificatesEntity::class,
            right: RatingsSource::class,
            relation: [CertificatesEntity::ID, RatingsSource::CERTIFICATE_ID],
        );
    }
}
