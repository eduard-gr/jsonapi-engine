<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Model\Fixture;

use Ggs\JsonApiEngine\Model\Join;
use Ggs\JsonApiEngine\Model\LeftJoin;
use Ggs\JsonApiEngine\Test\Model\Fixture\Entity\CertificatesEntity;
use Ggs\JsonApiEngine\Test\Model\Fixture\Model\CsrEntity;
use Ggs\JsonApiEngine\Test\Model\Fixture\Model\KeyAlgorithmsEntity;
use Ggs\JsonApiEngine\Test\Model\Fixture\Model\KeysEntity;

/**
 * Модель данных для тестов `ModelTree`: класс без описания ресурса, только `model()`.
 */
final class CertificateModel
{
    public static function model(): Join
    {
        return new LeftJoin(
            left: new LeftJoin(
                left: new LeftJoin(
                    left: CertificatesEntity::class,
                    right: CsrEntity::class,
                    relation: [CertificatesEntity::ID, CsrEntity::CERTIFICATE_ID],
                ),
                right: KeysEntity::class,
                relation: [CertificatesEntity::ID, KeysEntity::CERTIFICATE_ID],
            ),
            right: KeyAlgorithmsEntity::class,
            relation: [KeysEntity::ALGORITHM_ID, KeyAlgorithmsEntity::KEY_ALGORITHM_ID],
        );
    }
}
