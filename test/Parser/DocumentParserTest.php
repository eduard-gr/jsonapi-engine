<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Parser;

use Ggs\JsonApiEngine\Example\Resource\CertificateResource;
use Ggs\JsonApiEngine\Example\Resource\DomainResource;
use Ggs\JsonApiEngine\Example\Resource\ProviderResource;
use Ggs\JsonApiEngine\Parser\DocumentParser;
use Ggs\JsonApiEngine\Parser\InvalidDocumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * DocumentParser: `data` + `included` разворачиваются по `lid` во вложенное дерево, ссылки `{type, id}` остаются
 * ссылками; ошибки клиента — InvalidDocumentException с указателем на место.
 */
final class DocumentParserTest extends TestCase
{
    public function testExpandsIncludedIntoTree(): void
    {
        $roots = new DocumentParser()->parse(document: [
            'data' => [
                'type' => 'certificate',
                'lid' => 'c1',
                'attributes' => ['code' => 'C', 'nominal' => '1.00'],
                'relationships' => [
                    'provider' => ['data' => ['type' => 'provider', 'id' => 3]],
                    'detail' => ['data' => ['type' => 'detail', 'lid' => 'det1']],
                    'domains' => ['data' => [['type' => 'domain', 'lid' => 'd1'], ['type' => 'domain', 'lid' => 'd2']]],
                ],
            ],
            'included' => [
                ['type' => 'domain', 'lid' => 'd2', 'attributes' => ['domain_name' => 'b.com']],
                ['type' => 'detail', 'lid' => 'det1', 'attributes' => ['buyer_email' => 'x@y.z']],
                ['type' => 'domain', 'lid' => 'd1', 'attributes' => ['domain_name' => 'a.com']],
            ],
        ], resource: CertificateResource::class);

        self::assertSame([[
            'type' => 'certificate',
            'lid' => 'c1',
            'attributes' => ['code' => 'C', 'nominal' => '1.00'],
            'relationships' => [
                'provider' => ['data' => ['type' => 'provider', 'id' => '3']],
                'detail' => ['data' => [
                    'type' => 'detail',
                    'lid' => 'det1',
                    'attributes' => ['buyer_email' => 'x@y.z'],
                    'relationships' => [],
                ]],
                'domains' => ['data' => [
                    ['type' => 'domain', 'lid' => 'd1', 'attributes' => ['domain_name' => 'a.com'], 'relationships' => []],
                    ['type' => 'domain', 'lid' => 'd2', 'attributes' => ['domain_name' => 'b.com'], 'relationships' => []],
                ]],
            ],
        ]], $roots);
    }

    public function testListOfRootsKeepsOrder(): void
    {
        $roots = new DocumentParser()->parse(document: ['data' => [
            ['type' => 'domain', 'lid' => 'a', 'attributes' => ['domain_name' => 'a.com']],
            ['type' => 'domain', 'lid' => 'b', 'attributes' => ['domain_name' => 'b.com']],
        ]], resource: DomainResource::class);

        self::assertSame(['a', 'b'], array_column($roots, 'lid'));
    }

    /**
     * @param class-string<\Ggs\JsonApiEngine\Resource> $resource
     */
    #[DataProvider('invalidDocuments')]
    public function testRejectsInvalidDocument(string $resource, array $document, string $pointer, string $detail): void
    {
        try {
            new DocumentParser()->parse(document: $document, resource: $resource);
            self::fail('InvalidDocumentException expected');
        } catch (InvalidDocumentException $e) {
            self::assertSame([$pointer, $detail], [$e->pointer, $e->getMessage()]);
        }
    }

    /**
     * @return iterable<string, array{string, array, string, string}>
     */
    public static function invalidDocuments(): iterable
    {
        $certificate = static fn(array $relationships = [], array $attributes = []): array => [
            'type' => 'certificate',
            'lid' => 'c1',
            'attributes' => $attributes,
            'relationships' => $relationships,
        ];
        $domain = ['type' => 'domain', 'lid' => 'd1', 'attributes' => ['domain_name' => 'a.com']];

        yield 'no data' => [CertificateResource::class, [], '', 'data is required'];
        yield 'id on new resource' => [
            CertificateResource::class,
            ['data' => ['type' => 'certificate', 'id' => '1']],
            '/data/id',
            'new resource must not have id',
        ];
        yield 'wrong root type' => [
            CertificateResource::class,
            ['data' => $domain],
            '/data/type',
            'expected type certificate, got domain',
        ];
        yield 'duplicate lid' => [
            DomainResource::class,
            ['data' => [$domain, $domain]],
            '/data/1',
            'duplicate resource domain:d1',
        ];
        yield 'lid used twice' => [
            CertificateResource::class,
            [
                'data' => $certificate(['domains' => ['data' => [
                    ['type' => 'domain', 'lid' => 'd1'],
                    ['type' => 'domain', 'lid' => 'd1'],
                ]]]),
                'included' => [$domain],
            ],
            '/data/relationships/domains/data/1',
            'resource domain:d1 is linked more than once',
        ];
        yield 'unknown lid' => [
            CertificateResource::class,
            ['data' => $certificate(['domains' => ['data' => [['type' => 'domain', 'lid' => 'd9']]]])],
            '/data/relationships/domains/data/0',
            'unknown resource domain:d9',
        ];
        yield 'unused included' => [
            CertificateResource::class,
            ['data' => $certificate(), 'included' => [$domain]],
            '/included/0',
            'resource domain:d1 is not referenced from data',
        ];
        yield 'unknown attribute' => [
            CertificateResource::class,
            ['data' => $certificate(attributes: ['color' => 'red'])],
            '/data/attributes/color',
            'unknown attribute color',
        ];
        yield 'primary key attribute' => [
            CertificateResource::class,
            ['data' => $certificate(attributes: ['id' => '5'])],
            '/data/attributes/id',
            'attribute id is read-only',
        ];
        yield 'unknown relationship' => [
            CertificateResource::class,
            ['data' => $certificate(['owner' => ['data' => null]])],
            '/data/relationships/owner',
            'unknown relationship owner',
        ];
        yield 'to-many is not a list' => [
            CertificateResource::class,
            ['data' => $certificate(['domains' => ['data' => ['type' => 'domain', 'lid' => 'd1']]])],
            '/data/relationships/domains/data',
            'expected a list',
        ];
        yield 'existing resource in to-many' => [
            CertificateResource::class,
            ['data' => $certificate(['domains' => ['data' => [['type' => 'domain', 'id' => '7']]]])],
            '/data/relationships/domains/data/0',
            'only new resources {type, lid} can be linked here',
        ];
        yield 'not insertable root' => [
            ProviderResource::class,
            ['data' => ['type' => 'provider', 'lid' => 'p1', 'attributes' => ['name' => 'P']]],
            '/data',
            'resource provider can not be created',
        ];
        yield 'new resource behind foreign key' => [
            CertificateResource::class,
            [
                'data' => $certificate(['provider' => ['data' => ['type' => 'provider', 'lid' => 'p1']]]),
                'included' => [['type' => 'provider', 'lid' => 'p1']],
            ],
            '/data/relationships/provider/data',
            'resource provider can not be created: only an existing resource {type, id} can be linked',
        ];
        yield 'not insertable child' => [
            DomainResource::class,
            [
                'data' => [...$domain, 'relationships' => ['logs' => ['data' => [['type' => 'log', 'lid' => 'l1']]]]],
                'included' => [['type' => 'log', 'lid' => 'l1']],
            ],
            '/included/0',
            'resource log can not be created',
        ];
    }
}
