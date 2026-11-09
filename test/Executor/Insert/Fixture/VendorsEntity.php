<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Executor\Insert\Fixture;

use Ggs\JsonApiEngine\Model\Entity;

/**
 * Таблица `vendors` для OptionalParentTest: родитель `vouchers` (на неё ссылается `vouchers.vendor_id`).
 */
final class VendorsEntity extends Entity
{
    public const string TABLE = 'vendors';

    public const string ID = 'id';
    public const string TITLE = 'title';

    public const array COLUMNS = [
        self::ID => ['type' => self::INT, 'constraint' => self::PRIMARY],
        self::TITLE => ['type' => self::STRING],
    ];
}
