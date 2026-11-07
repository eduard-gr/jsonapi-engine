<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Model\Fixture;

use Ggs\JsonApiEngine\Model\InnerJoin;
use Ggs\JsonApiEngine\Model\Join;
use Ggs\JsonApiEngine\Model\LeftJoin;
use Ggs\JsonApiEngine\Test\Model\Fixture\Model\CustomersEntity;
use Ggs\JsonApiEngine\Test\Model\Fixture\Model\OrderedCertificatesEntity;
use Ggs\JsonApiEngine\Test\Model\Fixture\Model\OrdersEntity;

/**
 * Сертификаты, у которых есть заказ (INNER), и покупатель заказа (LEFT).
 */
final class ActiveCertificateModel
{
    public static function model(): Join
    {
        return new LeftJoin(
            left: new InnerJoin(
                left: OrderedCertificatesEntity::class,
                right: OrdersEntity::class,
                relation: [OrderedCertificatesEntity::ORDER_ID, OrdersEntity::ID],
            ),
            right: CustomersEntity::class,
            relation: [OrdersEntity::CUSTOMER_ID, CustomersEntity::ID],
        );
    }
}
