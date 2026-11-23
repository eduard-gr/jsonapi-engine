<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Executor\Insert;

use Ggs\JsonApiEngine\Example\Container\ArrayContainer;
use Ggs\JsonApiEngine\Example\Container\ExampleResources;
use Ggs\JsonApiEngine\Example\Resource\CertificateResource;
use Ggs\JsonApiEngine\Example\Resource\CertificateWithDetailResource;
use Ggs\JsonApiEngine\Example\Resource\DeliveryResource;
use Ggs\JsonApiEngine\Example\Resource\DetailResource;
use Ggs\JsonApiEngine\Example\Resource\DomainResource;
use Ggs\JsonApiEngine\Example\Resource\ProviderResource;
use Ggs\JsonApiEngine\Executor\Insert\InsertOptionalEntityWriter;
use Ggs\JsonApiEngine\Executor\Insert\InsertResourceWriter;
use Ggs\JsonApiEngine\Executor\Insert\InsertEntityJoinWriter;
use Ggs\JsonApiEngine\Executor\Insert\InsertEntityWriter;
use Ggs\JsonApiEngine\Executor\PushExecutorInterface;
use Ggs\JsonApiEngine\Executor\EntityWriterInterface;
use Ggs\JsonApiEngine\Executor\Write\ResourceDeserializer;
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
 * InsertEntityJoinWriter, пустая LEFT-сторона пропускается; ресурс без InsertableInterface и чужая операция не принимаются.
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
                    parent: $this->getPlace(CertificateResource::class),
                    child: $this->getPlace(DetailResource::class),
                ),
                child: $this->getPlace(DeliveryResource::class),
            ),
            child: $this->getPlace(DomainResource::class),
        );
        $plan = new WritePlanner()->plan(resource: CertificateResource::class, roots: $roots);
        self::assertSame(
            $tree->explain(),
            new WriterBuilder(pdo: $this->pdo, lock: false, container: new ArrayContainer(services: ExampleResources::getAll()))->build(plan: $plan->input)->explain(),
        );

        // InsertEntityWriter пишет каждый INSERT в error_log(): PHPUnit перехватывает его и не печатает
        $this->expectErrorLog();
        $keys = new WriteRootWriter(root: $tree, data: $roots, route: null)->execute();

        self::assertCount(1, $keys);
        $id = $keys[0]['id'];
        self::assertSame(['code' => 'T-1', 'provider_id' => 2], array_intersect_key($keys[0], ['code' => 0, 'provider_id' => 0]));
        self::assertSame(
            [['provider_id' => 2, 'code' => 'T-1', 'status' => 'ACTIVE']],
            $this->getRows("select provider_id, code, status from certificates where id = {$id}"),
        );
        self::assertSame(
            [['buyer_email' => 'b@t.test']],
            $this->getRows("select buyer_email from certificate_details where certificate_id = {$id}"),
        );
        self::assertSame(
            [['delivery_channel' => 'EMAIL']],
            $this->getRows("select delivery_channel from certificate_delivery_meta where certificate_id = {$id}"),
        );
        self::assertSame(
            [['domain_name' => 't1.test'], ['domain_name' => 't2.test']],
            $this->getRows("select domain_name from domains where certificate_id = {$id} order by id"),
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
            root: $this->getPlace(CertificateWithDetailResource::class),
            data: $roots,
            route: null,
        )->execute();

        [$first, $second] = [$keys[0]['id'], $keys[1]['id']];
        self::assertSame($first, $keys[0]['certificate_id']);
        self::assertArrayNotHasKey('certificate_id', $keys[1]);
        self::assertSame(
            [['certificate_id' => $first, 'buyer_email' => 'b@t.test']],
            $this->getRows("select certificate_id, buyer_email from certificate_details where certificate_id in ({$first}, {$second})"),
        );
    }

    public function testTableTreeFollowsModel(): void
    {
        $tree = $this->getEntities(CertificateWithDetailResource::class)->explain();

        self::assertSame(InsertEntityJoinWriter::class, $tree['node']);
        self::assertSame(['id', 'certificate_id'], $tree['relation']);
        self::assertSame([InsertEntityWriter::class, 'certificates'], [$tree['parent']['node'], $tree['parent']['table']]);
        // certificate_details — правая сторона LeftJoin: обёрнута в необязательную часть
        self::assertSame(InsertOptionalEntityWriter::class, $tree['child']['node']);
        $details = $tree['child']['input'];
        self::assertSame([InsertEntityWriter::class, 'certificate_details'], [$details['node'], $details['table']]);
        self::assertNotContains('certificate_id', $details['columns']);
    }

    public function testResourceWithoutInsertableIsRejected(): void
    {
        $this->expectException(LogicException::class);
        new InsertResourceWriter(
            resource: new ProviderResource(),
            deserializer: new ResourceDeserializer(
                resource: new ProviderResource(),
            ),
            input: $this->getEntities(DomainResource::class),
        );
    }

    public function testOtherOperationIsRejected(): void
    {
        $this->expectException(LogicException::class);
        new InsertResourceWriter(
            resource: new DomainResource(),
            deserializer: new ResourceDeserializer(
                resource: new DomainResource(),
            ),
            input: $this->getEntities(DomainResource::class),
        )->push(new KeepOperation(resource: DomainResource::class, identity: new PersistentIdentity(id: 1)));
    }

    /**
     * Место ресурса с одной ветвью вставки.
     *
     * @param class-string<Resource> $resource
     */
    private function getPlace(string $resource): PushExecutorInterface
    {
        return new WriteOperationWriter(
            resource: $resource,
            add: new InsertResourceWriter(
                resource: new $resource(),
                deserializer: new ResourceDeserializer(
                    resource: new $resource(),
                ),
                input: $this->getEntities($resource),
            ),
            update: null,
            keep: null,
            remove: null,
        );
    }

    /**
     * @param class-string<Resource> $resource
     */
    private function getEntities(string $resource): EntityWriterInterface
    {
        return new WriterBuilder(pdo: $this->pdo, lock: false, container: new ArrayContainer(services: ExampleResources::getAll()))->getInsertEntities(
            plan: new InsertPlanner()->getEntities(resource: $resource),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getRows(string $sql): array
    {
        /** @var list<array<string, mixed>> */
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
}
