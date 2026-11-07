<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test;

use Ggs\JsonApiEngine\Document\ResourceRegistry;
use Ggs\JsonApiEngine\Example\Controller\CertificatesController;
use Ggs\JsonApiEngine\Example\Resource\CertificateResource;
use Ggs\JsonApiEngine\Example\Resource\DeliveryResource;
use Ggs\JsonApiEngine\Example\Resource\DomainResource;
use Ggs\JsonApiEngine\Executor\ExecutorBuilder;
use Ggs\JsonApiEngine\Executor\HashJoinIterator;
use Ggs\JsonApiEngine\Plan\HashJoinNode;
use Ggs\JsonApiEngine\Plan\HashNestJoinNode;
use Ggs\JsonApiEngine\Plan\JoinNode;
use Ggs\JsonApiEngine\Plan\ResourceNode;
use Ggs\JsonApiEngine\Plan\Select\BuildSqlPass;
use Ggs\JsonApiEngine\Plan\SharedRowJoinNode;
use Ggs\JsonApiEngine\Plan\SharedScanNode;
use LogicException;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../example/db/connection.php';

/**
 * Запрос → план → `ExecutorBuilder` → документ на SQLite базе `example/db`.
 *
 * Для `GET /certificates?include=provider,detail,delivery,domains.logs` (дерево `example/document.php`) дерево
 * итераторов по плану совпадает по топологии с собранным руками (`DocumentTreeTest::tree()`), а документ —
 * с `Fixture/document.json`, включая порядок. Отдельно: ветка 1:N внутри строки (`HashJoinIterator`), фильтр,
 * повторное выполнение плана и ошибки сборки.
 */
final class PlanTreeTest extends TestCase
{
    private const array EXAMPLE = ['include' => 'provider,detail,delivery,domains.logs'];

    public function testDocumentMatchesFixture(): void
    {
        $document = (new CertificatesController(pdo: getPDO()))->get(query: self::EXAMPLE);

        self::assertSame(
            json_decode((string) file_get_contents(__DIR__ . '/Fixture/document.json'), true),
            json_decode(json_encode($document, \JSON_THROW_ON_ERROR), true),
        );
    }

    public function testTopologyMatchesHandMadeTree(): void
    {
        $plan = (new CertificatesController(pdo: getPDO()))->plan(query: self::EXAMPLE);
        $built = (new ExecutorBuilder(pdo: getPDO()))->build(plan: $plan, registry: new ResourceRegistry());

        self::assertSame(
            self::topology(DocumentTreeTest::tree(getPDO(), new ResourceRegistry())->explain()),
            self::topology($built->explain()),
        );
    }

    /**
     * План переиспользуется: каждое выполнение — новое дерево итераторов и новый реестр.
     */
    public function testPlanRunsTwice(): void
    {
        $plan = (new CertificatesController(pdo: getPDO()))->plan(query: self::EXAMPLE);
        $builder = new ExecutorBuilder(pdo: getPDO());

        self::assertSame(
            json_encode($builder->document(plan: $plan), \JSON_THROW_ON_ERROR),
            json_encode($builder->document(plan: $plan), \JSON_THROW_ON_ERROR),
        );
    }

    public function testFilterReachesDocument(): void
    {
        $document = self::decoded((new CertificatesController(pdo: getPDO()))->get(query: [
            'include' => 'delivery',
            'filter' => ['status' => 'ACTIVE', 'delivery.is_sent' => 'true'],
        ]));

        $expected = (int) getPDO()->query(
            "select count(*) from certificates c left join certificate_delivery_meta d on c.id = d.certificate_id"
            . " where c.status = 'ACTIVE' and (d.certificate_id is null or d.is_sent = 1)",
        )->fetchColumn();
        self::assertGreaterThan(0, $expected);
        self::assertCount($expected, $document['data']);
        foreach ($document['data'] as $certificate) {
            self::assertSame('ACTIVE', $certificate['attributes']['status']);
        }
    }

