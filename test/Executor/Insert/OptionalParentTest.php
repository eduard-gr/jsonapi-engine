<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Executor\Insert;

use Ggs\JsonApiEngine\Executor\Insert\WriterBuilder;
use Ggs\JsonApiEngine\Parser\Insert\DocumentParser;
use Ggs\JsonApiEngine\Plan\Insert\InsertOptionalTableNode;
use Ggs\JsonApiEngine\Plan\Insert\InsertPlanner;
use Ggs\JsonApiEngine\Plan\Insert\InsertTableJoinNode;
use Ggs\JsonApiEngine\Plan\Insert\InsertTableNode;
use Ggs\JsonApiEngine\Test\Executor\Insert\Fixture\VoucherWithVendorResource;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Необязательная сторона модели — родитель: `VoucherWithVendorResource` (`LeftJoin(vouchers, vendors, [vendor_id, id])`,
 * `vouchers.vendor_id` ссылается на `vendors`). Своя схема в SQLite в памяти: в example/db нет пары таблиц без
 * одинаковых колонок, где правая сторона `LeftJoin` была бы родителем.
 */
final class OptionalParentTest extends TestCase
{
    private PDO $pdo;

    #[\Override]
    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('CREATE TABLE vendors (id INTEGER PRIMARY KEY AUTOINCREMENT, title VARCHAR(64) NOT NULL)');
        $this->pdo->exec(
            'CREATE TABLE vouchers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                vendor_id INT REFERENCES vendors(id),
                code VARCHAR(64) NOT NULL
            )',
        );
    }

    public function testOptionalRightSideBecomesParent(): void
    {
        $tables = new InsertPlanner()->tables(resource: VoucherWithVendorResource::class);

        self::assertInstanceOf(InsertTableJoinNode::class, $tables);
        self::assertSame(['id', 'vendor_id'], $tables->relation);
        // vendors — правая сторона LeftJoin, но на неё ссылается vouchers.vendor_id: она родитель и вставляется первой
        self::assertInstanceOf(InsertOptionalTableNode::class, $tables->parent);
        self::assertInstanceOf(InsertTableNode::class, $tables->parent->input);
        self::assertSame('vendors', $tables->parent->input->entity::TABLE);
        self::assertInstanceOf(InsertTableNode::class, $tables->child);
        self::assertSame('vouchers', $tables->child->entity::TABLE);
    }

    public function testParentInsertedFirstWhenPresentAndSkippedWhenAbsent(): void
    {
        $keys = $this->insert(document: ['data' => [
            ['type' => 'voucher', 'lid' => 'v1', 'attributes' => ['code' => 'V-1', 'vendor_title' => 'Acme']],
            ['type' => 'voucher', 'lid' => 'v2', 'attributes' => ['code' => 'V-2']],
        ]]);

        // Первый ваучер: продавец вставлен первым, его ключ ушёл ваучеру в vendor_id
        self::assertSame(
            [['id' => $keys[0]['vendor_id'], 'title' => 'Acme']],
            $this->rows('select id, title from vendors'),
        );
        // Второй: атрибутов продавца нет — vendors пропущена, vendor_id ваучера остался NULL
        self::assertArrayNotHasKey('vendor_id', $keys[1]);
        self::assertSame(
            [
                ['code' => 'V-1', 'vendor_id' => $keys[0]['vendor_id']],
                ['code' => 'V-2', 'vendor_id' => null],
            ],
            $this->rows('select code, vendor_id from vouchers order by id'),
        );
    }

    /**
     * @return list<array<string, mixed>> ключи корней
     */
    private function insert(array $document): array
    {
        // InsertTableWriter пишет каждый INSERT в error_log(): PHPUnit перехватывает его и не печатает
        $this->expectErrorLog();

        $data = new DocumentParser()->parse(document: $document, resource: VoucherWithVendorResource::class);
        $plan = new InsertPlanner()->plan(resource: VoucherWithVendorResource::class, data: $data);

        return new WriterBuilder(pdo: $this->pdo)->root(plan: $plan, data: $data)->execute();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql): array
    {
        /** @var list<array<string, mixed>> */
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
}
