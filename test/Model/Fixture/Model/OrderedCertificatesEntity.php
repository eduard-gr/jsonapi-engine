<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Model\Fixture\Model;

use Ggs\JsonApiEngine\Model\Entity;

/**
 * Сертификаты со ссылкой на заказ.
 */
final class OrderedCertificatesEntity extends Entity
{
    public const string TABLE = 'certificates';

    public const string ID = 'id';
    public const string STATUS = 'status';
    public const string ORDER_ID = 'order_id';

    public const array COLUMNS = [
        self::ID => ['type' => self::INT, 'constraint' => self::PRIMARY],
        self::STATUS => ['type' => self::STRING],
        self::ORDER_ID => ['type' => self::INT, 'constraint' => self::FOREIGN, 'references' => OrdersEntity::class],
    ];
}
