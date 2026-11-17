<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Plan\Insert;

use Ggs\JsonApiEngine\Example\Resource\CertificateResource;
use Ggs\JsonApiEngine\Example\Resource\CertificateWithDetailResource;
use Ggs\JsonApiEngine\Example\Resource\ProviderResource;
use Ggs\JsonApiEngine\Plan\Insert\InsertOptionalTableNode;
use Ggs\JsonApiEngine\Plan\Insert\InsertPlanner;
use Ggs\JsonApiEngine\Plan\Insert\InsertTableJoinNode;
use Ggs\JsonApiEngine\Plan\Insert\InsertTableNode;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * InsertPlanner: поддерево таблиц вставки по модели ресурса. Уровень ресурсов (связи, корень) — `WritePlannerTest`.
 */
final class InsertPlannerTest extends TestCase
{
    public function testSingleTable(): void
    {
        self::assertEquals(new InsertTableNode(entity: CertificateResource::model(), columns: [
            'id', 'provider_id', 'code', 'nominal', 'currency', 'status', 'created_at',
        ]), new InsertPlanner()->tables(resource: CertificateResource::class));
    }

    public function testModelOfTwoTables(): void
    {
        $tables = new InsertPlanner()->tables(resource: CertificateWithDetailResource::class);

        self::assertInstanceOf(InsertTableJoinNode::class, $tables);
        self::assertSame(['id', 'certificate_id'], $tables->relation);
        self::assertInstanceOf(InsertTableNode::class, $tables->parent);
        self::assertSame('certificates', $tables->parent->entity::TABLE);
        // certificate_details — правая сторона LeftJoin: необязательна
        self::assertInstanceOf(InsertOptionalTableNode::class, $tables->child);
        self::assertInstanceOf(InsertTableNode::class, $tables->child->input);
        self::assertSame('certificate_details', $tables->child->input->entity::TABLE);
    }

    public function testNotInsertableResourceFails(): void
    {
        $this->expectException(LogicException::class);
        new InsertPlanner()->tables(resource: ProviderResource::class);
    }
}
