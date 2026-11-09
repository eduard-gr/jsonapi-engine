<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Executor\Select;

use ArrayIterator;
use Generator;
use Ggs\JsonApiEngine\Document\ResourceRecord;
use Ggs\JsonApiEngine\Document\ResourceRegistry;
use Ggs\JsonApiEngine\Example\Resource\CertificateResource;
use Ggs\JsonApiEngine\Example\Resource\ProviderResource;
use Ggs\JsonApiEngine\Executor\PullExecutor;
use Ggs\JsonApiEngine\Executor\Select\SerializeIterator;
use Ggs\JsonApiEngine\Executor\Select\SqlScanIterator;
use Ggs\JsonApiEngine\Parser\QueryParser;
use Ggs\JsonApiEngine\Plan\Select\JoinNode;
use Ggs\JsonApiEngine\Plan\Select\ResourceNode;
use Ggs\JsonApiEngine\Plan\Select\SelectPlanner;
use Ggs\JsonApiEngine\Plan\Select\SharedScanNode;
use Ggs\JsonApiEngine\Plan\Select\SqlScanNode;
use Ggs\JsonApiEngine\Resource\ManyToOne;
use Ggs\JsonApiEngine\Test\Executor\Select\Fixture\VoucherResource;
use PHPUnit\Framework\TestCase;
use sad_spirit\pg_builder\SqlBuilderWalker;

require_once __DIR__ . '/../../../example/db/connection.php';

/**
 * SerializeIterator по плану: ресурсы из строк запросов плана (карта колонок SqlScanNode::columns) —
 * атрибуты, составной атрибут через serialize<Атрибут>(), связь по внешнему ключу, ключи соединений, null
 * при NULL в первичном ключе.
 */
final class SerializeIteratorTest extends TestCase
{
    /**
     * Все запросы плана примера на SQLite: каждый ресурс документа-фикстуры получается с теми же атрибутами,
     * сертификат — с той же связью provider.
     */
    public function testPlanRowsMatchFixture(): void
    {
        $tree = (new QueryParser())->parse(
            resource: CertificateResource::class,
            query: ['include' => 'provider,detail,delivery,domains.logs'],
        );
        $owners = [];
        self::collect(node: (new SelectPlanner())->plan(tree: $tree)['sql'], owners: $owners);

        $records = [];
        foreach ($owners as $owner) {
            $sql = $owner->select->dispatch(new SqlBuilderWalker());
            foreach ($owner->columns as $columns) {
                $iterator = new SerializeIterator(
                    source: new SqlScanIterator(pdo: getPDO(), sql: $sql),
                    resource: $columns['resource'],
                    registry: new ResourceRegistry(),
                    columns: $columns,
                );
                foreach ($iterator as $record) {
                    self::assertNotNull($record);
                    $records[$record->key()] = json_decode((string) json_encode($record), true);
                }
            }
        }

        $document = json_decode((string) file_get_contents(__DIR__ . '/../../Fixture/document.json'), true);
        $expected = [...$document['data'], ...$document['included']];
        self::assertCount(\count($expected), $records);
        foreach ($expected as $object) {
            $key = $object['type'] . ':' . $object['id'];
            self::assertArrayHasKey($key, $records);
            self::assertSame($object['attributes'] ?? null, $records[$key]['attributes'] ?? null, $key);
            if ($object['type'] === CertificateResource::TYPE) {
                self::assertSame($object['relationships']['provider'], $records[$key]['relationships']['provider'], $key);
            }
        }
    }

