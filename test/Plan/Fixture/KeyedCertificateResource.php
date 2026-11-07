<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Plan\Fixture;

use Ggs\JsonApiEngine\Model\Join;
use Ggs\JsonApiEngine\Operator;
use Ggs\JsonApiEngine\Resource;
use Ggs\JsonApiEngine\Test\Model\Fixture\CertificateModel;
use Ggs\JsonApiEngine\Test\Model\Fixture\Entity\CertificatesEntity;
use Ggs\JsonApiEngine\Test\Model\Fixture\Model\KeyAlgorithmsEntity;
use Ggs\JsonApiEngine\Test\Model\Fixture\Model\KeysEntity;

/**
 * Ресурс над многотабличной моделью `CertificateModel` (certificates LEFT JOIN csr, keys, key_algorithms) для
 * SelectWhereTest: атрибуты из внутренних LEFT-таблиц модели с filter и sort.
 */
final class KeyedCertificateResource extends Resource
{
    public const string TYPE = 'keyed_certificate';

    public const string ID = 'id';
    public const string STATUS = 'status';
    public const string KEY_SIZE = 'key_size';
    public const string KEY_ALGORITHM = 'key_algorithm';

    public const array ATTRIBUTES = [
        self::ID => ['column' => CertificatesEntity::ID, 'type' => self::INT, 'constraint' => self::PRIMARY],
        self::STATUS => ['column' => CertificatesEntity::STATUS, 'type' => self::STRING],
        self::KEY_SIZE => ['column' => KeysEntity::KEY_SIZE, 'type' => self::INT, 'sort' => true],
        self::KEY_ALGORITHM => [
            'column' => KeyAlgorithmsEntity::KEY_ALGORITHM,
            'type' => self::STRING,
            'filter' => [Operator::Eq, Operator::In],
        ],
    ];

    public static function model(): Join
    {
        return CertificateModel::model();
    }
}
