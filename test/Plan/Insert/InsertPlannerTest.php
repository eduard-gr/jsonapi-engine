<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Plan\Insert;

use Ggs\JsonApiEngine\Example\Resource\CertificateResource;
use Ggs\JsonApiEngine\Example\Resource\CertificateWithDetailResource;
use Ggs\JsonApiEngine\Example\Resource\DeliveryResource;
use Ggs\JsonApiEngine\Example\Resource\DetailResource;
use Ggs\JsonApiEngine\Example\Resource\DomainResource;
use Ggs\JsonApiEngine\Example\Resource\ProviderResource;
use Ggs\JsonApiEngine\Executor\Insert\InsertJoinWriter;
use Ggs\JsonApiEngine\Executor\Insert\InsertResourceWriter;
use Ggs\JsonApiEngine\Executor\Insert\InsertRouteLinkWriter;
use Ggs\JsonApiEngine\Executor\Insert\TableTreeBuilder;
use Ggs\JsonApiEngine\Executor\Insert\WriterBuilder;
use Ggs\JsonApiEngine\Parser\Insert\DocumentParser;
use Ggs\JsonApiEngine\Plan\Insert\InsertJoinNode;
use Ggs\JsonApiEngine\Plan\Insert\InsertOptionalTableNode;
use Ggs\JsonApiEngine\Plan\Insert\InsertPlanner;
use Ggs\JsonApiEngine\Plan\Insert\InsertResourceNode;
use Ggs\JsonApiEngine\Plan\Insert\InsertTableJoinNode;
use Ggs\JsonApiEngine\Plan\Insert\InsertTableNode;
use Ggs\JsonApiEngine\Resource;
use LogicException;
use PDO;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../example/db/connection.php';

/**
 * InsertPlanner: план по форме документа (только встреченные связи, объединение по ресурсам места, порядок объявления
 * связей), поддерево таблиц по модели, колонки связи маршрута; дерево исполнителей по плану совпадает с собранным
 * руками и вставляет строки (SQLite примера, транзакция с откатом).
 */
