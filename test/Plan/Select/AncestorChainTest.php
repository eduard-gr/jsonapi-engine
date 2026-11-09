<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Plan\Select;

use Ggs\JsonApiEngine\Example\Controller\CertificatesController;
use Ggs\JsonApiEngine\Example\Resource\CertificateResource;
use Ggs\JsonApiEngine\Example\Resource\DeliveryResource;
use Ggs\JsonApiEngine\Example\Resource\DomainResource;
use Ggs\JsonApiEngine\Example\Resource\LogResource;
use Ggs\JsonApiEngine\Example\Resource\ProviderResource;
use Ggs\JsonApiEngine\Executor\Select\SqlScanIterator;
use Ggs\JsonApiEngine\Plan\Select\BuildSqlPass;
use Ggs\JsonApiEngine\Plan\Select\HashJoinNode;
use Ggs\JsonApiEngine\Plan\Select\JoinNode;
use Ggs\JsonApiEngine\Plan\Select\ResourceNode;
use Ggs\JsonApiEngine\Plan\Select\SharedRowJoinNode;
use Ggs\JsonApiEngine\Plan\Select\SharedScanNode;
use Ggs\JsonApiEngine\Plan\Select\SqlScanNode;
use PHPUnit\Framework\TestCase;
use sad_spirit\pg_builder\SqlBuilderWalker;

require_once __DIR__ . '/../../../example/db/connection.php';

/**
 * Цепочка предков (шаг sql): дочерний запрос повторяет условия родителей через WITH p1, p2, … и
 * relation[1] IN (select key from p1). Тело CTE — FROM строки родителя как есть и её WHERE целиком,
 * без ORDER BY; параметры предков — с префиксом CTE. PreloadNode цепочки не получает. Запросы выполняются
 * на SQLite примера и сравниваются с теми же условиями, записанными руками.
 */
final class AncestorChainTest extends TestCase
{
    private const array QUERY = [
        'include' => 'provider,delivery,domains.logs',
        'filter' => ['status' => 'ACTIVE', 'delivery.is_sent' => 'true'],
    ];

    /** условие сертификатов запроса QUERY, записанное руками */
    private const string CERTIFICATES = "select c.id from certificates c left join certificate_delivery_meta d on c.id = d.certificate_id"
        . " where c.status = 'ACTIVE' and (d.certificate_id is null or d.is_sent = 1)";

    public function testChildQueriesRepeatParentConditions(): void
    {
        $owners = self::owners(query: self::QUERY);
        $certificates = 'select t0.id as id from certificates as t0'
            . ' left join certificate_delivery_meta as t1 on t0.id = t1.certificate_id'
            . ' where t0.status = :%1$s_t0_status_0 and ( t1.certificate_id is null or t1.is_sent = :%1$s_t1_is_sent_1 )';

        self::assertSame(
            'with p1 as ( ' . sprintf($certificates, 'p1') . ' )'
            . ' select t0.id as t0_id, t0.domain_name as t0_domain_name, t0.is_primary as t0_is_primary,'
            . ' t0.created_at as t0_created_at, t0.certificate_id as t0_certificate_id'
            . ' from domains as t0 where t0.certificate_id in ( select id from p1 ) order by t0.id',
            self::sql(owner: $owners[DomainResource::class]),
        );
        self::assertSame(['p1_t0_status_0' => 'ACTIVE', 'p1_t1_is_sent_1' => true], $owners[DomainResource::class]->parameters);

        // Логи: дальний предок (сертификаты) объявлен первым, ближний (домены) ссылается на него
        self::assertSame(
            'with p2 as ( ' . sprintf($certificates, 'p2') . ' ),'
            . ' p1 as ( select t0.id as id from domains as t0 where t0.certificate_id in ( select id from p2 ) )'
            . ' select t0.id as t0_id, t0.log_level as t0_log_level, t0.message as t0_message,'
            . ' t0.created_at as t0_created_at, t0.domain_id as t0_domain_id'
            . ' from domain_logs as t0 where t0.domain_id in ( select id from p1 ) order by t0.domain_id, t0.id',
            self::sql(owner: $owners[LogResource::class]),
        );
        self::assertSame(['p2_t0_status_0' => 'ACTIVE', 'p2_t1_is_sent_1' => true], $owners[LogResource::class]->parameters);

        // Справочник PreloadNode читается целиком; у самого корня предков нет
        self::assertStringStartsWith('select ', self::sql(owner: $owners[ProviderResource::class]));
        self::assertStringNotContainsString(' in ( select', self::sql(owner: $owners[ProviderResource::class]));
        self::assertStringStartsWith('select ', self::sql(owner: $owners[CertificateResource::class]));
    }

