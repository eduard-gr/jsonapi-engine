<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Executor\Insert;

use Ggs\JsonApiEngine\Example\Resource\CertificateResource;
use Ggs\JsonApiEngine\Example\Resource\CertificateWithDetailResource;
use Ggs\JsonApiEngine\Example\Resource\DeliveryResource;
use Ggs\JsonApiEngine\Example\Resource\DetailResource;
use Ggs\JsonApiEngine\Example\Resource\DomainResource;
use Ggs\JsonApiEngine\Example\Resource\ProviderResource;
use Ggs\JsonApiEngine\Executor\Insert\InsertOptionalTableWriter;
use Ggs\JsonApiEngine\Executor\Insert\InsertResourceWriter;
use Ggs\JsonApiEngine\Executor\Insert\InsertTableJoinWriter;
use Ggs\JsonApiEngine\Executor\Insert\InsertTableWriter;
use Ggs\JsonApiEngine\Executor\PushExecutor;
use Ggs\JsonApiEngine\Executor\TableWriter;
use Ggs\JsonApiEngine\Executor\Write\WriteJoinWriter;
use Ggs\JsonApiEngine\Executor\Write\WriteOperationWriter;
use Ggs\JsonApiEngine\Executor\Write\WriteRootWriter;
use Ggs\JsonApiEngine\Executor\Write\WriterBuilder;
use Ggs\JsonApiEngine\Parser\Write\KeepOperation;
use Ggs\JsonApiEngine\Parser\Write\PersistentIdentity;
use Ggs\JsonApiEngine\Parser\Write\WriteDocumentParser;
use Ggs\JsonApiEngine\Plan\Insert\InsertPlanner;
use Ggs\JsonApiEngine\Plan\Write\WritePlanner;
use Ggs\JsonApiEngine\Resource;
use LogicException;
use PDO;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../example/db/connection.php';

/**
 * Исполнители вставки на SQLite базе example/db (каждый тест — в транзакции с откатом): дерево, собранное руками,
 * совпадает с деревом по плану и даёт связанным ресурсам ключ владельца; модель из двух таблиц разворачивается в
 * InsertTableJoinWriter, пустая LEFT-сторона пропускается; ресурс без Insertable и чужая операция не принимаются.
 */
final class InsertWriterTest extends TestCase
{
    private const array RELATION = ['id', 'certificate_id'];

    private PDO $pdo;

    #[\Override]
    protected function setUp(): void
    {
        $this->pdo = getPDO();
        $this->pdo->beginTransaction();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->pdo->rollBack();
    }

    public function testRelatedResourcesGetOwnerKey(): void
    {
        $roots = new WriteDocumentParser()->insert(resource: CertificateResource::class, document: [
            'data' => [
                'type' => 'certificate',
                'lid' => 'c1',
                'attributes' => ['code' => 'T-1', 'nominal' => '10.00'],
                'relationships' => [
                    'provider' => ['data' => ['type' => 'provider', 'id' => '2']],
                    'detail' => ['data' => ['type' => 'detail', 'lid' => 'det1']],
                    'delivery' => ['data' => ['type' => 'delivery', 'lid' => 'del1']],
                    'domains' => ['data' => [['type' => 'domain', 'lid' => 'd1'], ['type' => 'domain', 'lid' => 'd2']]],
                ],
            ],
            'included' => [
                ['type' => 'detail', 'lid' => 'det1', 'attributes' => ['buyer_email' => 'b@t.test']],
                ['type' => 'delivery', 'lid' => 'del1', 'attributes' => ['delivery_channel' => 'EMAIL']],
                ['type' => 'domain', 'lid' => 'd1', 'attributes' => ['domain_name' => 't1.test']],
                ['type' => 'domain', 'lid' => 'd2', 'attributes' => ['domain_name' => 't2.test']],
            ],
        ]);
        // Дерево руками: связи владельца — левый глубокий список в порядке объявления, владелец в самом низу
        $tree = new WriteJoinWriter(
            name: 'domains',
            relation: self::RELATION,
            parent: new WriteJoinWriter(
                name: 'delivery',
                relation: self::RELATION,
                parent: new WriteJoinWriter(
                    name: 'detail',
                    relation: self::RELATION,
                    parent: $this->place(CertificateResource::class),
                    child: $this->place(DetailResource::class),
                ),
                child: $this->place(DeliveryResource::class),
            ),
            child: $this->place(DomainResource::class),
        );
        $plan = new WritePlanner()->plan(resource: CertificateResource::class, roots: $roots);
        self::assertSame(
            $tree->explain(),
            new WriterBuilder(pdo: $this->pdo, lock: false)->build(plan: $plan->input)->explain(),
        );

        // InsertTableWriter пишет каждый INSERT в error_log(): PHPUnit перехватывает его и не печатает
        $this->expectErrorLog();
        $keys = new WriteRootWriter(root: $tree, data: $roots, route: null)->execute();

        self::assertCount(1, $keys);
        $id = $keys[0]['id'];
        self::assertSame(['code' => 'T-1', 'provider_id' => 2], array_intersect_key($keys[0], ['code' => 0, 'provider_id' => 0]));
        self::assertSame(
            [['provider_id' => 2, 'code' => 'T-1', 'status' => 'ACTIVE']],
            $this->rows("select provider_id, code, status from certificates where id = {$id}"),
        );
        self::assertSame(
            [['buyer_email' => 'b@t.test']],
            $this->rows("select buyer_email from certificate_details where certificate_id = {$id}"),
        );
        self::assertSame(
            [['delivery_channel' => 'EMAIL']],
            $this->rows("select delivery_channel from certificate_delivery_meta where certificate_id = {$id}"),
        );
        self::assertSame(
            [['domain_name' => 't1.test'], ['domain_name' => 't2.test']],
            $this->rows("select domain_name from domains where certificate_id = {$id} order by id"),
        );
    }

