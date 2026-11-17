<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Plan\Update;

use Ggs\JsonApiEngine\Example\Entity\CertificateDetailsEntity;
use Ggs\JsonApiEngine\Example\Entity\CertificatesEntity;
use Ggs\JsonApiEngine\Example\Entity\DomainsEntity;
use Ggs\JsonApiEngine\Example\Resource\CertificateWithDetailResource;
use Ggs\JsonApiEngine\Example\Resource\DomainResource;
use Ggs\JsonApiEngine\Plan\Insert\InsertTableNode;
use Ggs\JsonApiEngine\Plan\Update\UpdateOptionalTableNode;
use Ggs\JsonApiEngine\Plan\Update\UpdatePlanner;
use Ggs\JsonApiEngine\Plan\Update\UpdateTableJoinNode;
use Ggs\JsonApiEngine\Plan\Update\UpdateTableNode;
use PHPUnit\Framework\TestCase;

/**
 * UpdatePlanner: поддерево таблиц изменения по модели — главная таблица ищется по первичному ключу ресурса и возвращает
 * ключ для присоединённой; правая сторона `LeftJoin` — изменение или вставка.
 */
final class UpdatePlannerTest extends TestCase
{
    public function testSingleTable(): void
    {
        self::assertEquals(new UpdateTableNode(
            entity: DomainsEntity::class,
            columns: ['id', 'certificate_id', 'domain_name', 'is_primary', 'created_at'],
            key: 'id',
            returning: [],
        ), new UpdatePlanner()->tables(resource: DomainResource::class));
    }

    public function testModelOfTwoTables(): void
    {
        $detail_columns = ['buyer_email', 'recipient_name', 'recipient_email', 'greeting_text', 'design_template_id'];

        self::assertEquals(new UpdateTableJoinNode(
            relation: ['id', 'certificate_id'],
            // главная таблица: ключ — id ресурса; id нужен присоединённой, поэтому он в returning
            main: new UpdateTableNode(
                entity: CertificatesEntity::class,
                columns: ['id', 'provider_id', 'code', 'nominal', 'currency', 'status', 'created_at'],
                key: 'id',
                returning: ['id'],
            ),
            // certificate_details — правая сторона LeftJoin: строки может не быть
            joined: new UpdateOptionalTableNode(
                update: new UpdateTableNode(
                    entity: CertificateDetailsEntity::class,
                    columns: $detail_columns,
                    key: 'certificate_id',
                    returning: [],
                ),
                insert: new InsertTableNode(entity: CertificateDetailsEntity::class, columns: $detail_columns),
            ),
        ), new UpdatePlanner()->tables(resource: CertificateWithDetailResource::class));
    }
}
