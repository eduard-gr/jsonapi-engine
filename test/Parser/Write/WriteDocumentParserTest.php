<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Parser\Write;

use Ggs\JsonApiEngine\Example\Resource\CertificateWithDetailResource;
use Ggs\JsonApiEngine\Example\Resource\DeliveryResource;
use Ggs\JsonApiEngine\Example\Resource\DomainResource;
use Ggs\JsonApiEngine\Example\Resource\ProviderResource;
use Ggs\JsonApiEngine\Parser\InvalidDocumentException;
use Ggs\JsonApiEngine\Parser\ResourceNotFoundException;
use Ggs\JsonApiEngine\Parser\Write\DeleteOperation;
use Ggs\JsonApiEngine\Parser\Write\InsertOperation;
use Ggs\JsonApiEngine\Parser\Write\KeepOperation;
use Ggs\JsonApiEngine\Parser\Write\LocalIdentity;
use Ggs\JsonApiEngine\Parser\Write\PersistentIdentity;
use Ggs\JsonApiEngine\Parser\Write\RelationshipOperation;
use Ggs\JsonApiEngine\Parser\Write\UpdateOperation;
use Ggs\JsonApiEngine\Parser\Write\WriteDocumentParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Парсер записи: дерево операций из `data` + `included` для POST, PATCH и DELETE (классы операций, порядок детей,
 * признак замены), ошибки клиента со статусом и указателем.
 */
final class WriteDocumentParserTest extends TestCase
{
    public function testInsertBuildsOperationTree(): void
    {
        $roots = new WriteDocumentParser()->insert(resource: CertificateWithDetailResource::class, document: [
            'data' => [
                'type' => 'certificate',
                'attributes' => ['code' => 'P-1', 'buyer_email' => 'b@t.test'],
                'relationships' => [
                    'provider' => ['data' => ['type' => 'provider', 'id' => '2']],
                    'delivery' => ['data' => ['type' => 'delivery', 'lid' => 'del1']],
                    'domains' => ['data' => [['type' => 'domain', 'lid' => 'd1'], ['type' => 'domain', 'lid' => 'd2']]],
                ],
            ],
            'included' => [
                ['type' => 'domain', 'lid' => 'd2', 'attributes' => ['domain_name' => 't2.test']],
                ['type' => 'delivery', 'lid' => 'del1', 'attributes' => ['delivery_channel' => 'EMAIL']],
                ['type' => 'domain', 'lid' => 'd1', 'attributes' => ['domain_name' => 't1.test']],
            ],
        ]);

        self::assertEquals([
            new InsertOperation(
                resource: CertificateWithDetailResource::class,
                identity: null,
                attributes: ['code' => 'P-1', 'buyer_email' => 'b@t.test'],
                references: ['provider' => new PersistentIdentity(id: 2)],
                relationships: [
                    'delivery' => new RelationshipOperation(replace: false, children: [
                        self::insert(DeliveryResource::class, 'del1', ['delivery_channel' => 'EMAIL']),
                    ]),
                    // Порядок детей — как в связи, а не как в included
                    'domains' => new RelationshipOperation(replace: false, children: [
                        self::insert(DomainResource::class, 'd1', ['domain_name' => 't1.test']),
                        self::insert(DomainResource::class, 'd2', ['domain_name' => 't2.test']),
                    ]),
                ],
            ),
        ], $roots);
    }

    public function testListOfRootsKeepsOrder(): void
    {
        $roots = new WriteDocumentParser()->insert(resource: DomainResource::class, document: ['data' => [
            ['type' => 'domain', 'lid' => 'b', 'attributes' => ['domain_name' => 'b.test']],
            ['type' => 'domain', 'attributes' => ['domain_name' => 'a.test']],
        ]]);

        self::assertEquals([
            self::insert(DomainResource::class, 'b', ['domain_name' => 'b.test']),
            self::insert(DomainResource::class, null, ['domain_name' => 'a.test']),
        ], $roots);
    }