    public function testModelOfTwoTablesSkipsEmptyLeftSide(): void
    {
        $roots = new WriteDocumentParser()->insert(resource: CertificateWithDetailResource::class, document: ['data' => [
            [
                'type' => 'certificate',
                'lid' => 'c1',
                'attributes' => ['code' => 'T-2', 'nominal' => '1.00', 'buyer_email' => 'b@t.test'],
                'relationships' => ['provider' => ['data' => ['type' => 'provider', 'id' => '1']]],
            ],
            [
                'type' => 'certificate',
                'lid' => 'c2',
                'attributes' => ['code' => 'T-3', 'nominal' => '2.00'],
                'relationships' => ['provider' => ['data' => ['type' => 'provider', 'id' => '1']]],
            ],
        ]]);
        $this->expectErrorLog();
        $keys = new WriteRootWriter(
            root: $this->place(CertificateWithDetailResource::class),
            data: $roots,
            route: null,
        )->execute();

        [$first, $second] = [$keys[0]['id'], $keys[1]['id']];
        self::assertSame($first, $keys[0]['certificate_id']);
        self::assertArrayNotHasKey('certificate_id', $keys[1]);
        self::assertSame(
            [['certificate_id' => $first, 'buyer_email' => 'b@t.test']],
            $this->rows("select certificate_id, buyer_email from certificate_details where certificate_id in ({$first}, {$second})"),
        );
    }

    public function testTableTreeFollowsModel(): void
    {
        $tree = $this->tables(CertificateWithDetailResource::class)->explain();

        self::assertSame(InsertTableJoinWriter::class, $tree['node']);
        self::assertSame(['id', 'certificate_id'], $tree['relation']);
        self::assertSame([InsertTableWriter::class, 'certificates'], [$tree['parent']['node'], $tree['parent']['table']]);
        // certificate_details — правая сторона LeftJoin: обёрнута в необязательную часть
        self::assertSame(InsertOptionalTableWriter::class, $tree['child']['node']);
        $details = $tree['child']['input'];
        self::assertSame([InsertTableWriter::class, 'certificate_details'], [$details['node'], $details['table']]);
        self::assertNotContains('certificate_id', $details['columns']);
    }

    public function testResourceWithoutInsertableIsRejected(): void
    {
        $this->expectException(LogicException::class);
        new InsertResourceWriter(
            resource: ProviderResource::class,
            input: $this->tables(DomainResource::class),
        );
    }

    public function testOtherOperationIsRejected(): void
    {
        $this->expectException(LogicException::class);
        new InsertResourceWriter(
            resource: DomainResource::class,
            input: $this->tables(DomainResource::class),
        )->push(new KeepOperation(resource: DomainResource::class, identity: new PersistentIdentity(id: 1)));
    }

    /**
     * Место ресурса с одной ветвью вставки.
     *
     * @param class-string<Resource> $resource
     */
    private function place(string $resource): PushExecutor
    {
        return new WriteOperationWriter(
            resource: $resource,
            add: new InsertResourceWriter(resource: $resource, input: $this->tables($resource)),
            update: null,
            remove: null,
        );
    }

    /**
     * @param class-string<Resource> $resource
     */
    private function tables(string $resource): TableWriter
    {
        return new WriterBuilder(pdo: $this->pdo, lock: false)->insertTables(
            plan: new InsertPlanner()->tables(resource: $resource),
        );
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
