<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Executor\Select;

use ArrayIterator;
use Generator;
use Ggs\JsonApiEngine\Document\ResourceRecord;
use Ggs\JsonApiEngine\Example\Resource\CertificateResource;
use Ggs\JsonApiEngine\Example\Resource\ProviderResource;
use Ggs\JsonApiEngine\Executor\PullExecutorInterface;
use Ggs\JsonApiEngine\Executor\Select\ManualSerializeReader;
use Ggs\JsonApiEngine\Executor\Select\SerializeReader;
use Ggs\JsonApiEngine\Executor\Select\EntityScanReader;
use Ggs\JsonApiEngine\Parser\QueryParser;
use Ggs\JsonApiEngine\Plan\Select\JoinNode;
use Ggs\JsonApiEngine\Plan\Select\ResourceNode;
use Ggs\JsonApiEngine\Plan\Select\SelectPlanner;
use Ggs\JsonApiEngine\Plan\Select\EntityScanNodeDraft;
use Ggs\JsonApiEngine\Plan\Select\EntityScanNode;
use Ggs\JsonApiEngine\Resource\SerializeInterface;
use Ggs\JsonApiEngine\Resource\ManyToOne;
use Ggs\JsonApiEngine\Test\Executor\Select\Fixture\ManualVoucherResource;
use Ggs\JsonApiEngine\Test\Executor\Select\Fixture\VoucherResource;
use PHPUnit\Framework\TestCase;
use sad_spirit\pg_builder\SqlBuilderWalker;

require_once __DIR__ . '/../../../example/db/connection.php';

/**
 * SerializeReader по плану: ресурсы из строк запросов плана (карта колонок EntityScanNode::columns) —
 * атрибуты, связь по внешнему ключу, ключи соединений, null при NULL в первичном ключе; ручная сериализация — свой
 * serialize() экземпляра ресурса вместо сборки по описанию (ManualSerializeReader); составной атрибут без него — ошибка.
 */
