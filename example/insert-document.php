<?php

/**
 * Вставка с деревом исполнителей, собранным руками: документ → `Parser\Insert\DocumentParser` (вложенное дерево
 * новых ресурсов) → дерево вставки (`Executor\Insert\…Writer`, push) → строки в таблицах SQLite базы example/db.
 * То же дерево по плану строит `InsertPlanner` + `WriterBuilder` — example/insert-plan.php.
 *
 *     php example/insert-document.php            # дерево (explain) и ключи корня; SQL каждого INSERT — в stderr
 *     php example/insert-document.php 2>sql.log  # SQL отдельно
 *
 * `POST /certificates` для `CertificateWithDetailResource`: сертификат с деталями-атрибутами, `delivery` и три домена.
 * Руками собраны оба уровня дерева:
 *  - уровень ресурсов — левый глубокий список `InsertJoinWriter` (родитель — владелец связи, `parent`; новые дети —
 *    `child`): сначала `delivery`, потом `domains`;
 *  - уровень таблиц сертификата — `InsertTableJoinWriter`: родитель `certificates`, ребёнок `certificate_details`,
 *    обёрнутый в `InsertOptionalTableWriter` (правая сторона `LeftJoin`: без колонок деталей она пропускается).
 *    Для ресурсов из одной таблицы поддерево таблиц строит `TableTreeBuilder` из `model()`.
 * `delivery` вставляется после деталей: `certificate_delivery_meta.certificate_id` ссылается на `certificate_details`
 * (SQLite с `foreign_keys = ON` иначе отказывает). Вставка идёт в транзакции, которая в конце откатывается: база
 * не меняется.
 */

declare(strict_types=1);

use Ggs\JsonApiEngine\Example\Entity\CertificateDetailsEntity;
use Ggs\JsonApiEngine\Example\Entity\CertificatesEntity;
use Ggs\JsonApiEngine\Example\Resource\CertificateWithDetailResource;
use Ggs\JsonApiEngine\Example\Resource\DeliveryResource;
use Ggs\JsonApiEngine\Example\Resource\DomainResource;
use Ggs\JsonApiEngine\Executor\Insert\InsertJoinWriter;
use Ggs\JsonApiEngine\Executor\Insert\InsertOptionalTableWriter;
use Ggs\JsonApiEngine\Executor\Insert\InsertResourceWriter;
use Ggs\JsonApiEngine\Executor\Insert\InsertRootWriter;
use Ggs\JsonApiEngine\Executor\Insert\InsertTableJoinWriter;
use Ggs\JsonApiEngine\Executor\Insert\InsertTableWriter;
use Ggs\JsonApiEngine\Executor\Insert\TableTreeBuilder;
use Ggs\JsonApiEngine\Parser\Insert\DocumentParser;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/db/connection.php';

const DOCUMENT = [
    'data' => [
        'type' => 'certificate',
        'lid' => 'c1',
        'attributes' => [
            'code' => 'CERT-0001',
            'nominal' => '100.00',
            'currency' => 'EUR',
            'status' => 'ACTIVE',
            'buyer_email' => 'buyer@example.com',
            'recipient_name' => 'Anna',
            'recipient_email' => 'anna@example.com',
            'greeting_text' => 'Happy birthday!',
        ],
        'relationships' => [
            'provider' => ['data' => ['type' => 'provider', 'id' => '1']],
            'delivery' => ['data' => ['type' => 'delivery', 'lid' => 'del1']],
            'domains' => ['data' => [
                ['type' => 'domain', 'lid' => 'd1'],
                ['type' => 'domain', 'lid' => 'd2'],
                ['type' => 'domain', 'lid' => 'd3'],
            ]],
        ],
    ],
    'included' => [
        ['type' => 'delivery', 'lid' => 'del1', 'attributes' => ['delivery_channel' => 'EMAIL', 'is_sent' => false]],
        ['type' => 'domain', 'lid' => 'd1', 'attributes' => ['domain_name' => 'example.com', 'is_primary' => true]],
        ['type' => 'domain', 'lid' => 'd2', 'attributes' => ['domain_name' => 'www.example.com', 'is_primary' => false]],
        ['type' => 'domain', 'lid' => 'd3', 'attributes' => ['domain_name' => 'shop.example.com', 'is_primary' => false]],
    ],
];

$pdo = getPDO();

// Уровень таблиц сертификата: certificates — родитель, certificate_details — необязательный ребёнок (LeftJoin).
// Колонки — колонки модели ресурса в каждой таблице; certificate_id деталей приходит колонкой связи от родителя
$certificate_tables = new InsertTableJoinWriter(
    relation: [CertificatesEntity::ID, CertificateDetailsEntity::CERTIFICATE_ID],
    parent: new InsertTableWriter(
        pdo: $pdo,
        entity: CertificatesEntity::class,
        columns: [
            CertificatesEntity::ID,
            CertificatesEntity::PROVIDER_ID,
            CertificatesEntity::CODE,
            CertificatesEntity::NOMINAL,
            CertificatesEntity::CURRENCY,
            CertificatesEntity::STATUS,
            CertificatesEntity::CREATED_AT,
        ],
    ),
    child: new InsertOptionalTableWriter(
        input: new InsertTableWriter(
            pdo: $pdo,
            entity: CertificateDetailsEntity::class,
            columns: [
                CertificateDetailsEntity::BUYER_EMAIL,
                CertificateDetailsEntity::RECIPIENT_NAME,
                CertificateDetailsEntity::RECIPIENT_EMAIL,
                CertificateDetailsEntity::GREETING_TEXT,
                CertificateDetailsEntity::DESIGN_TEMPLATE_ID,
            ],
        ),
    ),
);

// Ресурсы из одной таблицы: поддерево таблиц — по model() ресурса
$tables = new TableTreeBuilder(pdo: $pdo);

$insert = new InsertRootWriter(
    root: new InsertJoinWriter(
        name: CertificateWithDetailResource::DOMAINS,
        relation: CertificateWithDetailResource::relationship(CertificateWithDetailResource::DOMAINS)->relation,
        parent: new InsertJoinWriter(
            name: CertificateWithDetailResource::DELIVERY,
            relation: CertificateWithDetailResource::relationship(CertificateWithDetailResource::DELIVERY)->relation,
            parent: new InsertResourceWriter(
                resource: CertificateWithDetailResource::class,
                input: $certificate_tables,
            ),
            child: new InsertResourceWriter(
                resource: DeliveryResource::class,
                input: $tables->build(DeliveryResource::class),
            ),
        ),
        child: new InsertResourceWriter(
            resource: DomainResource::class,
            input: $tables->build(DomainResource::class),
        ),
    ),
    data: new DocumentParser()->parse(document: DOCUMENT, resource: CertificateWithDetailResource::class),
);

echo json_encode($insert->explain(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n\n";

$pdo->beginTransaction();
try {
    foreach ($insert->execute() as $keys) {
        echo json_encode($keys), "\n";
    }
} finally {
    $pdo->rollBack();
}
