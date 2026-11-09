<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test;

use Ggs\JsonApiEngine\Document\Document;
use Ggs\JsonApiEngine\Document\ResourceRegistry;
use Ggs\JsonApiEngine\Example\Resource\CertificateResource;
use Ggs\JsonApiEngine\Example\Resource\DeliveryResource;
use Ggs\JsonApiEngine\Example\Resource\DetailResource;
use Ggs\JsonApiEngine\Example\Resource\DomainResource;
use Ggs\JsonApiEngine\Example\Resource\LogResource;
use Ggs\JsonApiEngine\Example\Resource\ProviderResource;
use Ggs\JsonApiEngine\Executor\Select\AscendingKeyIterator;
use Ggs\JsonApiEngine\Executor\Select\HashNestJoinIterator;
use Ggs\JsonApiEngine\Executor\Select\ManualSerializeIterator;
use Ggs\JsonApiEngine\Executor\Select\MergeNestJoinIterator;
use Ggs\JsonApiEngine\Executor\Select\PreloadIterator;
use Ggs\JsonApiEngine\Executor\Select\RegisterIterator;
use Ggs\JsonApiEngine\Executor\Select\SharedDataIterator;
use Ggs\JsonApiEngine\Executor\Select\SharedRowJoinIterator;
use Ggs\JsonApiEngine\Executor\Select\SharedScanIterator;
use Ggs\JsonApiEngine\Executor\Select\SqlScanIterator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../example/db/connection.php';

/**
 * Дерево узлов из `example/document.php` на SQLite базе `example/db`:
 * `GET /certificates?include=provider,detail,delivery,domains.logs`.
 *
 * Ресурсы собирает `serialize()` классов `example/Resource` по алиасам колонок запросов `QUERY1`–`QUERY4`.
 * Эталон `Fixture/document.json` — вывод `example/document.php` (он же вывод прототипа); данные базы
 * детерминированы (`example/db/seed.php`).
 */
final class DocumentTreeTest extends TestCase
{
    public function testDocumentMatchesFixture(): void
    {
        self::assertJsonStringEqualsJsonFile(
            __DIR__ . '/Fixture/document.json',
            json_encode(self::document(), \JSON_THROW_ON_ERROR),
        );
    }

    public function testEachResourceAppearsOnce(): void
    {
        $document = self::decoded();
        $keys = array_map(
            static fn (array $resource): string => "{$resource['type']}:{$resource['id']}",
            [...$document['data'], ...$document['included']],
        );

        self::assertSame(array_unique($keys), $keys);
        self::assertCount(20, $document['data']);
        self::assertSame(
            [
                DeliveryResource::TYPE => 20,
                DetailResource::TYPE => 20,
                DomainResource::TYPE => 52,
                LogResource::TYPE => 198,
                ProviderResource::TYPE => 4,
            ],
            self::countTypes($document['included']),
        );
    }

    /**
     * Связь по внешнему ключу пишет сериализатор сертификата из `provider_id`, а `PreloadIterator` только загружает
     * провайдеров: у каждого сертификата провайдер есть и в связи, и в `included`.
     */
    public function testProviderComesFromForeignKey(): void
    {
        $document = self::decoded();
        $providers = [];
        foreach ($document['included'] as $resource) {
            if ($resource['type'] === ProviderResource::TYPE) {
                $providers[$resource['id']] = true;
            }
        }

        foreach ($document['data'] as $certificate) {
            $provider = $certificate['relationships'][CertificateResource::PROVIDER]['data'];
            self::assertSame(ProviderResource::TYPE, $provider['type']);
            self::assertArrayHasKey($provider['id'], $providers);
        }
    }

    /**
     * Без `PreloadIterator` связь `provider` остаётся (она из строки сертификата), а провайдеров в `included` нет.
     */
    public function testProviderWithoutInclude(): void
    {
        $document = self::decodedDocument(self::certificates(...), QUERY1);

        self::assertCount(20, $document['data']);
        self::assertArrayNotHasKey(ProviderResource::TYPE, self::countTypes($document['included']));
        foreach ($document['data'] as $certificate) {
            self::assertNotNull($certificate['relationships'][CertificateResource::PROVIDER]['data']);
        }
    }

    /**
     * Сертификат без доставки (`LEFT JOIN` ничего не нашёл) остаётся в `data` со связью `delivery: {data: null}`,
     * а в `included` доставки для него нет. В базе доставка есть у всех, поэтому её часть отсекается условием в `ON`.
     */
    public function testMissingDeliveryGivesNullRelationship(): void
    {
        $sql = str_replace('cdm.certificate_id = cc.id', 'cdm.certificate_id = cc.id and cc.id % 5 <> 0', QUERY1);
        self::assertNotSame(QUERY1, $sql);

        $document = self::decodedDocument(self::certificates(...), $sql);

        self::assertCount(20, $document['data']);
        $deliveries = [];
        foreach ($document['included'] as $resource) {
            if ($resource['type'] === DeliveryResource::TYPE) {
                $deliveries[(int) $resource['id']] = true;
            }
        }
        foreach ($document['data'] as $certificate) {
            $id = (int) $certificate['id'];
            $delivery = $certificate['relationships'][CertificateResource::DELIVERY]['data'];
            if ($id % 5 === 0) {
                self::assertNull($delivery);
                self::assertArrayNotHasKey($id, $deliveries);
            } else {
                self::assertSame(['id' => (string) $id, 'type' => DeliveryResource::TYPE], $delivery);
                self::assertArrayHasKey($id, $deliveries);
            }
            self::assertNotNull($certificate['relationships'][CertificateResource::DETAIL]['data']);
        }
    }