    /**
     * Ветка 1-1 ресурса внутри строки: HashJoinNode → HashJoinIterator. У каждой доставки — домены её сертификата
     * (relation доставки — certificate_id), строка при этом не разъезжается: связи detail нет, delivery — у всех.
     */
    public function testHashJoinInsideRow(): void
    {
        $plan = (new BuildSqlPass())(plan: new SharedScanNode(input: new SharedRowJoinNode(
            name: CertificateResource::DELIVERY,
            relation: ['id', 'certificate_id'],
            outer: new ResourceNode(resource: CertificateResource::class, fields: ['code']),
            inner: new HashJoinNode(
                name: 'domains',
                relation: ['certificate_id', 'certificate_id'],
                outer: new ResourceNode(resource: DeliveryResource::class, fields: ['is_sent']),
                inner: new SharedScanNode(input: new ResourceNode(resource: DomainResource::class, fields: ['domain_name'])),
            ),
        )));
        $builder = new ExecutorBuilder(pdo: getPDO());

        $explain = $builder->build(plan: $plan, registry: new ResourceRegistry())->explain();
        self::assertSame(HashJoinIterator::class, $explain['source']['inner']['node']);

        $document = self::decoded($builder->document(plan: $plan));
        self::assertCount(20, $document['data']);

        $domains = [];
        foreach (getPDO()->query('select id, certificate_id from domains order by id') as $row) {
            $domains[(int) $row['certificate_id']][] = ['id' => (string) $row['id'], 'type' => DomainResource::TYPE];
        }
        $deliveries = 0;
        foreach ($document['included'] as $resource) {
            if ($resource['type'] === DeliveryResource::TYPE) {
                $deliveries++;
                self::assertSame($domains[(int) $resource['id']] ?? [], $resource['relationships']['domains']['data']);
            }
        }
        self::assertSame(20, $deliveries);
    }

    public function testPlanWithoutSqlStepFails(): void
    {
        $this->expectException(LogicException::class);
        (new ExecutorBuilder(pdo: getPDO()))->build(
            plan: new SharedScanNode(input: new ResourceNode(resource: CertificateResource::class)),
            registry: new ResourceRegistry(),
        );
    }

    public function testResourceOutsideQueryOwnerFails(): void
    {
        $this->expectException(LogicException::class);
        (new ExecutorBuilder(pdo: getPDO()))->build(
            plan: new ResourceNode(resource: CertificateResource::class),
            registry: new ResourceRegistry(),
        );
    }

    public function testNestJoinInsideRowFails(): void
    {
        $plan = (new BuildSqlPass())(plan: new SharedScanNode(input: new SharedRowJoinNode(
            name: CertificateResource::DELIVERY,
            relation: ['id', 'certificate_id'],
            outer: new ResourceNode(resource: CertificateResource::class),
            inner: new HashJoinNode(
                name: 'domains',
                relation: ['certificate_id', 'certificate_id'],
                outer: new ResourceNode(resource: DeliveryResource::class),
                inner: new SharedScanNode(input: new ResourceNode(resource: DomainResource::class)),
            ),
        )));
        self::assertInstanceOf(SharedScanNode::class, $plan);
        self::assertInstanceOf(SharedRowJoinNode::class, $plan->input);
        self::assertInstanceOf(JoinNode::class, $plan->input->inner);
        // Тот же план, но 1:N внутри строки — HashNestJoinNode (SharedScanPass так не оставляет)
        $inner = $plan->input->inner;
        $broken = $plan->withInput(input: $plan->input->withInner(inner: new HashNestJoinNode(
            name: $inner->name,
            relation: $inner->relation,
            outer: $inner->outer,
            inner: $inner->inner,
        )));

        $this->expectException(LogicException::class);
        (new ExecutorBuilder(pdo: getPDO()))->build(plan: $broken, registry: new ResourceRegistry());
    }

    /**
     * Топология из explain(): классы узлов, имена связей, ключи и ресурсы — без SQL и карт колонок.
     *
     * @param array<string, mixed> $explain
     * @return array<string, mixed>
     */
    private static function topology(array $explain): array
    {
        $result = [];
        foreach (['node', 'name', 'outer_key', 'inner_key', 'resource'] as $key) {
            if (isset($explain[$key])) {
                $result[$key] = $explain[$key];
            }
        }
        foreach (['data', 'source', 'outer', 'inner', 'preload'] as $key) {
            if (isset($explain[$key])) {
                $result[$key] = self::topology($explain[$key]);
            }
        }

        return $result;
    }

    /**
     * @return array{data: list<array<string, mixed>>, included: list<array<string, mixed>>}
     */
    private static function decoded(\JsonSerializable $document): array
    {
        return json_decode(json_encode($document, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);
    }
}