    public function testUpdateOrdersChildrenAndMarksReplacement(): void
    {
        $root = new WriteDocumentParser()->update(resource: CertificateWithDetailResource::class, id: '1', document: [
            'data' => [
                'type' => 'certificate',
                'id' => '1',
                'attributes' => ['recipient_name' => null],
                'relationships' => [
                    'provider' => ['data' => null],
                    'delivery' => ['data' => ['type' => 'delivery', 'lid' => 'del1']],
                    'domains' => ['data' => [
                        ['type' => 'domain', 'lid' => 'd1'],
                        ['type' => 'domain', 'id' => '3'],
                        ['type' => 'domain', 'id' => '5'],
                        ['type' => 'domain', 'id' => '2', 'meta' => ['op' => 'remove']],
                    ]],
                ],
            ],
            'included' => [
                ['type' => 'domain', 'id' => '3', 'attributes' => ['is_primary' => true]],
                ['type' => 'domain', 'lid' => 'd1', 'attributes' => ['domain_name' => 'new.test']],
                ['type' => 'delivery', 'lid' => 'del1', 'attributes' => ['delivery_channel' => 'SMS']],
            ],
        ]);

        self::assertEquals(new UpdateOperation(
            resource: CertificateWithDetailResource::class,
            identity: new PersistentIdentity(id: 1),
            attributes: ['recipient_name' => null],
            references: ['provider' => null],
            relationships: [
                // to-one через lid — замена: текущий ребёнок удаляется
                'delivery' => new RelationshipOperation(replace: true, children: [
                    self::insert(DeliveryResource::class, 'del1', ['delivery_channel' => 'SMS']),
                ]),
                // есть пометка meta.op — частичное изменение; порядок: удаления, изменённые и оставленные, вставки
                'domains' => new RelationshipOperation(replace: false, children: [
                    new DeleteOperation(resource: DomainResource::class, identity: new PersistentIdentity(id: 2)),
                    new UpdateOperation(
                        resource: DomainResource::class,
                        identity: new PersistentIdentity(id: 3),
                        attributes: ['is_primary' => true],
                        references: [],
                        relationships: [],
                    ),
                    new KeepOperation(resource: DomainResource::class, identity: new PersistentIdentity(id: 5)),
                    self::insert(DomainResource::class, 'd1', ['domain_name' => 'new.test']),
                ]),
            ],
        ), $root);
    }

    public function testListWithoutMarksIsFullReplacement(): void
    {
        $root = new WriteDocumentParser()->update(resource: CertificateWithDetailResource::class, id: '1', document: [
            'data' => ['type' => 'certificate', 'id' => '1', 'relationships' => [
                'delivery' => ['data' => null],
                'domains' => ['data' => [['type' => 'domain', 'id' => '1']]],
            ]],
        ]);

        self::assertEquals([
            'delivery' => new RelationshipOperation(replace: true, children: []),
            'domains' => new RelationshipOperation(replace: true, children: [
                new KeepOperation(resource: DomainResource::class, identity: new PersistentIdentity(id: 1)),
            ]),
        ], $root->relationships);
        // Связи, которой нет в документе, нет и в дереве: её детей не трогают
        self::assertNull(
            new WriteDocumentParser()->update(
                resource: CertificateWithDetailResource::class,
                id: '1',
                document: ['data' => ['type' => 'certificate', 'id' => '1']],
            )->getRelationship(name: 'domains'),
        );
    }

    public function testToOneIdentifierKeepsCurrentChild(): void
    {
        $root = new WriteDocumentParser()->update(resource: CertificateWithDetailResource::class, id: '1', document: [
            'data' => ['type' => 'certificate', 'id' => '1', 'relationships' => [
                'delivery' => ['data' => ['type' => 'delivery', 'id' => '1']],
            ]],
        ]);

        self::assertEquals(new RelationshipOperation(replace: false, children: [
            new KeepOperation(resource: DeliveryResource::class, identity: new PersistentIdentity(id: 1)),
        ]), $root->getRelationship(name: 'delivery'));
    }

    public function testDelete(): void
    {
        self::assertEquals(
            new DeleteOperation(resource: DomainResource::class, identity: new PersistentIdentity(id: 7)),
            new WriteDocumentParser()->delete(resource: DomainResource::class, id: '7'),
        );
    }

