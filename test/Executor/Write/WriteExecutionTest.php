<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Executor\Write;

use Ggs\JsonApiEngine\Example\Resource\CertificateResource;
use Ggs\JsonApiEngine\Example\Resource\CertificateWithDetailResource;
use Ggs\JsonApiEngine\Example\Resource\DomainResource;
use Ggs\JsonApiEngine\Executor\Write\RouteParentWriter;
use Ggs\JsonApiEngine\Executor\Write\WriteNotFoundException;
use Ggs\JsonApiEngine\Executor\Write\WriterBuilder;
use Ggs\JsonApiEngine\Parser\Write\WriteDocumentParser;
use Ggs\JsonApiEngine\Parser\Write\WriteOperation;
use Ggs\JsonApiEngine\Plan\Write\WritePlanner;
use Ggs\JsonApiEngine\Resource;
use PDO;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../example/db/connection.php';

/**
 * Запись целиком на SQLite базе example/db (каждый тест — в транзакции с откатом): документ → `WriteDocumentParser` →
 * `WritePlanner` → `WriterBuilder` → строки в базе. PATCH (обе таблицы модели, замена to-one, частичное изменение и
 * полная замена to-many, отсутствующая строка необязательной таблицы), POST (дерево, вложенный маршрут), DELETE
 * (каскад базы), 404 при выполнении.
 *
 * Сертификат 1 в базе примера: номинал 1000, детали, доставка EMAIL, домены 1–3; домен 4 — другого сертификата.
 */
final class WriteExecutionTest extends TestCase
{
    private PDO $pdo;

    #[\Override]
    protected function setUp(): void
    {
        $this->pdo = getPDO();
        $this->pdo->beginTransaction();
        // Исполнители пишут каждый запрос в error_log(): PHPUnit перехватывает его и не печатает
        $this->expectErrorLog();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->pdo->rollBack();
    }

    public function testPatchWritesBothTablesAndRelationships(): void
    {
        $keys = $this->patch(data: [
            'attributes' => ['nominal' => '2500.00', 'buyer_email' => 'new@t.test', 'recipient_name' => null],
            'relationships' => [
                'delivery' => ['data' => ['type' => 'delivery', 'lid' => 'del1']],
                'domains' => ['data' => [
                    ['type' => 'domain', 'id' => '3'],
                    ['type' => 'domain', 'id' => '2', 'meta' => ['op' => 'remove']],
                    ['type' => 'domain', 'lid' => 'd1'],
                ]],
            ],
        ], included: [
            ['type' => 'domain', 'id' => '3', 'attributes' => ['is_primary' => true]],
            // имя удалённого домена 2: удаления идут раньше вставок, уникальное значение уже свободно
            ['type' => 'domain', 'lid' => 'd1', 'attributes' => ['domain_name' => 'www.green1.net']],
            ['type' => 'delivery', 'lid' => 'del1', 'attributes' => ['delivery_channel' => 'SMS']],
        ]);

        self::assertSame(
            ['nominal' => '2500.00', 'id' => 1, 'buyer_email' => 'new@t.test', 'recipient_name' => null, 'certificate_id' => 1],
            $keys,
        );
        self::assertSame(
            [['nominal' => 2500, 'buyer_email' => 'new@t.test', 'recipient_name' => null]],
            $this->rows(
                'select c.nominal, d.buyer_email, d.recipient_name from certificates c
                 join certificate_details d on d.certificate_id = c.id where c.id = 1',
            ),
        );
        self::assertSame(
            [['delivery_channel' => 'SMS', 'external_provider_id' => null]],
            $this->rows('select delivery_channel, external_provider_id from certificate_delivery_meta where certificate_id = 1'),
        );
        // домен 1 не назван — остался: в списке есть пометка meta.op, изменение частичное
        self::assertSame(
            [['domain_name' => 'green1.net', 'is_primary' => 1], ['domain_name' => 'cdn.green1.net', 'is_primary' => 1], ['domain_name' => 'www.green1.net', 'is_primary' => 0]],
            $this->rows('select domain_name, is_primary from domains where certificate_id = 1 order by id'),
        );
        self::assertSame([], $this->rows('select id from domains where id = 2'));
    }

