<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Model\Fixture\Model;

use Ggs\JsonApiEngine\Model\Entity;

final class OrdersEntity extends Entity
{
    public const string TABLE = 'orders';

    public const string ID = 'id';
    public const string ORDER_NUMBER = 'order_number';
    public const string CUSTOMER_ID = 'customer_id';

    public const array COLUMNS = [
        self::ID => ['type' => self::INT, 'constraint' => self::PRIMARY],
        self::ORDER_NUMBER => ['column' => 'number', 'type' => self::STRING],
        self::CUSTOMER_ID => ['type' => self::INT, 'constraint' => self::FOREIGN, 'references' => CustomersEntity::class],
    ];
}
