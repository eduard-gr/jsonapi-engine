<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Parser;

use Ggs\JsonApiEngine\Parser\InvalidQueryException;
use Ggs\JsonApiEngine\Parser\QueryParser;
use Ggs\JsonApiEngine\Test\Plan\Select\Fixture\KeyedCertificateResource;
use PHPUnit\Framework\TestCase;

/**
 * Оператор фильтра `in` временно отключён: парсер отклоняет запрос ошибкой клиенту (400) ещё при построении дерева,
 * даже если ресурс объявил оператор в `filter`; остальные объявленные операторы принимаются.
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
}