    public function testUnchangedValuesAreFoundButNotRewritten(): void
    {
        $keys = $this->patch(data: ['attributes' => ['nominal' => '1000.00', 'buyer_email' => 'buyer1@example.com']]);

        // UPDATE … IS DISTINCT FROM ничего не вернул, строки нашёл SELECT: это не 404
        self::assertSame(1, $keys['id']);
        self::assertSame(1, $keys['certificate_id']);
    }

    public function testMissingOptionalRowIsInsertedOnlyWithValues(): void
    {
        $this->pdo->exec("insert into certificates (provider_id, code, nominal) values (1, 'NO-DETAIL', '1.00')");
        $id = (string) $this->pdo->lastInsertId();
        $details = "select buyer_email, greeting_text from certificate_details where certificate_id = {$id}";

        // все присланные значения NULL — вставлять нечего
        $keys = $this->patch(data: ['attributes' => ['recipient_name' => null]], id: $id);
        self::assertSame(['id' => (int) $id], $keys);
        self::assertSame([], $this->rows($details));

        $this->patch(data: ['attributes' => ['buyer_email' => 'first@t.test', 'greeting_text' => 'Hello']], id: $id);
        self::assertSame([['buyer_email' => 'first@t.test', 'greeting_text' => 'Hello']], $this->rows($details));
    }

    public function testFullReplacementDeletesUnlistedChildren(): void
    {
        $this->patch(data: ['relationships' => ['domains' => ['data' => [
            ['type' => 'domain', 'id' => '1'],
            ['type' => 'domain', 'id' => '3'],
        ]]]]);

        self::assertSame([['id' => 1], ['id' => 3]], $this->rows('select id from domains where certificate_id = 1 order by id'));
    }

    public function testReplacementByEmptyDeletesAllChildren(): void
    {
        $this->patch(data: ['relationships' => ['delivery' => ['data' => null], 'domains' => ['data' => []]]]);

        self::assertSame([], $this->rows('select id from domains where certificate_id = 1'));
        self::assertSame([], $this->rows('select certificate_id from certificate_delivery_meta where certificate_id = 1'));
    }

    public function testForeignChildIsNotFound(): void
    {
        try {
            $this->patch(data: ['relationships' => ['domains' => ['data' => [
                ['type' => 'domain', 'id' => '4', 'meta' => ['op' => 'remove']],
            ]]]]);
            self::fail('WriteNotFoundException expected');
        } catch (WriteNotFoundException $exception) {
            self::assertSame(['type' => 'domain', 'id' => '4'], $exception->toError()['meta']);
            self::assertSame('404', $exception->toError()['status']);
        }
        // домен другого сертификата не тронут
        self::assertCount(1, $this->rows('select id from domains where id = 4'));
    }

    public function testPostInsertsTree(): void
    {
        $roots = new WriteDocumentParser()->insert(resource: CertificateWithDetailResource::class, document: [
            'data' => [
                'type' => 'certificate',
                'attributes' => ['code' => 'W-1', 'nominal' => '10.00', 'buyer_email' => 'b@t.test'],
                'relationships' => [
                    'provider' => ['data' => ['type' => 'provider', 'id' => '2']],
                    'delivery' => ['data' => ['type' => 'delivery', 'lid' => 'del1']],
                    'domains' => ['data' => [['type' => 'domain', 'lid' => 'd1'], ['type' => 'domain', 'lid' => 'd2']]],
                ],
            ],
            'included' => [
                ['type' => 'delivery', 'lid' => 'del1', 'attributes' => ['delivery_channel' => 'EMAIL']],
                ['type' => 'domain', 'lid' => 'd1', 'attributes' => ['domain_name' => 'w1.test']],
                ['type' => 'domain', 'lid' => 'd2', 'attributes' => ['domain_name' => 'w2.test']],
            ],
        ]);
        $keys = $this->execute(resource: CertificateWithDetailResource::class, roots: $roots);

        self::assertCount(1, $keys);
        $id = $keys[0]['id'];
        self::assertSame(
            [['provider_id' => 2, 'code' => 'W-1', 'status' => 'ACTIVE']],
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
            [['domain_name' => 'w1.test'], ['domain_name' => 'w2.test']],
            $this->rows("select domain_name from domains where certificate_id = {$id} order by id"),
        );
    }

