<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Executor;

use Ggs\JsonApiEngine\Example\Resource\CertificateResource;
use Ggs\JsonApiEngine\Example\Resource\CertificateWithDetailResource;
use Ggs\JsonApiEngine\Example\Resource\DeliveryResource;
use Ggs\JsonApiEngine\Example\Resource\DetailResource;
use Ggs\JsonApiEngine\Example\Resource\DomainResource;
use Ggs\JsonApiEngine\Example\Resource\ProviderResource;
use Ggs\JsonApiEngine\Executor\InsertDocument;
use Ggs\JsonApiEngine\Executor\InsertJoinWriter;
use Ggs\JsonApiEngine\Executor\InsertResourceWriter;
use Ggs\JsonApiEngine\Executor\InsertTableJoinWriter;
use Ggs\JsonApiEngine\Executor\InsertTableWriter;
use Ggs\JsonApiEngine\Executor\TableTreeBuilder;
use Ggs\JsonApiEngine\Parser\DocumentParser;
use Ggs\JsonApiEngine\Resource;
use LogicException;
use PDO;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../example/db/connection.php';

/**
 * Дерево вставки на SQLite базе example/db (каждый тест — в транзакции с откатом): связи между ресурсами получают
 * ключ владельца, модель из двух таблиц разворачивается в InsertTableJoinWriter, пустая LEFT-сторона пропускается,
 * колонка связи из маршрута вложенного ресурса доходит до каждого корня, ресурс без Insertable не принимается.
 */
final class InsertWriterTest extends TestCase
{
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
        $keys = $this->insert(resource: CertificateResource::class, document: [
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
        ], tree: fn(): InsertJoinWriter => new InsertJoinWriter(
            name: 'domains',
            relation: ['id', 'certificate_id'],
            first: new InsertJoinWriter(
                name: 'delivery',
                relation: ['id', 'certificate_id'],
                first: new InsertJoinWriter(
                    name: 'detail',
                    relation: ['id', 'certificate_id'],
                    first: $this->writer(CertificateResource::class),
                    then: $this->writer(DetailResource::class),
                ),
                then: $this->writer(DeliveryResource::class),
            ),
            then: $this->writer(DomainResource::class),
        ));

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
        $keys = $this->insert(resource: CertificateWithDetailResource::class, document: ['data' => [
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
        ]], tree: fn(): InsertResourceWriter => $this->writer(CertificateWithDetailResource::class));

        [$first, $second] = [$keys[0]['id'], $keys[1]['id']];
        self::assertSame($first, $keys[0]['certificate_id']);
        self::assertArrayNotHasKey('certificate_id', $keys[1]);
        self::assertSame(
            [['certificate_id' => $first, 'buyer_email' => 'b@t.test']],
            $this->rows("select certificate_id, buyer_email from certificate_details where certificate_id in ({$first}, {$second})"),
        );
    }

    public function testRouteLinkGoesToEveryRoot(): void
    {
        // POST /certificates/3/domains: certificate_id — из маршрута по связи родителя, не из документа
        $relation = CertificateResource::relationship(CertificateResource::DOMAINS)->relation;
        $keys = $this->insert(resource: DomainResource::class, document: ['data' => [
            ['type' => 'domain', 'lid' => 'd1', 'attributes' => ['domain_name' => 'r1.test']],
            ['type' => 'domain', 'lid' => 'd2', 'attributes' => ['domain_name' => 'r2.test']],
        ]], tree: fn(): InsertResourceWriter => $this->writer(DomainResource::class), link: [$relation[1] => 3]);

        self::assertSame([3, 3], array_column($keys, 'certificate_id'));
        self::assertSame(
            [['certificate_id' => 3], ['certificate_id' => 3]],
            $this->rows("select certificate_id from domains where domain_name in ('r1.test', 'r2.test') order by id"),
        );
    }

    public function testTableTreeFollowsModel(): void
    {
        $tree = new TableTreeBuilder(pdo: $this->pdo)->build(CertificateWithDetailResource::class)->explain();

        self::assertSame(InsertTableJoinWriter::class, $tree['node']);
        self::assertSame(['id', 'certificate_id'], $tree['relation']);
        self::assertSame('then', $tree['optional']);
        self::assertSame([InsertTableWriter::class, 'certificates'], [$tree['first']['node'], $tree['first']['table']]);
        self::assertSame([InsertTableWriter::class, 'certificate_details'], [$tree['then']['node'], $tree['then']['table']]);
        self::assertNotContains('certificate_id', $tree['then']['columns']);
    }

    public function testResourceWithoutInsertableIsRejected(): void
    {
        $this->expectException(LogicException::class);
        new TableTreeBuilder(pdo: $this->pdo)->build(ProviderResource::class);
    }

    /**
     * @param class-string<Resource>                 $resource
     * @param callable(): \Ggs\JsonApiEngine\Executor\ExecutorWriter $tree
     * @param array<string, mixed>                   $link колонки связи из маршрута, всем корням
     * @return list<array<string, mixed>>
     */
    private function insert(string $resource, array $document, callable $tree, array $link = []): array
    {
        // InsertTableWriter пишет каждый INSERT в error_log(): PHPUnit перехватывает его и не печатает
        $this->expectErrorLog();

        return new InsertDocument(
            root: $tree(),
            roots: new DocumentParser()->parse(document: $document, resource: $resource),
            link: $link,
        )->execute();
    }

    /**
     * @param class-string<Resource> $resource
     */
    private function writer(string $resource): InsertResourceWriter
    {
        return new InsertResourceWriter(resource: $resource, input: new TableTreeBuilder(pdo: $this->pdo)->build($resource));
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
