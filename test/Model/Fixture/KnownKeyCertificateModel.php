<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Model\Fixture;

use Ggs\JsonApiEngine\Model\InnerJoin;
use Ggs\JsonApiEngine\Model\Join;
use Ggs\JsonApiEngine\Model\LeftJoin;
use Ggs\JsonApiEngine\Test\Model\Fixture\Entity\CertificatesEntity;
use Ggs\JsonApiEngine\Test\Model\Fixture\Model\KeyAlgorithmsEntity;
use Ggs\JsonApiEngine\Test\Model\Fixture\Model\KeysEntity;

/**
 * Сертификаты и, если есть, ключ с известным алгоритмом: INNER внутри правого поддерева LEFT, в скобках.
 */
final class KnownKeyCertificateModel
{
    public static function model(): Join
    {
        return new LeftJoin(
            left: CertificatesEntity::class,
            right: new InnerJoin(
                left: KeysEntity::class,
                right: KeyAlgorithmsEntity::class,
                relation: [KeysEntity::ALGORITHM_ID, KeyAlgorithmsEntity::KEY_ALGORITHM_ID],
            ),
            relation: [CertificatesEntity::ID, KeysEntity::CERTIFICATE_ID],
        );
    }
}
