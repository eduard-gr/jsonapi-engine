<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test;

use Ggs\JsonApiEngine\Document\ResourceRegistry;
use Ggs\JsonApiEngine\Example\Container\ArrayContainer;
use Ggs\JsonApiEngine\Example\Container\ExampleResources;
use Ggs\JsonApiEngine\Example\Controller\CertificatesController;
use Ggs\JsonApiEngine\Example\Resource\CertificateResource;
use Ggs\JsonApiEngine\Example\Resource\DeliveryResource;
use Ggs\JsonApiEngine\Example\Resource\DomainResource;
use Ggs\JsonApiEngine\Executor\Select\HashJoinReader;
use Ggs\JsonApiEngine\Executor\Select\ReaderBuilder;
use Ggs\JsonApiEngine\Executor\Select\SerializeReader;
use Ggs\JsonApiEngine\Plan\Select\BuildSqlPass;
use Ggs\JsonApiEngine\Plan\Select\HashJoinNode;
use Ggs\JsonApiEngine\Plan\Select\HashNestJoinNode;
use Ggs\JsonApiEngine\Plan\Select\JoinNode;
use Ggs\JsonApiEngine\Plan\Select\ResourceNode;
use Ggs\JsonApiEngine\Plan\Select\EntitySharedJoinNode;
use Ggs\JsonApiEngine\Plan\Select\EntityScanNodeDraft;
use Ggs\JsonApiEngine\Plan\Select\EntityScanNode;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Container\NotFoundExceptionInterface;

require_once __DIR__ . '/../example/db/connection.php';

/**
 * Запрос → план → `ReaderBuilder` → документ на SQLite базе `example/db`.
 *
 * Для `GET /certificates?include=provider,detail,delivery,domains.logs` (дерево `example/document.php`) дерево
 * читателей по плану совпадает по топологии с собранным руками (`DocumentTreeTest::getTree()`), а документ —
 * с `Fixture/document.json`, включая порядок. Отдельно: ветка 1:N внутри строки (`HashJoinReader`), фильтр,
 * повторное выполнение плана и ошибки сборки.
 */
final class PlanTreeTest extends TestCase
{
    private const array EXAMPLE = ['include' => 'provider,detail,delivery,domains.logs'];

    public function testDocumentMatchesFixture(): void
    {
        $document = (new CertificatesController(pdo: getPDO(), container: new ArrayContainer(services: ExampleResources::getAll())))->get(query: self::EXAMPLE);

        self::assertSame(
            json_decode((string) file_get_contents(__DIR__ . '/Fixture/document.json'), true),
            json_decode(json_encode($document, \JSON_THROW_ON_ERROR), true),
        );
    }

    public function testTopologyMatchesHandMadeTree(): void
    {
        $plan = (new CertificatesController(pdo: getPDO(), container: new ArrayContainer(services: ExampleResources::getAll())))->plan(query: self::EXAMPLE);
        $built = (new ReaderBuilder(pdo: getPDO(), container: new ArrayContainer(services: ExampleResources::getAll())))->build(plan: $plan, registry: new ResourceRegistry());

        self::assertSame(
            self::getTopology(DocumentTreeTest::getTree(getPDO(), new ResourceRegistry())->explain()),
            self::getTopology($built->explain()),
        );
    }

    /**
     * План переиспользуется: каждое выполнение — новое дерево читателей и новый реестр.
     */
    public function testPlanRunsTwice(): void
    {
        $plan = (new CertificatesController(pdo: getPDO(), container: new ArrayContainer(services: ExampleResources::getAll())))->plan(query: self::EXAMPLE);
        $builder = new ReaderBuilder(pdo: getPDO(), container: new ArrayContainer(services: ExampleResources::getAll()));

        self::assertSame(
            json_encode($builder->getDocument(plan: $plan), \JSON_THROW_ON_ERROR),
            json_encode($builder->getDocument(plan: $plan), \JSON_THROW_ON_ERROR),
        );
    }

