<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Model\Fixture\Entity;

use Ggs\JsonApiEngine\Model\Entity;

final class ProvidersEntity extends Entity
{
    public const string TABLE = 'providers';

    public const string ID = 'id';
    public const string NAME = 'name';
    public const string API_URL = 'api_url';

    public const array COLUMNS = [
        self::ID => ['type' => self::INT, 'constraint' => self::PRIMARY],
        self::NAME => ['type' => self::STRING],
        self::API_URL => ['type' => self::STRING],
    ];
}