final class InsertPlannerTest extends TestCase
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

    public function testPlanMatchesHandMadeTree(): void
    {
        $plan = self::plan(resource: CertificateResource::class, document: self::certificate(
            relationships: ['detail' => 'det1', 'delivery' => 'del1', 'domains' => ['d1', 'd2']],
        ));

        $tables = new TableTreeBuilder(pdo: $this->pdo);
        $resource = static fn(string $class): InsertResourceWriter
            => new InsertResourceWriter(resource: $class, input: $tables->build($class));
        $relation = static fn(string $name): array => CertificateResource::relationship($name)?->relation ?? [];
        $hand_built_tree = new InsertJoinWriter(
            name: 'domains',
            relation: $relation('domains'),
            parent: new InsertJoinWriter(
                name: 'delivery',
                relation: $relation('delivery'),
                parent: new InsertJoinWriter(
                    name: 'detail',
                    relation: $relation('detail'),
                    parent: $resource(CertificateResource::class),
                    child: $resource(DetailResource::class),
                ),
                child: $resource(DeliveryResource::class),
            ),
            child: $resource(DomainResource::class),
        );

        self::assertSame($hand_built_tree->explain(), new WriterBuilder(pdo: $this->pdo)->build(plan: $plan)->explain());
    }

    public function testOnlyRelationshipsFromDocument(): void
    {
        $plan = self::plan(resource: CertificateResource::class, document: self::certificate(
            relationships: ['domains' => ['d1']],
        ));

        self::assertInstanceOf(InsertJoinNode::class, $plan);
        self::assertSame('domains', $plan->name);
        self::assertInstanceOf(InsertResourceNode::class, $plan->parent);
        self::assertSame(CertificateResource::class, $plan->parent->resource);
    }

    public function testNoRelationshipsGiveResourceNode(): void
    {
        $plan = self::plan(resource: CertificateResource::class, document: self::certificate(relationships: []));

        self::assertInstanceOf(InsertResourceNode::class, $plan);
        self::assertEquals(new InsertTableNode(entity: CertificateResource::model(), columns: [
            'id', 'provider_id', 'code', 'nominal', 'currency', 'status', 'created_at',
        ]), $plan->input);
    }

    public function testRelationshipsAreUnitedOverRoots(): void
    {
        $plan = self::plan(resource: CertificateResource::class, document: [
            'data' => [
                ['type' => 'certificate', 'lid' => 'c1', 'attributes' => ['code' => 'A']],
                [
                    'type' => 'certificate',
                    'lid' => 'c2',
                    'attributes' => ['code' => 'B'],
                    'relationships' => ['delivery' => ['data' => ['type' => 'delivery', 'lid' => 'del1']]],
                ],
            ],
            'included' => [['type' => 'delivery', 'lid' => 'del1', 'attributes' => ['delivery_channel' => 'SMS']]],
        ]);

        self::assertInstanceOf(InsertJoinNode::class, $plan);
        self::assertSame('delivery', $plan->name);
    }

    public function testModelOfTwoTables(): void
    {
        $tables = new InsertPlanner()->tables(resource: CertificateWithDetailResource::class);

        self::assertInstanceOf(InsertTableJoinNode::class, $tables);
        self::assertSame(['id', 'certificate_id'], $tables->relation);
        self::assertInstanceOf(InsertTableNode::class, $tables->parent);
        self::assertSame('certificates', $tables->parent->entity::TABLE);
        // certificate_details — правая сторона LeftJoin: необязательна
        self::assertInstanceOf(InsertOptionalTableNode::class, $tables->child);
        self::assertInstanceOf(InsertTableNode::class, $tables->child->input);
        self::assertSame('certificate_details', $tables->child->input->entity::TABLE);
    }

    public function testRouteLink(): void
    {
        self::assertSame(
            ['certificate_id' => 7],
            new InsertPlanner()->routeLink(parent: CertificateResource::class, relationship: 'domains', id: 7),
        );
    }

    public function testRouteLinkNeedsOwnerSidePrimaryKey(): void
    {
        $this->expectException(LogicException::class);
        new InsertPlanner()->routeLink(parent: CertificateResource::class, relationship: 'provider', id: 1);
    }

    public function testNotInsertableResourceFails(): void
    {
        $this->expectException(LogicException::class);
        new InsertPlanner()->plan(resource: ProviderResource::class, data: [['type' => 'provider', 'lid' => 'p1']]);
    }

    public function testExecutesPlanWithRouteLink(): void
    {
        // POST /certificates/3/domains
        $document = ['data' => [
            ['type' => 'domain', 'lid' => 'd1', 'attributes' => ['domain_name' => 'p1.test']],
            ['type' => 'domain', 'lid' => 'd2', 'attributes' => ['domain_name' => 'p2.test']],
        ]];
        $data = new DocumentParser()->parse(document: $document, resource: DomainResource::class);
        $planner = new InsertPlanner();

        $this->expectErrorLog();
        $insert = new WriterBuilder(pdo: $this->pdo)->root(
            plan: $planner->plan(resource: DomainResource::class, data: $data),
            data: $data,
            link: $planner->routeLink(parent: CertificateResource::class, relationship: 'domains', id: 3),
        );
        // колонки связи из маршрута — отдельный узел над деревом, не параметр корня
        $explain = $insert->explain();
        self::assertSame(InsertRouteLinkWriter::class, $explain['root']['node']);
        self::assertSame(['certificate_id' => 3], $explain['root']['link']);
        $keys = $insert->execute();

        self::assertSame([3, 3], array_column($keys, 'certificate_id'));
        self::assertSame(
            [['domain_name' => 'p1.test', 'certificate_id' => 3], ['domain_name' => 'p2.test', 'certificate_id' => 3]],
            $this->pdo->query("select domain_name, certificate_id from domains where domain_name like 'p_.test' order by id")
                ?->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    /**
     * @param class-string<Resource> $resource
     */
    private static function plan(string $resource, array $document): InsertJoinNode|InsertResourceNode
    {
        return new InsertPlanner()->plan(
            resource: $resource,
            data: new DocumentParser()->parse(document: $document, resource: $resource),
        );
    }

    /**
     * Сертификат c1 с новыми детями: связь → lid (список lid у `domains`).
     *
     * @param array<string, string|list<string>> $relationships
     */
    private static function certificate(array $relationships): array
    {
        $types = ['detail' => 'detail', 'delivery' => 'delivery', 'domains' => 'domain'];
        $attributes = [
            'detail' => ['buyer_email' => 'b@t.test'],
            'delivery' => ['delivery_channel' => 'EMAIL'],
            'domain' => ['domain_name' => 'x.test'],
        ];
        $data = [];
        $included = [];
        foreach ($relationships as $name => $lids) {
            $type = $types[$name];
            foreach ((array) $lids as $lid) {
                $included[] = ['type' => $type, 'lid' => $lid, 'attributes' => $attributes[$type]];
            }
            $data[$name] = ['data' => is_array($lids)
                ? array_map(static fn(string $lid): array => ['type' => $type, 'lid' => $lid], $lids)
                : ['type' => $type, 'lid' => $lids]];
        }

        return [
            'data' => ['type' => 'certificate', 'lid' => 'c1', 'attributes' => ['code' => 'C'], 'relationships' => $data],
            'included' => $included,
        ];
    }
}
