<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Parser;

use Ggs\JsonApiEngine\Example\Container\ArrayContainer;
use Ggs\JsonApiEngine\Executor\Select\ReaderBuilder;
use Ggs\JsonApiEngine\Parser\InvalidQueryException;
use Ggs\JsonApiEngine\Parser\QueryParser;
use Ggs\JsonApiEngine\Plan\Select\EntityScanNode;
use Ggs\JsonApiEngine\Plan\Select\SelectPlanner;
use Ggs\JsonApiEngine\Test\Parser\Fixture\OptionalColumnEntity;
use Ggs\JsonApiEngine\Test\Parser\Fixture\OptionalNominalResource;
use Ggs\JsonApiEngine\Test\Parser\Fixture\OptionalPrimaryResource;
use Ggs\JsonApiEngine\Test\Plan\Select\Fixture\KeyedCertificateResource;
use LogicException;
use PHPUnit\Framework\TestCase;
use sad_spirit\pg_builder\SqlBuilderWalker;

require_once __DIR__ . '/../../example/db/connection.php';

/**
 * Оператор фильтра `in` временно отключён: парсер отклоняет запрос ошибкой клиенту (400) ещё при построении дерева,
 * даже если ресурс объявил оператор в `filter`; остальные объявленные операторы принимаются.
 *
 * Набор полей по умолчанию: без `fields` клиент получает публичные атрибуты ресурса, кроме объявленных `OPTIONAL`;
 * такой атрибут отдаётся, только если назван в `fields`, а без запроса не читается и из базы.
 */
final class QueryParserTest extends TestCase
{
    public function testInOperatorIsRejectedEvenWhenResourceDeclaresIt(): void
    {
        try {
            new QueryParser()->parse(
                resource: KeyedCertificateResource::class,
                query: ['filter' => ['key_algorithm' => ['in' => 'RSA,EC']]],
            );
            self::fail('in must be rejected');
        } catch (InvalidQueryException $exception) {
            self::assertSame('Operator in is not supported yet for filter key_algorithm', $exception->getMessage());
            self::assertSame('filter[key_algorithm]', $exception->parameter);
        }
    }

    public function testOtherDeclaredOperatorIsAccepted(): void
    {
        $tree = new QueryParser()->parse(
            resource: KeyedCertificateResource::class,
            query: ['filter' => ['key_algorithm' => 'RSA']],
        );

        self::assertSame([['attribute' => 'key_algorithm', 'operator' => 'eq', 'value' => 'RSA']], $tree['filters']);
    }

    public function testOptionalAttributeIsNotInDefaultFields(): void
    {
        self::assertSame(['code', 'status', 'nominal'], OptionalNominalResource::getPublicAttributes());
        self::assertSame(['code', 'status'], OptionalNominalResource::getDefaultAttributes());
        OptionalNominalResource::validate();

        $tree = new QueryParser()->parse(resource: OptionalNominalResource::class, query: []);
        self::assertSame(['code', 'status'], $tree['fields']);

        // Колонка атрибута «только по запросу» в запрос не попадает
        $plan = new SelectPlanner()->plan(tree: $tree)['sql'];
        self::assertInstanceOf(EntityScanNode::class, $plan);
        self::assertStringNotContainsString('nominal', $plan->select->dispatch(new SqlBuilderWalker()));

        $document = self::getDocument(query: ['filter' => []]);
        self::assertSame(['code', 'status'], array_keys($document['data'][0]['attributes']));
    }

    public function testOptionalAttributeIsReturnedWhenRequested(): void
    {
        $tree = new QueryParser()->parse(
            resource: OptionalNominalResource::class,
            query: ['fields' => ['certificate' => 'code,nominal']],
        );
        self::assertSame(['code', 'nominal'], $tree['fields']);

        $document = self::getDocument(query: ['fields' => ['certificate' => 'code,nominal']]);
        self::assertSame(['code', 'nominal'], array_keys($document['data'][0]['attributes']));
    }

    public function testOptionalIsAnAttributeModeOnly(): void
    {
        try {
            OptionalPrimaryResource::validate();
            self::fail('OPTIONAL primary key must be rejected');
        } catch (LogicException $exception) {
            self::assertStringContainsString('PRIMARY attribute can not be OPTIONAL', $exception->getMessage());
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('mode must be a mask of READ, WRITE and FIXED');
        OptionalColumnEntity::validate();
    }

    /**
     * @param array<string, mixed> $query
     * @return array{data: list<array{attributes: array<string, mixed>}>}
     */
    private static function getDocument(array $query): array
    {
        $plan = new SelectPlanner()->plan(
            tree: new QueryParser()->parse(resource: OptionalNominalResource::class, query: array_filter($query)),
        )['sql'];
        $builder = new ReaderBuilder(
            pdo: getPDO(),
            container: new ArrayContainer(
                services: [new OptionalNominalResource()],
            ),
        );

        return json_decode((string) json_encode($builder->getDocument(plan: $plan)), true);
    }
}
