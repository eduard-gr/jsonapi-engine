<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Model\Fixture\Model;

use Ggs\JsonApiEngine\Model\Entity;

/**
 * Ключ сертификата с алгоритмом из справочника `key_algorithms`.
 */
final class KeysEntity extends Entity
{
    public const string TABLE = 'certificate_keys';

    public const string KEY_ID = 'key_id';
    public const string CERTIFICATE_ID = 'certificate_id';
    public const string KEY_SIZE = 'key_size';
    public const string ALGORITHM_ID = 'algorithm_id';

    public const array COLUMNS = [
        self::KEY_ID => ['column' => 'id', 'type' => self::INT, 'constraint' => self::PRIMARY],
        self::CERTIFICATE_ID => ['type' => self::INT, 'unique' => true],
        self::KEY_SIZE => ['column' => 'size', 'type' => self::INT],
        self::ALGORITHM_ID => ['type' => self::INT, 'constraint' => self::FOREIGN, 'references' => KeyAlgorithmsEntity::class],
    ];
}
