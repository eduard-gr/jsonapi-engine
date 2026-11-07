<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Plan;

use Ggs\JsonApiEngine\Example\Resource\CertificateResource;
use Ggs\JsonApiEngine\Example\Resource\DeliveryResource;
use Ggs\JsonApiEngine\Example\Resource\DetailResource;
use Ggs\JsonApiEngine\Example\Resource\DomainResource;
use Ggs\JsonApiEngine\Example\Resource\LogResource;
use Ggs\JsonApiEngine\Example\Resource\ProviderResource;
use Ggs\JsonApiEngine\Parser\QueryParser;
use Ggs\JsonApiEngine\Plan\JoinNode;
use Ggs\JsonApiEngine\Plan\ResourceNode;
use Ggs\JsonApiEngine\Plan\Select\QueryPlanner;
use Ggs\JsonApiEngine\Plan\SharedRowJoinNode;
use Ggs\JsonApiEngine\Plan\SharedScanNode;
use PHPUnit\Framework\TestCase;
use sad_spirit\pg_builder\SqlBuilderWalker;

/**
 * Запросы плана (шаг sql): список колонок (первичный ключ, колонки fields и ключи соединений каждого ресурса
 * под именами <алиас ресурса>_<колонка>), карта колонок у владельца, отсечение лишних LEFT JOIN строки и ORDER BY
 * (order, клиентский sort, первичный ключ последним).
 */
final class SelectColumnsTest extends TestCase
{
    public function testExampleQueries(): void
    {
        $owners = self::owners(query: ['include' => 'provider,detail,delivery,domains.logs']);

        self::assertSame([CertificateResource::class, ProviderResource::class, DomainResource::class, LogResource::class], array_keys($owners));

        self::assertSame(
            'select t0.id as t0_id, t0.code as t0_code, t0.nominal as t0_nominal, t0.currency as t0_currency,'
            . ' t0.status as t0_status, t0.created_at as t0_created_at, t0.provider_id as t0_provider_id,'
            . ' t1.certificate_id as t1_certificate_id, t1.buyer_email as t1_buyer_email,'
            . ' t1.recipient_name as t1_recipient_name, t1.recipient_email as t1_recipient_email,'
            . ' t1.greeting_text as t1_greeting_text, t1.design_template_id as t1_design_template_id,'
            . ' t2.certificate_id as t2_certificate_id, t2.delivery_channel as t2_delivery_channel,'
            . ' t2.is_sent as t2_is_sent, t2.external_provider_id as t2_external_provider_id'
            . ' from certificates as t0'
            . ' left join certificate_details as t1 on t0.id = t1.certificate_id'
            . ' left join certificate_delivery_meta as t2 on t0.id = t2.certificate_id'
            . ' order by t0.id',
            self::sql(owner: $owners[CertificateResource::class]),
        );
        // Ключ provider_id нужен PreloadNode над строкой, id — HashNestJoinNode domains: оба приходят сверху
        self::assertSame(
            [
                'resource' => CertificateResource::class,
                'alias' => 't0',
                'columns' => [
                    'id' => 't0_id', 'code' => 't0_code', 'nominal' => 't0_nominal', 'currency' => 't0_currency',
                    'status' => 't0_status', 'created_at' => 't0_created_at', 'provider_id' => 't0_provider_id',
                ],
                // provider — связь по внешнему ключу (ManyToOne), публичный атрибут
                'fields' => ['code', 'nominal', 'currency', 'status', 'created_at', 'provider'],
                'keys' => ['id', 'provider_id'],
            ],
            $owners[CertificateResource::class]->columns[0],
        );
        self::assertSame([DetailResource::class, 't1'], [$owners[CertificateResource::class]->columns[1]['resource'], $owners[CertificateResource::class]->columns[1]['alias']]);
        self::assertSame([DeliveryResource::class, 't2'], [$owners[CertificateResource::class]->columns[2]['resource'], $owners[CertificateResource::class]->columns[2]['alias']]);

        self::assertSame(
            'select t0.id as t0_id, t0."name" as t0_name, t0.website as t0_website, t0.created_at as t0_created_at'
            . ' from providers as t0 order by t0.id',
            self::sql(owner: $owners[ProviderResource::class]),
        );
        // certificate_id — ключ domains (inner), id — ключ logs (outer)
        self::assertSame(
            ['id' => 't0_id', 'domain_name' => 't0_domain_name', 'is_primary' => 't0_is_primary', 'created_at' => 't0_created_at', 'certificate_id' => 't0_certificate_id'],
            $owners[DomainResource::class]->columns[0]['columns'],
        );
        self::assertSame(
            ['id' => 't0_id', 'log_level' => 't0_log_level', 'message' => 't0_message', 'created_at' => 't0_created_at', 'domain_id' => 't0_domain_id'],
            $owners[LogResource::class]->columns[0]['columns'],
        );

        // MergeNestJoinNode logs: домены по id (это и первичный ключ), логи по domain_id, затем по id
        self::assertStringEndsWith(' order by t0.id', self::sql(owner: $owners[DomainResource::class]));
        self::assertStringEndsWith(' order by t0.domain_id, t0.id', self::sql(owner: $owners[LogResource::class]));
    }

