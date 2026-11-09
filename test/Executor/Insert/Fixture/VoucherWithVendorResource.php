<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Executor\Insert\Fixture;

use Ggs\JsonApiEngine\Model\LeftJoin;
use Ggs\JsonApiEngine\Resource;
use Ggs\JsonApiEngine\Resource\Insertable;

/**
 * Ресурс для OptionalParentTest, где необязательная сторона модели — родитель: `LeftJoin(vouchers, vendors,
 * [vendor_id, id])`. `vouchers.vendor_id` ссылается на `vendors`, поэтому `vendors` (правая сторона `LeftJoin`,
 * обёрнута в `InsertOptionalTableNode`) вставляется первой, а её ключ уходит ваучеру колонкой связи. Без атрибутов
 * продавца `vendors` пропускается, и `vendor_id` остаётся NULL.
 */
final class VoucherWithVendorResource extends Resource implements Insertable
{
    public const string TYPE = 'voucher';

    public const string ID = 'id';
    public const string CODE = 'code';
    public const string VENDOR_TITLE = 'vendor_title';

    public const array ATTRIBUTES = [
        self::ID => ['column' => VouchersEntity::ID, 'type' => self::INT, 'constraint' => self::PRIMARY],
        self::CODE => ['column' => VouchersEntity::CODE, 'type' => self::STRING],
        self::VENDOR_TITLE => ['column' => VendorsEntity::TITLE, 'type' => self::STRING, 'nullable' => true],
    ];

    #[\Override]
    public static function relationships(): array
    {
        return [];
    }

    public static function model(): LeftJoin
    {
        return new LeftJoin(
            left: VouchersEntity::class,
            right: VendorsEntity::class,
            relation: [VouchersEntity::VENDOR_ID, VendorsEntity::ID],
        );
    }
}
