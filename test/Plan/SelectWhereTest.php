<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Plan;

use Ggs\JsonApiEngine\Example\Resource\CertificateResource;
use Ggs\JsonApiEngine\Example\Resource\DeliveryResource;
use Ggs\JsonApiEngine\Executor\SqlScanIterator;
use Ggs\JsonApiEngine\Parser\QueryParser;
use Ggs\JsonApiEngine\Plan\JoinNode;
use Ggs\JsonApiEngine\Plan\ResourceNode;
use Ggs\JsonApiEngine\Plan\Select\QueryPlanner;
use Ggs\JsonApiEngine\Plan\SharedRowJoinNode;
use Ggs\JsonApiEngine\Plan\SharedScanNode;
use Ggs\JsonApiEngine\Test\Plan\Fixture\KeyedCertificateResource;
use PHPUnit\Framework\TestCase;
use sad_spirit\pg_builder\SqlBuilderWalker;

require_once __DIR__ . '/../../example/db/connection.php';

/**
 * WHERE запросов плана (шаг sql): фильтры владельца строки — через AND, фильтры 1-1 ресурса — группой
 * (pk IS NULL OR …), значения — именованные параметры; колонки фильтров и сортировки держат свои таблицы модели
 * в FROM, но не выводятся. Запросы выполняются на SQLite примера.
 */
final class SelectWhereTest extends TestCase
{
    public function testOwnerAndOneToOneFilters(): void
    {
        $owner = self::owner(query: [
            'include' => 'delivery',
            'fields' => ['certificate' => 'code', 'delivery' => 'is_sent'],
            'filter' => ['status' => 'ACTIVE', 'created_at' => ['gte' => '2026-03-01'], 'delivery.is_sent' => 'false'],
        ]);

        self::assertSame(
            'select t0.id as t0_id, t0.code as t0_code, t1.certificate_id as t1_certificate_id, t1.is_sent as t1_is_sent'
            . ' from certificates as t0 left join certificate_delivery_meta as t1 on t0.id = t1.certificate_id'
            . ' where t0.status = :t0_status_0 and t0.created_at >= :t0_created_at_1'
            . ' and ( t1.certificate_id is null or t1.is_sent = :t1_is_sent_2 )'
            . ' order by t0.id',
            self::sql(owner: $owner),
        );
        self::assertSame(
            ['t0_status_0' => 'ACTIVE', 't0_created_at_1' => '2026-03-01 00:00:00.000000' . date('P', strtotime('2026-03-01')), 't1_is_sent_2' => false],
            $owner->parameters,
        );

        // Те же условия руками: строк столько же
        $expected = (int) getPDO()->query(
            "select count(*) from certificates c left join certificate_delivery_meta d on c.id = d.certificate_id"
            . " where c.status = 'ACTIVE' and c.created_at >= '2026-03-01' and (d.certificate_id is null or d.is_sent = 0)",
        )->fetchColumn();
        self::assertGreaterThan(0, $expected);
        self::assertCount($expected, self::rows(owner: $owner));
    }

    public function testFilterOfOneToOneWithoutOwnerFilters(): void
    {
        $owner = self::owner(query: ['include' => 'delivery', 'filter' => ['delivery.is_sent' => 'true']]);

        self::assertStringContainsString(' where ( t1.certificate_id is null or t1.is_sent = :t1_is_sent_0 ) order by', self::sql(owner: $owner));
        self::assertSame(['t1_is_sent_0' => true], $owner->parameters);
        self::assertNotSame([], self::rows(owner: $owner));
    }

    public function testTwoConditionsOnOneColumnHaveDistinctParameters(): void
    {
        $owner = self::owner(query: ['filter' => ['created_at' => ['gte' => '2026-01-01', 'lt' => '2026-04-01']]]);

        self::assertStringContainsString(
            ' where t0.created_at >= :t0_created_at_0 and t0.created_at < :t0_created_at_1 order by',
            self::sql(owner: $owner),
        );
        self::assertSame(['t0_created_at_0', 't0_created_at_1'], array_keys($owner->parameters));
    }

    public function testWithoutFiltersNoWhere(): void
    {
        $owner = self::owner(query: []);

        self::assertStringNotContainsString(' where ', self::sql(owner: $owner));
        self::assertSame([], $owner->parameters);
    }

    /**
     * Колонки фильтра и сортировки из внутренних LEFT-таблиц модели держат свои таблицы в FROM (иначе ModelTree
     * отбросил бы их), но в список колонок не попадают.
     */
    public function testFilterAndSortColumnsKeepModelTables(): void
    {
        $owner = (new SharedScanNode(input: new ResourceNode(
            resource: KeyedCertificateResource::class,
            fields: ['status'],
            filters: [['attribute' => 'key_algorithm', 'operator' => 'eq', 'value' => 'RSA']],
            sort: [['attribute' => 'key_size', 'descending' => true, 'position' => 0]],
        )))->withSelect();
        $sql = self::sql(owner: $owner);

        self::assertStringStartsWith('select t0.id as t0_id, t0.status as t0_status from ', $sql);
        self::assertStringContainsString('certificate_keys as t0_2', $sql);
        self::assertStringContainsString('key_algorithms as t0_3', $sql);
        self::assertStringNotContainsString('certificates_csr', $sql);
        // колонки модели key_algorithm и key_size — в таблицах "name" и size
        self::assertStringEndsWith(' where t0_3."name" = :t0_key_algorithm_0 order by t0_2.size desc, t0.id', $sql);
        self::assertSame(['t0_key_algorithm_0' => 'RSA'], $owner->parameters);
    }

    public function testInOperatorIsNotSupportedYet(): void
    {
        $node = new SharedScanNode(input: new ResourceNode(
            resource: KeyedCertificateResource::class,
            filters: [['attribute' => 'key_algorithm', 'operator' => 'in', 'value' => ['RSA', 'EC']]],
        ));

        $this->expectException(\LogicException::class);
        $node->withSelect();
    }

    /**
     * Владелец запроса сертификатов после шага sql.
     *
     * @param array<string, mixed> $query
     */
    private static function owner(array $query): SharedScanNode
    {
        $tree = (new QueryParser())->parse(resource: CertificateResource::class, query: $query);
        $node = (new QueryPlanner())->plan(tree: $tree)['sql'];
        while ($node instanceof JoinNode && !$node instanceof SharedRowJoinNode) {
            $node = $node->outer;
        }
        self::assertInstanceOf(SharedScanNode::class, $node);
        self::assertSame(CertificateResource::class, $node->rowOwner()->resource);

        return $node;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(SharedScanNode $owner): array
    {
        self::assertNotNull($owner->select);
        $scan = new SqlScanIterator(pdo: getPDO(), sql: $owner->select->dispatch(new SqlBuilderWalker()), parameters: $owner->parameters);

        return iterator_to_array($scan, false);
    }

    private static function sql(SharedScanNode $owner): string
    {
        self::assertNotNull($owner->select);

        return (string) preg_replace('/\s+/', ' ', $owner->select->dispatch(new SqlBuilderWalker()));
    }
}
