<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Model\Fixture\Entity;

use Ggs\JsonApiEngine\Model\Entity;

final class CertificateKeysEntity extends Entity
{
    public const string TABLE = 'certificate_keys';

    public const string KEY_ID = 'key_id';
    public const string CERTIFICATE_ID = 'certificate_id';
    public const string KEY_ALGORITHM = 'key_algorithm';
    public const string KEY_SIZE = 'key_size';

    public const array COLUMNS = [
        self::KEY_ID => ['column' => 'id', 'type' => self::INT, 'constraint' => self::PRIMARY],
        self::CERTIFICATE_ID => [
            'type' => self::INT,
            'constraint' => self::FOREIGN,
            'references' => CertificatesEntity::class,
            'unique' => true,
        ],
        self::KEY_ALGORITHM => ['column' => 'algorithm', 'type' => self::STRING],
        self::KEY_SIZE => ['column' => 'size', 'type' => self::INT],
    ];
}
