<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Plan\Write;

use Ggs\JsonApiEngine\Example\Resource\CertificateResource;
use Ggs\JsonApiEngine\Example\Resource\CertificateWithDetailResource;
use Ggs\JsonApiEngine\Example\Resource\DeliveryResource;
use Ggs\JsonApiEngine\Example\Resource\DomainResource;
use Ggs\JsonApiEngine\Example\Resource\ProviderResource;
use Ggs\JsonApiEngine\Parser\Write\InsertOperation;
use Ggs\JsonApiEngine\Parser\Write\WriteDocumentParser;
use Ggs\JsonApiEngine\Plan\Delete\DeleteResourceNode;
use Ggs\JsonApiEngine\Plan\Insert\InsertPlanner;
use Ggs\JsonApiEngine\Plan\Insert\InsertResourceNode;
use Ggs\JsonApiEngine\Plan\Update\UpdatePlanner;
use Ggs\JsonApiEngine\Plan\Update\UpdateResourceNode;
use Ggs\JsonApiEngine\Plan\Write\RouteParentNode;
use Ggs\JsonApiEngine\Plan\Write\WriteJoinNode;
use Ggs\JsonApiEngine\Plan\Write\WriteOperationNode;
use Ggs\JsonApiEngine\Plan\Write\WritePlanner;
use Ggs\JsonApiEngine\Plan\Write\WriteReplaceNode;
use Ggs\JsonApiEngine\Plan\Write\WriteRootNode;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * WritePlanner: план по форме дерева операций — ветви места только для встреченных операций, связи в порядке
 * объявления, узел замены под узлом связи или без него, объединение по корням, родитель вложенного маршрута.
 */
final class WritePlannerTest extends TestCase
{
    private const array RELATION = ['id', 'certificate_id'];

    public function testPatchPlanFollowsOperationTree(): void
    {
        $plan = self::patch(data: [
            'attributes' => ['nominal' => '1.00'],
            'relationships' => [
                // в документе domains раньше delivery: порядок в плане — порядок объявления связей
                'domains' => ['data' => [
                    ['type' => 'domain', 'id' => '3'],
                    ['type' => 'domain', 'id' => '2', 'meta' => ['op' => 'remove']],
                    ['type' => 'domain', 'lid' => 'd1'],
                ]],
                'delivery' => ['data' => ['type' => 'delivery', 'lid' => 'del1']],
            ],
        ], included: [
            ['type' => 'domain', 'id' => '3', 'attributes' => ['is_primary' => true]],
            ['type' => 'domain', 'lid' => 'd1', 'attributes' => ['domain_name' => 'n.test']],
            ['type' => 'delivery', 'lid' => 'del1', 'attributes' => ['delivery_channel' => 'SMS']],
        ]);

        self::assertEquals(new WriteRootNode(
            input: new WriteJoinNode(
                name: 'domains',
                relation: self::RELATION,
                parent: new WriteJoinNode(
                    name: 'delivery',
                    relation: self::RELATION,
                    // замена to-one через lid: узел замены под узлом связи
                    parent: new WriteReplaceNode(
                        name: 'delivery',
                        relation: self::RELATION,
                        child_resource: DeliveryResource::class,
                        input: self::place(CertificateWithDetailResource::class, update: true),
                    ),
                    child: self::place(DeliveryResource::class, add: true),
                ),
                // частичное изменение: узла замены нет, у места три ветви
                child: self::place(DomainResource::class, add: true, update: true, remove: true),
            ),
            route: null,
        ), $plan);
    }

    public function testFullReplacementPutsReplaceNodeUnderJoin(): void
    {
        $plan = self::patch(data: ['relationships' => ['domains' => ['data' => [['type' => 'domain', 'id' => '1']]]]]);

        self::assertEquals(new WriteJoinNode(
            name: 'domains',
            relation: self::RELATION,
            parent: new WriteReplaceNode(
                name: 'domains',
                relation: self::RELATION,
                child_resource: DomainResource::class,
                input: self::place(CertificateWithDetailResource::class, update: true),
            ),
            // KeepOperation исполняет ветвь update
            child: self::place(DomainResource::class, update: true),
        ), $plan->input);
    }