    public function testDeleteOfNotDeletableResourceIsForbidden(): void
    {
        try {
            new WriteDocumentParser()->delete(resource: ProviderResource::class, id: '1');
            self::fail('InvalidDocumentException expected');
        } catch (InvalidDocumentException $exception) {
            self::assertSame('403', $exception->status);
            self::assertNull($exception->pointer);
            self::assertArrayNotHasKey('source', $exception->toError());
        }
    }

    public function testDeleteWithNonNumericIdIsNotFound(): void
    {
        $this->expectException(ResourceNotFoundException::class);
        new WriteDocumentParser()->delete(resource: DomainResource::class, id: 'abc');
    }

    /**
     * @param 'insert'|'update' $method `update` — `PATCH /certificates/1`
     */
    #[DataProvider('getInvalidDocuments')]
    public function testRejectsInvalidDocument(
        string $method,
        string $resource,
        array $document,
        string $status,
        string $pointer,
    ): void {
        try {
            $method === 'insert'
                ? new WriteDocumentParser()->insert(document: $document, resource: $resource)
                : new WriteDocumentParser()->update(document: $document, resource: $resource, id: '1');
            self::fail('InvalidDocumentException expected');
        } catch (InvalidDocumentException $exception) {
            self::assertSame([$status, $pointer], [$exception->status, $exception->pointer], $exception->getMessage());
            self::assertSame(['pointer' => $pointer], $exception->toError()['source'] ?? null);
        }
    }