    /**
     * Без фильтров CTE всё равно ставится: таблица родителя может быть view со своими условиями или таблицей
     * под RLS, и какие родители видны, знает только его запрос. Убирать CTE при пустом WHERE предка нельзя.
     */
    public function testChildQueriesKeepAncestorsWithoutFilters(): void
    {
        $owners = self::owners(query: ['include' => 'detail,delivery,domains.logs']);
        $certificates = 'select t0.id as id from certificates as t0'
            . ' left join certificate_details as t1 on t0.id = t1.certificate_id'
            . ' left join certificate_delivery_meta as t2 on t0.id = t2.certificate_id';

        self::assertSame(
            "with p1 as ( {$certificates} )"
            . ' select t0.id as t0_id, t0.domain_name as t0_domain_name, t0.is_primary as t0_is_primary,'
            . ' t0.created_at as t0_created_at, t0.certificate_id as t0_certificate_id'
            . ' from domains as t0 where t0.certificate_id in ( select id from p1 ) order by t0.id',
            self::sql(owner: $owners[DomainResource::class]),
        );
        self::assertSame(
            "with p2 as ( {$certificates} ),"
            . ' p1 as ( select t0.id as id from domains as t0 where t0.certificate_id in ( select id from p2 ) )'
            . ' select t0.id as t0_id, t0.log_level as t0_log_level, t0.message as t0_message,'
            . ' t0.created_at as t0_created_at, t0.domain_id as t0_domain_id'
            . ' from domain_logs as t0 where t0.domain_id in ( select id from p1 ) order by t0.domain_id, t0.id',
            self::sql(owner: $owners[LogResource::class]),
        );
        self::assertSame([], $owners[DomainResource::class]->parameters);
        self::assertSame([], $owners[LogResource::class]->parameters);

        // На данных примера CTE ничего не отсекает: все домены и логи
        $pdo = getPDO();
        self::assertCount((int) $pdo->query('select count(*) from domains')->fetchColumn(), self::rows(owner: $owners[DomainResource::class]));
        self::assertCount((int) $pdo->query('select count(*) from domain_logs')->fetchColumn(), self::rows(owner: $owners[LogResource::class]));
    }

    public function testChildQueriesReturnOnlyChildrenOfParents(): void
    {
        $owners = self::owners(query: self::QUERY);
        $pdo = getPDO();

        $domains = $pdo->query('select id from domains where certificate_id in (' . self::CERTIFICATES . ') order by id')
            ->fetchAll(\PDO::FETCH_COLUMN);
        self::assertNotSame([], $domains);
        self::assertLessThan((int) $pdo->query('select count(*) from domains')->fetchColumn(), \count($domains));
        self::assertSame($domains, array_column(self::rows(owner: $owners[DomainResource::class]), 't0_id'));

        $logs = $pdo->query(
            'select id from domain_logs where domain_id in (select id from domains where certificate_id in ('
            . self::CERTIFICATES . ')) order by domain_id, id',
        )->fetchAll(\PDO::FETCH_COLUMN);
        self::assertNotSame([], $logs);
        self::assertSame($logs, array_column(self::rows(owner: $owners[LogResource::class]), 't0_id'));
    }

