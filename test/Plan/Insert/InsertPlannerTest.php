<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Plan\Insert;

use Ggs\JsonApiEngine\Example\Resource\CertificateResource;
use Ggs\JsonApiEngine\Example\Resource\CertificateWithDetailResource;
use Ggs\JsonApiEngine\Example\Resource\ProviderResource;
use Ggs\JsonApiEngine\Plan\Insert\InsertOptionalEntityNode;
use Ggs\JsonApiEngine\Plan\Insert\InsertPlanner;
use Ggs\JsonApiEngine\Plan\Insert\InsertEntityJoinNode;
use Ggs\JsonApiEngine\Plan\Insert\InsertEntityNode;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * InsertPlanner: поддерево таблиц вставки по модели ресурса. Уровень ресурсов (связи, корень) — `WritePlannerTest`.
 */
final class InsertPlannerTest extends TestCase
{
    public function testSingleTable(): void
    {
        self::assertEquals(new InsertEntityNode(entity: CertificateResource::getModel(), columns: [
            'id', 'provider_id', 'code', 'nominal', 'currency', 'status', 'created_at',
        ]), new InsertPlanner()->getEntities(resource: CertificateResource::class));
    }

    public function testModelOfTwoTables(): void
    {
        $tables = new InsertPlanner()->getEntities(resource: CertificateWithDetailResource::class);

        self::assertInstanceOf(InsertEntityJoinNode::class, $tables);
        self::assertSame(['id', 'certificate_id'], $tables->relation);
        self::assertInstanceOf(InsertEntityNode::class, $tables->parent);
        self::assertSame('certificates', $tables->parent->entity::TABLE);
        // certificate_details — правая сторона LeftJoin: необязательна
        self::assertInstanceOf(InsertOptionalEntityNode::class, $tables->child);
        self::assertInstanceOf(InsertEntityNode::class, $tables->child->input);
        self::assertSame('certificate_details', $tables->child->input->entity::TABLE);
    }

    public function testNotInsertableResourceFails(): void
    {
        $this->expectException(LogicException::class);
        new InsertPlanner()->getEntities(resource: ProviderResource::class);
    }
}