    /**
     * @return array<string, array{string, class-string, array, string, string}>
     */
    public static function getInvalidDocuments(): array
    {
        $certificate = CertificateWithDetailResource::class;
        $patch = static fn(array $data, array $included = []): array => [
            'data' => ['type' => 'certificate', 'id' => '1', ...$data],
            'included' => $included,
        ];
        $domains = static fn(array ...$identifiers): array => [
            'relationships' => ['domains' => ['data' => $identifiers]],
        ];

        return [
            'PATCH: id differs from the route' => [
                'update', $certificate, ['data' => ['type' => 'certificate', 'id' => '2']], '409', '/data/id',
            ],
            'PATCH: type differs from the route' => [
                'update', $certificate, ['data' => ['type' => 'domain', 'id' => '1']], '409', '/data/type',
            ],
            'PATCH: data is a list' => [
                'update', $certificate, ['data' => [['type' => 'certificate', 'id' => '1']]], '400', '/data',
            ],
            'PATCH: resource is not UpdatableInterface' => [
                'update', ProviderResource::class, ['data' => ['type' => 'provider', 'id' => '1']], '403', '/data',
            ],
            'unknown attribute' => [
                'update', $certificate, $patch(['attributes' => ['nope' => 1]]), '400', '/data/attributes/nope',
            ],
            'null in a non-nullable attribute' => [
                'update', $certificate, $patch(['attributes' => ['nominal' => null]]), '400', '/data/attributes/nominal',
            ],
            'primary key attribute' => [
                'update', $certificate, $patch(['attributes' => ['id' => 5]]), '400', '/data/attributes/id',
            ],
            'unknown relationship' => [
                'update', $certificate, $patch(['relationships' => ['nope' => ['data' => null]]]),
                '400', '/data/relationships/nope',
            ],
            'foreign key by lid' => [
                'update', $certificate,
                $patch(['relationships' => ['provider' => ['data' => ['type' => 'provider', 'lid' => 'x']]]]),
                '400', '/data/relationships/provider/data',
            ],
            'foreign key of another type' => [
                'update', $certificate,
                $patch(['relationships' => ['provider' => ['data' => ['type' => 'domain', 'id' => '1']]]]),
                '400', '/data/relationships/provider/data/type',
            ],
            'unknown op' => [
                'update', $certificate, $patch($domains(['type' => 'domain', 'id' => '3', 'meta' => ['op' => 'bad']])),
                '400', '/data/relationships/domains/data/0/meta/op',
            ],
            'lid without included' => [
                'update', $certificate, $patch($domains(['type' => 'domain', 'lid' => 'a'])),
                '400', '/data/relationships/domains/data/0',
            ],
            'update without included' => [
                'update', $certificate,
                $patch($domains(['type' => 'domain', 'id' => '3', 'meta' => ['op' => 'update']])),
                '400', '/data/relationships/domains/data/0',
            ],
            'identifier listed twice' => [
                'update', $certificate,
                $patch($domains(['type' => 'domain', 'id' => '3'], ['type' => 'domain', 'id' => '3'])),
                '400', '/data/relationships/domains/data/1',
            ],
            'add of an existing resource' => [
                'update', $certificate, $patch($domains(['type' => 'domain', 'id' => '3', 'meta' => ['op' => 'add']])),
                '400', '/data/relationships/domains/data/0/meta/op',
            ],
            'removed resource in included' => [
                'update', $certificate,
                $patch(
                    $domains(['type' => 'domain', 'id' => '3', 'meta' => ['op' => 'remove']]),
                    [['type' => 'domain', 'id' => '3']],
                ),
                '400', '/included/0',
            ],
            'op on a to-one relationship' => [
                'update', $certificate,
                $patch(['relationships' => ['delivery' => ['data' => [
                    'type' => 'delivery', 'id' => '1', 'meta' => ['op' => 'remove'],
                ]]]]),
                '400', '/data/relationships/delivery/data/meta/op',
            ],
            'list in a to-one relationship' => [
                'update', $certificate,
                $patch(['relationships' => ['delivery' => ['data' => [['type' => 'delivery', 'id' => '1']]]]]),
                '400', '/data/relationships/delivery/data',
            ],
            'included resource is not referenced' => [
                'update', $certificate, $patch([], [['type' => 'domain', 'lid' => 'zz']]), '400', '/included/0',
            ],
            'duplicate in included' => [
                'update', $certificate,
                $patch(
                    $domains(['type' => 'domain', 'lid' => 'a']),
                    [['type' => 'domain', 'lid' => 'a'], ['type' => 'domain', 'lid' => 'a']],
                ),
                '400', '/included/1',
            ],
            'POST: client-generated id' => [
                'insert', $certificate, ['data' => ['type' => 'certificate', 'id' => '5']], '403', '/data/id',
            ],
            'POST: empty data' => ['insert', $certificate, ['data' => []], '400', '/data'],
            'POST: type differs from the route' => [
                'insert', $certificate, ['data' => ['type' => 'domain']], '409', '/data/type',
            ],
            'POST: resource is not InsertableInterface' => [
                'insert', ProviderResource::class, ['data' => ['type' => 'provider']], '403', '/data',
            ],
            'POST: existing child of a new resource' => [
                'insert', $certificate,
                ['data' => ['type' => 'certificate', ...$domains(['type' => 'domain', 'id' => '3'])]],
                '400', '/data/relationships/domains/data/0',
            ],
            'POST: duplicate lid of roots' => [
                'insert', $certificate,
                ['data' => [['type' => 'certificate', 'lid' => 'a'], ['type' => 'certificate', 'lid' => 'a']]],
                '400', '/data/1',
            ],
            'POST: included resource linked from two roots' => [
                'insert', $certificate,
                [
                    'data' => [
                        ['type' => 'certificate', ...$domains(['type' => 'domain', 'lid' => 'd'])],
                        ['type' => 'certificate', ...$domains(['type' => 'domain', 'lid' => 'd'])],
                    ],
                    'included' => [['type' => 'domain', 'lid' => 'd', 'attributes' => ['domain_name' => 'd.test']]],
                ],
                '400', '/data/1/relationships/domains/data/0',
            ],
        ];
    }

    /**
     * @param class-string<\Ggs\JsonApiEngine\Resource> $resource
     * @param array<string, mixed>                      $attributes
     */
    private static function insert(string $resource, ?string $lid, array $attributes): InsertOperation
    {
        return new InsertOperation(
            resource: $resource,
            identity: $lid === null ? null : new LocalIdentity(lid: $lid),
            attributes: $attributes,
            references: [],
            relationships: [],
        );
    }
}
