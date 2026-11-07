<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Model\Fixture\Model;

use Ggs\JsonApiEngine\Model\Entity;

final class CustomersEntity extends Entity
{
    public const string TABLE = 'customers';

    public const string ID = 'id';
    public const string CUSTOMER_NAME = 'customer_name';

    public const array COLUMNS = [
        self::ID => ['type' => self::INT, 'constraint' => self::PRIMARY],
        self::CUSTOMER_NAME => ['column' => 'name', 'type' => self::STRING],
    ];
}
