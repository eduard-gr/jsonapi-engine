<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Model\Fixture\Model;

use Ggs\JsonApiEngine\Model\DatabaseEntitySource;

final class KeyAlgorithmsEntity extends DatabaseEntitySource
{
    public const string TABLE = 'key_algorithms';

    public const string KEY_ALGORITHM_ID = 'key_algorithm_id';
    public const string KEY_ALGORITHM = 'key_algorithm';

    public const array COLUMNS = [
        self::KEY_ALGORITHM_ID => ['column' => 'id', 'type' => self::INT, 'constraint' => self::PRIMARY],
        self::KEY_ALGORITHM => ['column' => 'name', 'type' => self::STRING],
    ];
}