    public function testExplainDescribesTree(): void
    {
        $explain = self::tree(getPDO(), new ResourceRegistry())->explain();

        self::assertSame(HashNestJoinIterator::class, $explain['node']);
        self::assertSame(CertificateResource::DOMAINS, $explain['name']);
        self::assertSame(PreloadIterator::class, $explain['outer']['node']);
        self::assertSame(SharedScanIterator::class, $explain['outer']['source']['node']);
        self::assertSame(SharedRowJoinIterator::class, $explain['outer']['source']['source']['node']);
        self::assertSame(CertificateResource::DELIVERY, $explain['outer']['source']['source']['name']);
        self::assertSame(MergeNestJoinIterator::class, $explain['inner']['node']);
        self::assertSame(DomainResource::LOGS, $explain['inner']['name']);
    }

    private static function document(): Document
    {
        $registry = new ResourceRegistry();

        return new Document(self::tree(getPDO(), $registry), $registry);
    }

    /**
     * @return array{data: list<array<string, mixed>>, included: list<array<string, mixed>>}
     */
    private static function decoded(): array
    {
        return json_decode(json_encode(self::document(), \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);
    }

    /**
     * Документ по поддереву сертификатов без провайдеров и доменов.
     *
     * @param \Closure(\PDO, ResourceRegistry, string): SharedScanIterator $certificates
     * @return array{data: list<array<string, mixed>>, included: list<array<string, mixed>>}
     */
    private static function decodedDocument(\Closure $certificates, string $sql): array
    {
        $registry = new ResourceRegistry();
        $document = new Document($certificates(getPDO(), $registry, $sql), $registry);

        return json_decode(json_encode($document, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<array<string, mixed>> $resources
     * @return array<string, int>
     */
    private static function countTypes(array $resources): array
    {
        $counts = array_count_values(array_column($resources, 'type'));
        ksort($counts);

        return $counts;
    }

    /**
     * Сертификаты с деталями и доставкой из одного SQL (QUERY1), провайдеры (QUERY4) загружаются в реестр,
     * домены (QUERY2) с логами (QUERY3) вкладываются в сертификаты.
     *
     * Публичный: `PlanTreeTest` сравнивает с ним топологию дерева, которое `IteratorBuilder` собирает по плану.
     */
    public static function tree(\PDO $pdo, ResourceRegistry $registry): HashNestJoinIterator
    {
        return new HashNestJoinIterator(
            outer: new PreloadIterator(
                source: self::certificates($pdo, $registry),
                preload: new RegisterIterator(
                    source: new ManualSerializeIterator(
                        source: new SqlScanIterator(pdo: $pdo, sql: QUERY4),
                        resource: ProviderResource::class,
                    ),
                    registry: $registry,
                ),
            ),
            inner: new MergeNestJoinIterator(
                outer: new AscendingKeyIterator(
                    source: new RegisterIterator(
                        source: new ManualSerializeIterator(
                            source: new SqlScanIterator(pdo: $pdo, sql: QUERY2),
                            resource: DomainResource::class,
                        ),
                        registry: $registry,
                    ),
                    key: 'id',
                    strict: true,
                ),
                inner: new AscendingKeyIterator(
                    source: new RegisterIterator(
                        source: new ManualSerializeIterator(
                            source: new SqlScanIterator(pdo: $pdo, sql: QUERY3),
                            resource: LogResource::class,
                        ),
                        registry: $registry,
                    ),
                    key: 'domain_id',
                    strict: false,
                ),
                outer_key: 'id',
                inner_key: 'domain_id',
                name: DomainResource::LOGS,
            ),
            outer_key: 'id',
            inner_key: 'certificate_id',
            name: CertificateResource::DOMAINS,
        );
    }

    /**
     * Поддерево общего SQL (QUERY1): сертификат, детали и доставка из одной строки.
     */
    private static function certificates(\PDO $pdo, ResourceRegistry $registry, string $sql = QUERY1): SharedScanIterator
    {
        $data = new SharedDataIterator(source: new SqlScanIterator(pdo: $pdo, sql: $sql));

        return new SharedScanIterator(
            data: $data,
            source: new SharedRowJoinIterator(
                outer: new SharedRowJoinIterator(
                    outer: new RegisterIterator(
                        source: new ManualSerializeIterator(
                            source: $data,
                            resource: CertificateResource::class,
                        ),
                        registry: $registry,
                    ),
                    inner: new RegisterIterator(
                        source: new ManualSerializeIterator(
                            source: $data,
                            resource: DetailResource::class,
                        ),
                        registry: $registry,
                    ),
                    name: CertificateResource::DETAIL,
                ),
                inner: new RegisterIterator(
                    source: new ManualSerializeIterator(
                        source: $data,
                        resource: DeliveryResource::class,
                    ),
                    registry: $registry,
                ),
                name: CertificateResource::DELIVERY,
            ),
        );
    }
}
