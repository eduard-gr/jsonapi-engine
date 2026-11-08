<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Plan;

use Ggs\JsonApiEngine\Example\Resource\CertificateResource;
use Ggs\JsonApiEngine\Example\Resource\CertificateWithDetailResource;
use Ggs\JsonApiEngine\Example\Resource\DeliveryResource;
use Ggs\JsonApiEngine\Example\Resource\DetailResource;
use Ggs\JsonApiEngine\Example\Resource\DomainResource;
use Ggs\JsonApiEngine\Example\Resource\ProviderResource;
use Ggs\JsonApiEngine\Executor\InsertJoinWriter;
use Ggs\JsonApiEngine\Executor\InsertResourceWriter;
use Ggs\JsonApiEngine\Executor\TableTreeBuilder;
use Ggs\JsonApiEngine\Executor\WriterBuilder;
use Ggs\JsonApiEngine\Parser\DocumentParser;
use Ggs\JsonApiEngine\Plan\Insert\InsertJoinNode;
use Ggs\JsonApiEngine\Plan\Insert\InsertPlanner;
use Ggs\JsonApiEngine\Plan\Insert\InsertResourceNode;
use Ggs\JsonApiEngine\Plan\Insert\InsertTableJoinNode;
use Ggs\JsonApiEngine\Plan\Insert\InsertTableNode;
use Ggs\JsonApiEngine\Resource;
use LogicException;
use PDO;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../example/db/connection.php';

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
        $hand = new InsertJoinWriter(
            name: 'domains',
            relation: $relation('domains'),
            first: new InsertJoinWriter(
                name: 'delivery',
                relation: $relation('delivery'),
                first: new InsertJoinWriter(
                    name: 'detail',
                    relation: $relation('detail'),
                    first: $resource(CertificateResource::class),
                    then: $resource(DetailResource::class),
                ),
                then: $resource(DeliveryResource::class),
            ),
            then: $resource(DomainResource::class),
        );

        self::assertSame($hand->explain(), new WriterBuilder(pdo: $this->pdo)->build(plan: $plan)->explain());
    }

    public function testOnlyRelationshipsFromDocument(): void
    {
        $plan = self::plan(resource: CertificateResource::class, document: self::certificate(
            relationships: ['domains' => ['d1']],
        ));

        self::assertInstanceOf(InsertJoinNode::class, $plan);
        self::assertSame('domains', $plan->name);
        self::assertInstanceOf(InsertResourceNode::class, $plan->first);
        self::assertSame(CertificateResource::class, $plan->first->resource);
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
        self::assertSame([['id', 'certificate_id'], 'then'], [$tables->relation, $tables->optional]);
        self::assertInstanceOf(InsertTableNode::class, $tables->then);
        self::assertSame('certificate_details', $tables->then->entity::TABLE);
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
        new InsertPlanner()->plan(resource: ProviderResource::class, roots: [['type' => 'provider', 'lid' => 'p1']]);
    }

    public function testExecutesPlanWithRouteLink(): void
    {
        // POST /certificates/3/domains
        $document = ['data' => [
            ['type' => 'domain', 'lid' => 'd1', 'attributes' => ['domain_name' => 'p1.test']],
            ['type' => 'domain', 'lid' => 'd2', 'attributes' => ['domain_name' => 'p2.test']],
        ]];
        $roots = new DocumentParser()->parse(document: $document, resource: DomainResource::class);
        $planner = new InsertPlanner();

        $this->expectErrorLog();
        $keys = new WriterBuilder(pdo: $this->pdo)->document(
            plan: $planner->plan(resource: DomainResource::class, roots: $roots),
            roots: $roots,
            link: $planner->routeLink(parent: CertificateResource::class, relationship: 'domains', id: 3),
        )->execute();

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
            roots: new DocumentParser()->parse(document: $document, resource: $resource),
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
