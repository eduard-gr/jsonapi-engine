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
use Ggs\JsonApiEngine\Executor\Select\AscendingKeyReader;
use Ggs\JsonApiEngine\Executor\Select\HashNestJoinReader;
use Ggs\JsonApiEngine\Executor\Select\SerializeReader;
use Ggs\JsonApiEngine\Executor\Select\ManualSerializeReader;
use Ggs\JsonApiEngine\Executor\Select\MergeNestJoinReader;
use Ggs\JsonApiEngine\Executor\Select\PreloadReader;
use Ggs\JsonApiEngine\Executor\Select\RegisterReader;
use Ggs\JsonApiEngine\Executor\Select\EntitySharedWindowReader;
use Ggs\JsonApiEngine\Executor\Select\EntitySharedJoinReader;
use Ggs\JsonApiEngine\Executor\Select\EntitySharedAdvanceReader;
use Ggs\JsonApiEngine\Executor\Select\EntityScanReader;
use Ggs\JsonApiEngine\Executor\Select\UniqueAscendingKeyReader;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../example/db/connection.php';

/**
 * Дерево узлов из `example/document.php` на SQLite базе `example/db`:
 * `GET /certificates?include=provider,detail,delivery,domains.logs`.
 *
 * Ресурсы собирает `SerializeReader` по описанию классов `example/Resource` (сертификат со своим `serialize()` —
 * `ManualSerializeReader`); где в строке запросов
 * `QUERY1`–`QUERY4` лежат колонки ресурса, говорят написанные руками записи колонок (`QUERY1_CERTIFICATE` и другие
 * в `example/db/connection.php`).
 * Эталон `Fixture/document.json` — вывод `example/document.php` (он же вывод прототипа); данные базы
 * детерминированы (`example/db/seed.php`).
 */
final class DocumentTreeTest extends TestCase
{
    public function testDocumentMatchesFixture(): void
    {
        self::assertJsonStringEqualsJsonFile(
            __DIR__ . '/Fixture/document.json',
            json_encode(self::getDocument(), \JSON_THROW_ON_ERROR),
        );
    }

    public function testEachResourceAppearsOnce(): void
    {
        $document = self::getDecoded();
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
     * Связь по внешнему ключу пишет сериализатор сертификата из `provider_id`, а `PreloadReader` только загружает
     * провайдеров: у каждого сертификата провайдер есть и в связи, и в `included`.
     */
    public function testProviderComesFromForeignKey(): void
    {
        $document = self::getDecoded();
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
     * Без `PreloadReader` связь `provider` остаётся (она из строки сертификата), а провайдеров в `included` нет.
     */
    public function testProviderWithoutInclude(): void
    {
        $document = self::getDecodedDocument(self::getCertificates(...), QUERY1);

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

        $document = self::getDecodedDocument(self::getCertificates(...), $sql);

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
        $explain = self::getTree(getPDO(), new ResourceRegistry())->explain();

        self::assertSame(HashNestJoinReader::class, $explain['node']);
        self::assertSame(CertificateResource::DOMAINS, $explain['name']);
        self::assertSame(PreloadReader::class, $explain['outer']['node']);
        self::assertSame(EntitySharedAdvanceReader::class, $explain['outer']['source']['node']);
        self::assertSame(EntitySharedJoinReader::class, $explain['outer']['source']['source']['node']);
        self::assertSame(CertificateResource::DELIVERY, $explain['outer']['source']['source']['name']);
        self::assertSame(MergeNestJoinReader::class, $explain['inner']['node']);
        self::assertSame(DomainResource::LOGS, $explain['inner']['name']);
    }

    private static function getDocument(): Document
    {
        $registry = new ResourceRegistry();

        return new Document(self::getTree(getPDO(), $registry), $registry);
    }

    /**
     * @return array{data: list<array<string, mixed>>, included: list<array<string, mixed>>}
     */
    private static function getDecoded(): array
    {
        return json_decode(json_encode(self::getDocument(), \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);
    }

    /**
     * Документ по поддереву сертификатов без провайдеров и доменов.
     *
     * @param \Closure(\PDO, ResourceRegistry, string): EntitySharedAdvanceReader $certificates
     * @return array{data: list<array<string, mixed>>, included: list<array<string, mixed>>}
     */
    private static function getDecodedDocument(\Closure $certificates, string $sql): array
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
     * Публичный: `PlanTreeTest` сравнивает с ним топологию дерева, которое `ReaderBuilder` собирает по плану.
     */
    public static function getTree(\PDO $pdo, ResourceRegistry $registry): HashNestJoinReader
    {
        return new HashNestJoinReader(
            outer: new PreloadReader(
                source: self::getCertificates($pdo, $registry),
                preload: new RegisterReader(
                    source: new SerializeReader(
                        source: new EntityScanReader(pdo: $pdo, sql: QUERY4),
                        resource: new ProviderResource(),
                        columns: QUERY4_PROVIDER,
                    ),
                    registry: $registry,
                ),
            ),
            inner: new MergeNestJoinReader(
                outer: new UniqueAscendingKeyReader(
                    source: new RegisterReader(
                        source: new SerializeReader(
                            source: new EntityScanReader(pdo: $pdo, sql: QUERY2),
                            resource: new DomainResource(),
                            columns: QUERY2_DOMAIN,
                        ),
                        registry: $registry,
                    ),
                    key: 'id',
                ),
                inner: new AscendingKeyReader(
                    source: new RegisterReader(
                        source: new SerializeReader(
                            source: new EntityScanReader(pdo: $pdo, sql: QUERY3),
                            resource: new LogResource(),
                            columns: QUERY3_LOG,
                        ),
                        registry: $registry,
                    ),
                    key: 'domain_id',
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
    private static function getCertificates(\PDO $pdo, ResourceRegistry $registry, string $sql = QUERY1): EntitySharedAdvanceReader
    {
        $data = new EntitySharedWindowReader(source: new EntityScanReader(pdo: $pdo, sql: $sql));

        return new EntitySharedAdvanceReader(
            data: $data,
            source: new EntitySharedJoinReader(
                outer: new EntitySharedJoinReader(
                    outer: new RegisterReader(
                        source: new ManualSerializeReader(
                            source: $data,
                            resource: new CertificateResource(),
                            columns: QUERY1_CERTIFICATE,
                        ),
                        registry: $registry,
                    ),
                    inner: new RegisterReader(
                        source: new SerializeReader(
                            source: $data,
                            resource: new DetailResource(),
                            columns: QUERY1_DETAIL,
                        ),
                        registry: $registry,
                    ),
                    name: CertificateResource::DETAIL,
                ),
                inner: new RegisterReader(
                    source: new SerializeReader(
                        source: $data,
                        resource: new DeliveryResource(),
                        columns: QUERY1_DELIVERY,
                    ),
                    registry: $registry,
                ),
                name: CertificateResource::DELIVERY,
            ),
        );
    }
}