    public function testCompositeAttributeCastForeignKeyAndKeys(): void
    {
        $records = self::serialize(
            rows: [
                [
                    't0_id' => '7', 't0_amount' => '150', 't0_active' => 0, 't0_valid_from' => '2026-01-01',
                    't0_valid_to' => '2026-12-31', 't0_issuer_id' => '3', 't0_parent_id' => '42',
                ],
                [
                    't0_id' => '8', 't0_amount' => null, 't0_active' => 1, 't0_valid_from' => null,
                    't0_valid_to' => null, 't0_issuer_id' => null, 't0_parent_id' => null,
                ],
                // LEFT JOIN без ресурса
                [
                    't0_id' => null, 't0_amount' => null, 't0_active' => null, 't0_valid_from' => null,
                    't0_valid_to' => null, 't0_issuer_id' => null, 't0_parent_id' => null,
                ],
            ],
            fields: ['validity', 'issuer', 'amount', 'active'],
            keys: ['id', 'parent_id'],
        );

        self::assertCount(3, $records);
        [$first, $second, $absent] = $records;
        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertNull($absent);

        // атрибуты — в порядке fields, связь по внешнему ключу — в relationships
        self::assertSame(
            [
                'id' => '7',
                'type' => 'voucher',
                'attributes' => ['validity' => ['from' => '2026-01-01', 'to' => '2026-12-31'], 'amount' => 150, 'active' => false],
                'relationships' => ['issuer' => ['data' => ['id' => '3', 'type' => ProviderResource::TYPE]]],
            ],
            $first->jsonSerialize(),
        );
        self::assertSame(42, $first->getKey('parent_id'));
        self::assertSame(7, $first->getKey('id'));

        self::assertSame(
            [
                'id' => '8',
                'type' => 'voucher',
                'attributes' => ['validity' => ['from' => null, 'to' => null], 'amount' => null, 'active' => true],
                'relationships' => ['issuer' => ['data' => null]],
            ],
            $second->jsonSerialize(),
        );
        // NULL в ключе не пишется
        $this->expectException(\OutOfBoundsException::class);
        $second->getKey('parent_id');
    }

    public function testColumnsOfOtherResourceFail(): void
    {
        $this->expectException(\LogicException::class);
        new SerializeIterator(
            source: self::source(rows: []),
            resource: CertificateResource::class,
            registry: new ResourceRegistry(),
            columns: self::columns(fields: [], keys: []),
        );
    }

    public function testMissingColumnFails(): void
    {
        $columns = self::columns(fields: ['amount'], keys: []);
        unset($columns['columns']['amount']);

        $this->expectException(\LogicException::class);
        new SerializeIterator(source: self::source(rows: []), resource: VoucherResource::class, registry: new ResourceRegistry(), columns: $columns);
    }

    public function testManyToOneIsForeignKeyRelationship(): void
    {
        self::assertInstanceOf(ManyToOne::class, CertificateResource::foreignRelationship('provider'));
        self::assertContains('provider', CertificateResource::publicAttributes());
        self::assertSame(['provider_id'], CertificateResource::attributeColumns('provider'));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string>               $fields
     * @param list<string>               $keys
     * @return list<?ResourceRecord>
     */
    private static function serialize(array $rows, array $fields, array $keys): array
    {
        $iterator = new SerializeIterator(
            source: self::source(rows: $rows),
            resource: VoucherResource::class,
            registry: new ResourceRegistry(),
            columns: self::columns(fields: $fields, keys: $keys),
        );

        return iterator_to_array($iterator, false);
    }

    /**
     * Запись SqlScanNode::columns для VoucherResource с алиасом t0 и всеми колонками.
     *
     * @param list<string> $fields
     * @param list<string> $keys
     */
    private static function columns(array $fields, array $keys): array
    {
        $map = [];
        foreach (['id', 'amount', 'active', 'valid_from', 'valid_to', 'issuer_id', 'parent_id'] as $column) {
            $map[$column] = 't0_' . $column;
        }

        return ['resource' => VoucherResource::class, 'alias' => 't0', 'columns' => $map, 'fields' => $fields, 'keys' => $keys];
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private static function source(array $rows): PullExecutor
    {
        return new class ($rows) implements PullExecutor {
            /** @param list<array<string, mixed>> $rows */
            public function __construct(private array $rows) {}

            public function explain(): array
            {
                return ['node' => 'rows'];
            }

            public function getIterator(): Generator
            {
                yield from new ArrayIterator($this->rows);
            }
        };
    }

    /**
     * @param array<class-string, SqlScanNode> $owners
     */
    private static function collect(ResourceNode|SharedScanNode|SqlScanNode|JoinNode $node, array &$owners): void
    {
        if ($node instanceof SqlScanNode) {
            $owners[$node->rowOwner()->resource] = $node;
            self::collect(node: $node->input, owners: $owners);
        } elseif ($node instanceof JoinNode) {
            self::collect(node: $node->outer, owners: $owners);
            self::collect(node: $node->inner, owners: $owners);
        }
    }
}