    public function testReplacementByEmptyHasNoJoin(): void
    {
        $plan = self::patch(data: ['relationships' => ['delivery' => ['data' => null], 'domains' => ['data' => []]]]);

        self::assertEquals(new WriteReplaceNode(
            name: 'domains',
            relation: self::RELATION,
            child_resource: DomainResource::class,
            input: new WriteReplaceNode(
                name: 'delivery',
                relation: self::RELATION,
                child_resource: DeliveryResource::class,
                input: self::place(CertificateWithDetailResource::class, update: true),
            ),
        ), $plan->input);
    }

    public function testRelationshipsAreUnitedOverRoots(): void
    {
        $roots = new WriteDocumentParser()->insert(resource: CertificateWithDetailResource::class, document: [
            'data' => [
                ['type' => 'certificate', 'attributes' => ['code' => 'A']],
                [
                    'type' => 'certificate',
                    'attributes' => ['code' => 'B'],
                    'relationships' => ['delivery' => ['data' => ['type' => 'delivery', 'lid' => 'del1']]],
                ],
            ],
            'included' => [['type' => 'delivery', 'lid' => 'del1', 'attributes' => ['delivery_channel' => 'SMS']]],
        ]);
        $plan = new WritePlanner()->plan(resource: CertificateWithDetailResource::class, roots: $roots);

        // у POST узла замены не бывает
        self::assertEquals(new WriteJoinNode(
            name: 'delivery',
            relation: self::RELATION,
            parent: self::place(CertificateWithDetailResource::class, add: true),
            child: self::place(DeliveryResource::class, add: true),
        ), $plan->input);
    }

    public function testDeletePlanHasOnlyRemoveBranch(): void
    {
        $root = new WriteDocumentParser()->delete(resource: DomainResource::class, id: '1');
        $plan = new WritePlanner()->plan(resource: DomainResource::class, roots: [$root]);

        self::assertEquals(self::place(DomainResource::class, remove: true), $plan->input);
    }

    public function testNestedRouteGivesRouteParentNode(): void
    {
        $roots = new WriteDocumentParser()->insert(resource: DomainResource::class, document: [
            'data' => ['type' => 'domain', 'attributes' => ['domain_name' => 'r.test']],
        ]);
        $plan = new WritePlanner()->plan(
            resource: DomainResource::class,
            roots: $roots,
            parent: CertificateResource::class,
            relationship: CertificateResource::DOMAINS,
        );

        // id родителя — значение, в плане его нет
        self::assertEquals(new RouteParentNode(parent: CertificateResource::class, relation: self::RELATION), $plan->route);
    }

    public function testNestedRouteNeedsOwnerSideRelationship(): void
    {
        $this->expectException(LogicException::class);
        new WritePlanner()->plan(
            resource: ProviderResource::class,
            roots: [],
            parent: CertificateResource::class,
            relationship: CertificateResource::PROVIDER,
        );
    }

    public function testInsertOfNotInsertableResourceFails(): void
    {
        $this->expectException(LogicException::class);
        new WritePlanner()->plan(resource: ProviderResource::class, roots: [
            new InsertOperation(
                resource: ProviderResource::class,
                identity: null,
                attributes: [],
                references: [],
                relationships: [],
            ),
        ]);
    }

    /**
     * План `PATCH /certificates/1` над `CertificateWithDetailResource`.
     */
    private static function patch(array $data, array $included = []): WriteRootNode
    {
        $root = new WriteDocumentParser()->update(
            document: ['data' => ['type' => 'certificate', 'id' => '1', ...$data], 'included' => $included],
            resource: CertificateWithDetailResource::class,
            id: '1',
        );

        return new WritePlanner()->plan(resource: CertificateWithDetailResource::class, roots: [$root]);
    }

    /**
     * Место ресурса с ветвями названных операций.
     *
     * @param class-string<\Ggs\JsonApiEngine\Resource> $resource
     */
    private static function place(
        string $resource,
        bool $add = false,
        bool $update = false,
        bool $remove = false,
    ): WriteOperationNode {
        return new WriteOperationNode(
            resource: $resource,
            add: $add
                ? new InsertResourceNode(resource: $resource, input: new InsertPlanner()->tables(resource: $resource))
                : null,
            update: $update
                ? new UpdateResourceNode(resource: $resource, input: new UpdatePlanner()->tables(resource: $resource))
                : null,
            remove: $remove ? new DeleteResourceNode(resource: $resource) : null,
        );
    }
}