    public function testNestedRouteLinksEveryRootAndChecksParentOnce(): void
    {
        // POST /certificates/3/domains: certificate_id — из маршрута, не из документа
        $roots = new WriteDocumentParser()->insert(resource: DomainResource::class, document: ['data' => [
            ['type' => 'domain', 'lid' => 'd1', 'attributes' => ['domain_name' => 'r1.test']],
            ['type' => 'domain', 'lid' => 'd2', 'attributes' => ['domain_name' => 'r2.test']],
        ]]);
        $plan = new WritePlanner()->plan(
            resource: DomainResource::class,
            roots: $roots,
            parent: CertificateResource::class,
            relationship: CertificateResource::DOMAINS,
        );
        $builder = new WriterBuilder(pdo: $this->pdo, lock: false);

        $writer = $builder->root(plan: $plan, data: $roots, route_id: 3);
        self::assertSame(RouteParentWriter::class, $writer->explain()['route']['node']);
        self::assertSame([3, 3], array_column($writer->execute(), 'certificate_id'));
        self::assertSame(
            [['certificate_id' => 3], ['certificate_id' => 3]],
            $this->rows("select certificate_id from domains where domain_name in ('r1.test', 'r2.test') order by id"),
        );

        // тот же план, другого родителя нет: 404 до первой вставки
        try {
            $builder->root(plan: $plan, data: $roots, route_id: 999)->execute();
            self::fail('WriteNotFoundException expected');
        } catch (WriteNotFoundException $exception) {
            self::assertSame(['type' => 'certificate', 'id' => '999'], $exception->toError()['meta']);
        }
        self::assertSame([], $this->rows('select id from domains where certificate_id = 999'));
    }

    public function testDeleteRemovesRowAndDatabaseCascades(): void
    {
        $root = new WriteDocumentParser()->delete(resource: CertificateWithDetailResource::class, id: '1');
        $keys = $this->execute(resource: CertificateWithDetailResource::class, roots: [$root]);

        self::assertSame([['id' => 1]], $keys);
        self::assertSame([], $this->rows('select id from certificates where id = 1'));
        self::assertSame([], $this->rows('select certificate_id from certificate_details where certificate_id = 1'));
        self::assertSame([], $this->rows('select id from domains where certificate_id = 1'));
    }

    public function testDeleteOfMissingResourceIsNotFound(): void
    {
        $root = new WriteDocumentParser()->delete(resource: CertificateWithDetailResource::class, id: '999');

        $this->expectException(WriteNotFoundException::class);
        $this->execute(resource: CertificateWithDetailResource::class, roots: [$root]);
    }

    /**
     * `PATCH /certificates/{id}` над `CertificateWithDetailResource`.
     *
     * @return array<string, mixed> ключи корня
     */
    private function patch(array $data, array $included = [], string $id = '1'): array
    {
        $root = new WriteDocumentParser()->update(
            document: ['data' => ['type' => 'certificate', 'id' => $id, ...$data], 'included' => $included],
            resource: CertificateWithDetailResource::class,
            id: $id,
        );

        return $this->execute(resource: CertificateWithDetailResource::class, roots: [$root])[0];
    }

    /**
     * @param class-string<Resource> $resource
     * @param list<WriteOperation>   $roots
     * @return list<array<string, mixed>> ключи корней
     */
    private function execute(string $resource, array $roots): array
    {
        $plan = new WritePlanner()->plan(resource: $resource, roots: $roots);

        return new WriterBuilder(pdo: $this->pdo, lock: false)->root(plan: $plan, data: $roots)->execute();
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