    public function testFilterReachesDocument(): void
    {
        $document = self::getDecoded((new CertificatesController(pdo: getPDO(), container: new ArrayContainer(services: ExampleResources::getAll())))->get(query: [
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
     * Ветка 1-1 ресурса внутри строки: HashJoinNode → HashJoinReader. У каждой доставки — домены её сертификата
     * (relation доставки — certificate_id), строка при этом не разъезжается: связи detail нет, delivery — у всех.
     */
    public function testHashJoinInsideRow(): void
    {
        $plan = (new BuildSqlPass())(plan: new EntityScanNodeDraft(input: new EntitySharedJoinNode(
            name: CertificateResource::DELIVERY,
            relation: ['id', 'certificate_id'],
            outer: new ResourceNode(resource: CertificateResource::class, fields: ['code']),
            inner: new HashJoinNode(
                name: 'domains',
                relation: ['certificate_id', 'certificate_id'],
                outer: new ResourceNode(resource: DeliveryResource::class, fields: ['is_sent']),
                inner: new EntityScanNodeDraft(input: new ResourceNode(resource: DomainResource::class, fields: ['domain_name'])),
            ),
        )));
        $builder = new ReaderBuilder(pdo: getPDO(), container: new ArrayContainer(services: ExampleResources::getAll()));

        $explain = $builder->build(plan: $plan, registry: new ResourceRegistry())->explain();
        self::assertSame(HashJoinReader::class, $explain['source']['inner']['node']);

        $document = self::getDecoded($builder->getDocument(plan: $plan));
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
        (new ReaderBuilder(pdo: getPDO(), container: new ArrayContainer(services: ExampleResources::getAll())))->build(
            plan: new EntityScanNodeDraft(input: new ResourceNode(resource: CertificateResource::class)),
            registry: new ResourceRegistry(),
        );
    }

    /**
     * Ресурс сборщик берёт из контейнера зависимостей: ресурса в контейнере нет — ошибка при сборке, до выполнения.
     */
    public function testResourceMissingInContainerFails(): void
    {
        $plan = (new CertificatesController(pdo: getPDO(), container: new ArrayContainer(services: [])))->plan(query: self::EXAMPLE);

        $this->expectException(NotFoundExceptionInterface::class);
        (new ReaderBuilder(pdo: getPDO(), container: new ArrayContainer(services: [])))->build(
            plan: $plan,
            registry: new ResourceRegistry(),
        );
    }

    public function testResourceOutsideQueryOwnerFails(): void
    {
        $this->expectException(LogicException::class);
        (new ReaderBuilder(pdo: getPDO(), container: new ArrayContainer(services: ExampleResources::getAll())))->build(
            plan: new ResourceNode(resource: CertificateResource::class),
            registry: new ResourceRegistry(),
        );
    }

    public function testNestJoinInsideRowFails(): void
    {
        $plan = (new BuildSqlPass())(plan: new EntityScanNodeDraft(input: new EntitySharedJoinNode(
            name: CertificateResource::DELIVERY,
            relation: ['id', 'certificate_id'],
            outer: new ResourceNode(resource: CertificateResource::class),
            inner: new HashJoinNode(
                name: 'domains',
                relation: ['certificate_id', 'certificate_id'],
                outer: new ResourceNode(resource: DeliveryResource::class),
                inner: new EntityScanNodeDraft(input: new ResourceNode(resource: DomainResource::class)),
            ),
        )));
        self::assertInstanceOf(EntityScanNode::class, $plan);
        self::assertInstanceOf(EntitySharedJoinNode::class, $plan->input);
        self::assertInstanceOf(JoinNode::class, $plan->input->inner);
        // Тот же план, но 1:N внутри строки — HashNestJoinNode (EntityScanNodeDraftPass так не оставляет)
        $inner = $plan->input->inner;
        // EntityScanNode неизменяем и без with…(): испорченную копию собираем конструктором
        $broken = new EntityScanNode(
            input: $plan->input->withInner(inner: new HashNestJoinNode(
                name: $inner->name,
                relation: $inner->relation,
                outer: $inner->outer,
                inner: $inner->inner,
            )),
            order: $plan->order,
            select: $plan->select,
            columns: $plan->columns,
            parameters: $plan->parameters,
        );

        $this->expectException(LogicException::class);
        (new ReaderBuilder(pdo: getPDO(), container: new ArrayContainer(services: ExampleResources::getAll())))->build(plan: $broken, registry: new ResourceRegistry());
    }

    /**
     * Топология из explain(): классы узлов, имена связей, ключи (в том числе ключ проверки порядка; строгая она или нет, видно по классу узла)
     * и ресурсы — без SQL и карт колонок.
     *
     * @param array<string, mixed> $explain
     * @return array<string, mixed>
     */
    private static function getTopology(array $explain): array
    {
        $result = [];
        foreach (['node', 'name', 'outer_key', 'inner_key', 'resource', 'key'] as $key) {
            if (isset($explain[$key])) {
                $result[$key] = $explain[$key];
            }
        }
        foreach (['data', 'source', 'outer', 'inner', 'preload'] as $key) {
            if (isset($explain[$key])) {
                $result[$key] = self::getTopology($explain[$key]);
            }
        }

        return $result;
    }

    /**
     * @return array{data: list<array<string, mixed>>, included: list<array<string, mixed>>}
     */
    private static function getDecoded(\JsonSerializable $document): array
    {
        return json_decode(json_encode($document, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);
    }
}