    /**
     * Документ с фильтром: каждый домен в included — домен сертификата из data (ребёнок без родителя был бы
     * LogicException), и доменов ровно столько, сколько у этих сертификатов.
     */
    public function testDocumentWithFilterHasOnlyChildrenOfData(): void
    {
        $document = json_decode(
            json_encode((new CertificatesController(pdo: getPDO()))->get(query: self::QUERY), \JSON_THROW_ON_ERROR),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );

        $linked = [];
        foreach ($document['data'] as $certificate) {
            foreach ($certificate['relationships']['domains']['data'] as $domain) {
                $linked[] = $domain['id'];
            }
        }
        $included = [];
        foreach ($document['included'] as $resource) {
            if ($resource['type'] === DomainResource::TYPE) {
                $included[] = $resource['id'];
            }
        }
        sort($linked);
        sort($included);
        self::assertSame($linked, $included);
        self::assertCount(
            (int) getPDO()->query('select count(*) from domains where certificate_id in (' . self::CERTIFICATES . ')')->fetchColumn(),
            $included,
        );
    }

    /**
     * 1:N у 1-1 ресурса внутри строки: предок — запрос самой строки, ключ — колонка 1-1 ресурса (t1), а не
     * владельца строки.
     */
    public function testBranchInsideRowUsesKeyOfOneToOneResource(): void
    {
        $plan = (new BuildSqlPass())(plan: new SharedScanNode(input: new SharedRowJoinNode(
            name: CertificateResource::DELIVERY,
            relation: ['id', 'certificate_id'],
            outer: new ResourceNode(
                resource: CertificateResource::class,
                fields: ['code'],
                filters: [['attribute' => 'status', 'operator' => 'eq', 'value' => 'ACTIVE']],
            ),
            inner: new HashJoinNode(
                name: 'domains',
                relation: ['certificate_id', 'certificate_id'],
                outer: new ResourceNode(resource: DeliveryResource::class, fields: ['is_sent']),
                inner: new SharedScanNode(input: new ResourceNode(resource: DomainResource::class, fields: ['domain_name'])),
            ),
        )));
        self::assertInstanceOf(SqlScanNode::class, $plan);
        self::assertInstanceOf(SharedRowJoinNode::class, $plan->input);
        self::assertInstanceOf(HashJoinNode::class, $plan->input->inner);
        $domains = $plan->input->inner->inner;
        self::assertInstanceOf(SqlScanNode::class, $domains);

        self::assertSame(
            'with p1 as ( select t1.certificate_id as certificate_id from certificates as t0'
            . ' left join certificate_delivery_meta as t1 on t0.id = t1.certificate_id where t0.status = :p1_t0_status_0 )'
            . ' select t0.id as t0_id, t0.domain_name as t0_domain_name, t0.certificate_id as t0_certificate_id'
            . ' from domains as t0 where t0.certificate_id in ( select certificate_id from p1 ) order by t0.id',
            self::sql(owner: $domains),
        );

        $expected = getPDO()->query(
            "select d.id from domains d where d.certificate_id in (select m.certificate_id from certificates c"
            . " left join certificate_delivery_meta m on c.id = m.certificate_id where c.status = 'ACTIVE') order by d.id",
        )->fetchAll(\PDO::FETCH_COLUMN);
        self::assertNotSame([], $expected);
        self::assertSame($expected, array_column(self::rows(owner: $domains), 't0_id'));
    }

    /**
     * Владельцы запросов плана после шага sql по классу ресурса-владельца.
     *
     * @param array<string, mixed> $query
     * @return array<string, SqlScanNode>
     */
    private static function owners(array $query): array
    {
        $owners = [];
        self::collect(node: (new CertificatesController(pdo: getPDO()))->plan(query: $query), owners: $owners);

        return $owners;
    }

    /**
     * @param array<string, SqlScanNode> $owners
     */
    private static function collect(ResourceNode|SharedScanNode|SqlScanNode|JoinNode $node, array &$owners): void
    {
        if ($node instanceof SqlScanNode) {
            $owners[$node->rowOwner()->resource] = $node;

            return;
        }
        if ($node instanceof JoinNode) {
            self::collect(node: $node->outer, owners: $owners);
            self::collect(node: $node->inner, owners: $owners);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(SqlScanNode $owner): array
    {
        $scan = new SqlScanIterator(pdo: getPDO(), sql: $owner->select->dispatch(new SqlBuilderWalker()), parameters: $owner->parameters);

        return iterator_to_array($scan, false);
    }

    private static function sql(SqlScanNode $owner): string
    {

        return (string) preg_replace('/\s+/', ' ', $owner->select->dispatch(new SqlBuilderWalker()));
    }
}