final class SerializeReaderTest extends TestCase
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
                // Как ReaderBuilder: ресурс, реализующий SerializeInterface, собирает ManualSerializeReader
                $class = is_a($columns['resource'], SerializeInterface::class, true)
                    ? ManualSerializeReader::class
                    : SerializeReader::class;
                $reader = new $class(
                    source: new EntityScanReader(pdo: getPDO(), sql: $sql),
                    resource: new $columns['resource'](),
                    columns: $columns,
                );
                foreach ($reader as $record) {
                    self::assertNotNull($record);
                    $records[$record->getRegistryKey()] = json_decode((string) json_encode($record), true);
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

    public function testCastForeignKeyAndKeys(): void
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
            fields: ['issuer', 'amount', 'active'],
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
                'attributes' => ['amount' => 150, 'active' => false],
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
                'attributes' => ['amount' => null, 'active' => true],
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
        new SerializeReader(
            source: self::getSource(rows: []),
            resource: new CertificateResource(),
            columns: self::getColumns(fields: [], keys: []),
        );
    }

    public function testMissingColumnFails(): void
    {
        $columns = self::getColumns(fields: ['amount'], keys: []);
        unset($columns['columns']['amount']);

        $this->expectException(\LogicException::class);
        new SerializeReader(source: self::getSource(rows: []), resource: new VoucherResource(), columns: $columns);
    }

    public function testCompositeAttributeWithoutSerializeMethodFails(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('composite attribute validity requires ' . SerializeInterface::class);
        new SerializeReader(
            source: self::getSource(rows: []),
            resource: new VoucherResource(),
            columns: self::getColumns(fields: ['validity'], keys: []),
        );
    }

    public function testManyToOneIsForeignKeyRelationship(): void
    {
        self::assertInstanceOf(ManyToOne::class, CertificateResource::getForeignRelationship('provider'));
        self::assertContains('provider', CertificateResource::getPublicAttributes());
        self::assertSame(['provider_id'], CertificateResource::getAttributeColumns('provider'));
    }

    public function testResourceSerializeMethodBuildsTheResource(): void
    {
        // Экземпляр с зависимостью конструктора: её использует serialize()
        $resource = new ManualVoucherResource(
            type_prefix: 'voucher-',
        );
        $reader = new ManualSerializeReader(
            source: self::getSource(rows: [
                // t1_code — колонка другого ресурса той же строки: в строку serialize() не попадает
                ['t0_id' => '7', 't0_amount' => '100', 't0_kind' => 'gift', 't0_parent_id' => '3', 't1_code' => 'X'],
                ['t0_id' => null, 't0_amount' => null, 't0_kind' => null, 't0_parent_id' => null, 't1_code' => 'Y'],
                ['t0_id' => 8, 't0_amount' => 5, 't0_kind' => 'hidden', 't0_parent_id' => 3, 't1_code' => 'Z'],
                ['t0_id' => 9, 't0_amount' => 20, 't0_kind' => 'promo', 't0_parent_id' => null, 't1_code' => 'W'],
            ]),
            resource: $resource,
            columns: [
                'resource' => ManualVoucherResource::class,
                'alias' => 't0',
                'columns' => ['id' => 't0_id', 'amount' => 't0_amount', 'kind' => 't0_kind', 'parent_id' => 't0_parent_id'],
                'fields' => ['amount'],
                'keys' => ['id', 'parent_id'],
            ],
        );

        self::assertSame(ManualSerializeReader::class, $reader->explain()['node']);
        $records = iterator_to_array($reader, false);

        // Первичный ключ NULL — метод не вызывается; строка — колонки модели, значения как пришли
        self::assertSame(
            [
                ['id' => '7', 'amount' => '100', 'kind' => 'gift', 'parent_id' => '3'],
                ['id' => 8, 'amount' => 5, 'kind' => 'hidden', 'parent_id' => 3],
                ['id' => 9, 'amount' => 20, 'kind' => 'promo', 'parent_id' => null],
            ],
            $resource->calls,
        );
        // запрошенное метод получает вторым аргументом, а не угадывает по колонкам строки
        self::assertSame(['amount'], $resource->fields);

        self::assertCount(4, $records);
        self::assertNull($records[1]);
        // serialize() вернул null — ресурса нет
        self::assertNull($records[2]);

        // Тип и атрибуты — от метода (active не запрошен: его нет в fields); ключи соединений — от узла
        self::assertSame(
            ['id' => '7', 'type' => 'voucher-gift', 'attributes' => ['amount' => '100']],
            $records[0]?->jsonSerialize(),
        );
        self::assertSame(3, $records[0]?->getKey('parent_id'));
        self::assertSame('voucher-promo:9', $records[3]?->getRegistryKey());

        // NULL в ключе соединения не пишется, как и без ручной сериализации
        $this->expectException(\OutOfBoundsException::class);
        $records[3]?->getKey('parent_id');
    }

    /**
     * Ресурс, который собирает себя сам, общий узел не принимает: собрать его по описанию значило бы молча выдать
     * другой документ.
     */
    public function testGenericReaderRejectsResourceWithInterface(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('implements ' . SerializeInterface::class . ': use ' . ManualSerializeReader::class);
        new SerializeReader(
            source: self::getSource(rows: []),
            resource: new ManualVoucherResource(
                type_prefix: 'voucher-',
            ),
            columns: [
                'resource' => ManualVoucherResource::class,
                'alias' => 't0',
                'columns' => ['id' => 't0_id', 'amount' => 't0_amount'],
                'fields' => ['amount'],
                'keys' => [],
            ],
        );
    }

    public function testManualReaderNeedsSerializeInterface(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('does not implement ' . SerializeInterface::class);
        new ManualSerializeReader(
            source: self::getSource(rows: []),
            resource: new VoucherResource(),
            columns: self::getColumns(fields: ['amount'], keys: []),
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string>               $fields
     * @param list<string>               $keys
     * @return list<?ResourceRecord>
     */
    private static function serialize(array $rows, array $fields, array $keys): array
    {
        $reader = new SerializeReader(
            source: self::getSource(rows: $rows),
            resource: new VoucherResource(),
            columns: self::getColumns(fields: $fields, keys: $keys),
        );

        return iterator_to_array($reader, false);
    }

    /**
     * Запись EntityScanNode::columns для VoucherResource с алиасом t0 и всеми колонками.
     *
     * @param list<string> $fields
     * @param list<string> $keys
     */
    private static function getColumns(array $fields, array $keys): array
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
    private static function getSource(array $rows): PullExecutorInterface
    {
        return new class ($rows) implements PullExecutorInterface {
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
     * @param array<class-string, EntityScanNode> $owners
     */
    private static function collect(ResourceNode|EntityScanNodeDraft|EntityScanNode|ForeignDataScanNode|JoinNode $node, array &$owners): void
    {
        if ($node instanceof EntityScanNode) {
            $owners[$node->getRootResourceNode()->resource] = $node;
            self::collect(node: $node->input, owners: $owners);
        } elseif ($node instanceof JoinNode) {
            self::collect(node: $node->outer, owners: $owners);
            self::collect(node: $node->inner, owners: $owners);
        }
    }
}