    public function testClientSortOfRowOwner(): void
    {
        // sort владельца по position; сортировка 1-1 ресурса (detail) на порядок строк не влияет
        $owner = new SharedScanNode(input: new SharedRowJoinNode(
            name: 'detail',
            relation: ['id', 'certificate_id'],
            outer: new ResourceNode(resource: CertificateResource::class, sort: [
                ['attribute' => 'code', 'descending' => false, 'position' => 1],
                ['attribute' => 'created_at', 'descending' => true, 'position' => 0],
            ]),
            inner: new ResourceNode(resource: DetailResource::class, sort: [
                ['attribute' => 'buyer_email', 'descending' => false, 'position' => 2],
            ]),
        ));

        self::assertStringEndsWith(
            ' order by t0.created_at desc, t0.code, t0.id',
            self::sql(owner: $owner->withSelect()),
        );
    }

    public function testPrimaryKeyInSortIsNotRepeated(): void
    {
        $owner = new SharedScanNode(input: new ResourceNode(resource: ProviderResource::class, sort: [
            ['attribute' => 'id', 'descending' => true, 'position' => 0],
        ]));

        self::assertStringEndsWith(' order by t0.id desc', self::sql(owner: $owner->withSelect()));
    }

    public function testOnlyRequestedFieldsAndKeys(): void
    {
        $owners = self::owners(query: [
            'include' => 'delivery',
            'fields' => ['certificate' => 'code', 'delivery' => 'is_sent'],
        ]);

        // detail не подключён — его LEFT JOIN нет; ключ certificate_id delivery нужен для ON
        self::assertSame(
            'select t0.id as t0_id, t0.code as t0_code, t1.certificate_id as t1_certificate_id, t1.is_sent as t1_is_sent'
            . ' from certificates as t0 left join certificate_delivery_meta as t1 on t0.id = t1.certificate_id'
            . ' order by t0.id',
            self::sql(owner: $owners[CertificateResource::class]),
        );
    }

    /**
     * Владельцы запросов плана после шага sql по классу ресурса-владельца.
     *
     * @param array<string, mixed> $query
     * @return array<class-string, SharedScanNode>
     */
    private static function owners(array $query): array
    {
        $tree = (new QueryParser())->parse(resource: CertificateResource::class, query: $query);
        $owners = [];
        self::collect(node: (new QueryPlanner())->plan(tree: $tree)['sql'], owners: $owners);

        return $owners;
    }

    /**
     * @param array<class-string, SharedScanNode> $owners
     */
    private static function collect(ResourceNode|SharedScanNode|JoinNode $node, array &$owners): void
    {
        if ($node instanceof SharedScanNode) {
            $owners[$node->rowOwner()->resource] = $node;
            self::collect(node: $node->input, owners: $owners);
        } elseif ($node instanceof JoinNode) {
            self::collect(node: $node->outer, owners: $owners);
            self::collect(node: $node->inner, owners: $owners);
        }
    }

    private static function sql(SharedScanNode $owner): string
    {
        self::assertNotNull($owner->select);

        return (string) preg_replace('/\s+/', ' ', $owner->select->dispatch(new SqlBuilderWalker()));
    }
}
