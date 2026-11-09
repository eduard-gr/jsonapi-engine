<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Executor\Insert\Fixture;

use Ggs\JsonApiEngine\Model\Entity;

/**
 * Таблица `vouchers` для OptionalParentTest: `vendor_id` ссылается на `vendors` и может быть NULL (ваучер без продавца).
 */
final class VouchersEntity extends Entity
{
    public const string TABLE = 'vouchers';

    public const string ID = 'id';
    public const string VENDOR_ID = 'vendor_id';
    public const string CODE = 'code';

    public const array COLUMNS = [
        self::ID => ['type' => self::INT, 'constraint' => self::PRIMARY],
        self::VENDOR_ID => [
            'type' => self::INT,
            'constraint' => self::FOREIGN,
            'references' => VendorsEntity::class,
            'nullable' => true,
        ],
        self::CODE => ['type' => self::STRING],
    ];
}
