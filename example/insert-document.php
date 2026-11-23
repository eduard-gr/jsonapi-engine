<?php

/**
 * Вставка с деревом исполнителей, собранным руками: документ → `Parser\Write\WriteDocumentParser::insert()` (дерево
 * операций) → дерево исполнителей (`…Writer`, push) → строки в таблицах SQLite базы example/db. То же дерево по плану
 * строит `WritePlanner` + `WriterBuilder` — example/insert-plan.php.
 *
 *     php example/insert-document.php            # дерево (explain) и ключи корня; SQL каждого INSERT — в stderr
 *     php example/insert-document.php 2>sql.log  # SQL отдельно
 *
 * `POST /certificates` для `CertificateWithDetailResource`: сертификат с деталями-атрибутами, `delivery` и три домена.
 * Руками собраны оба уровня дерева:
 *  - уровень ресурсов — левый глубокий список `WriteJoinWriter` (родитель — владелец связи, `parent`; новые дети —
 *    `child`): сначала `delivery`, потом `domains`; место каждого ресурса — `WriteOperationWriter` с одной ветвью
 *    `add` (`InsertResourceWriter`): в документе вставки других операций нет;
 *  - уровень таблиц сертификата — `InsertEntityJoinWriter`: родитель `certificates`, ребёнок `certificate_details`,
 *    обёрнутый в `InsertOptionalEntityWriter` (правая сторона `LeftJoin`: без колонок деталей она пропускается).
 *    Для ресурсов из одной таблицы поддерево таблиц строит `WriterBuilder::getInsertEntities()` по
 *    `InsertPlanner::getEntities()` из `getModel()`.
 * `delivery` вставляется после деталей: `certificate_delivery_meta.certificate_id` ссылается на `certificate_details`
 * (SQLite с `foreign_keys = ON` иначе отказывает). Вставка идёт в транзакции, которая в конце откатывается: база
 * не меняется.
 */

declare(strict_types=1);

use Ggs\JsonApiEngine\Example\Container\ArrayContainer;
use Ggs\JsonApiEngine\Example\Container\ExampleResources;
use Ggs\JsonApiEngine\Example\Entity\CertificateDetailsEntity;
use Ggs\JsonApiEngine\Example\Entity\CertificatesEntity;
use Ggs\JsonApiEngine\Example\Resource\CertificateWithDetailResource;
use Ggs\JsonApiEngine\Example\Resource\DeliveryResource;
use Ggs\JsonApiEngine\Example\Resource\DomainResource;
use Ggs\JsonApiEngine\Executor\Insert\InsertOptionalEntityWriter;
use Ggs\JsonApiEngine\Executor\Insert\InsertResourceWriter;
use Ggs\JsonApiEngine\Executor\Insert\InsertEntityJoinWriter;
use Ggs\JsonApiEngine\Executor\Insert\InsertEntityWriter;
use Ggs\JsonApiEngine\Executor\Write\ResourceDeserializer;
use Ggs\JsonApiEngine\Executor\Write\WriteJoinWriter;
use Ggs\JsonApiEngine\Executor\Write\WriteOperationWriter;
use Ggs\JsonApiEngine\Executor\Write\WriteRootWriter;
use Ggs\JsonApiEngine\Executor\Write\WriterBuilder;
use Ggs\JsonApiEngine\Parser\Write\WriteDocumentParser;
use Ggs\JsonApiEngine\Plan\Insert\InsertPlanner;

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
$certificate_tables = new InsertEntityJoinWriter(
    relation: [CertificatesEntity::ID, CertificateDetailsEntity::CERTIFICATE_ID],
    parent: new InsertEntityWriter(
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
    child: new InsertOptionalEntityWriter(
        input: new InsertEntityWriter(
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

// Ресурсы из одной таблицы: поддерево таблиц — по getModel() ресурса
$builder = new WriterBuilder(
    pdo: $pdo,
    lock: false,
    container: new ArrayContainer(
        services: ExampleResources::getAll(),
    ),
);
$planner = new InsertPlanner();

$insert = new WriteRootWriter(
    root: new WriteJoinWriter(
        name: CertificateWithDetailResource::DOMAINS,
        relation: CertificateWithDetailResource::getRelationship(CertificateWithDetailResource::DOMAINS)->relation,
        parent: new WriteJoinWriter(
            name: CertificateWithDetailResource::DELIVERY,
            relation: CertificateWithDetailResource::getRelationship(CertificateWithDetailResource::DELIVERY)->relation,
            parent: new WriteOperationWriter(
                resource: CertificateWithDetailResource::class,
                add: new InsertResourceWriter(
                    resource: new CertificateWithDetailResource(),
                    deserializer: new ResourceDeserializer(
                        resource: new CertificateWithDetailResource(),
                    ),
                    input: $certificate_tables,
                ),
                update: null,
                keep: null,
                remove: null,
            ),
            child: new WriteOperationWriter(
                resource: DeliveryResource::class,
                add: new InsertResourceWriter(
                    resource: new DeliveryResource(),
                    deserializer: new ResourceDeserializer(
                        resource: new DeliveryResource(),
                    ),
                    input: $builder->getInsertEntities(plan: $planner->getEntities(resource: DeliveryResource::class)),
                ),
                update: null,
                keep: null,
                remove: null,
            ),
        ),
        child: new WriteOperationWriter(
            resource: DomainResource::class,
            add: new InsertResourceWriter(
                resource: new DomainResource(),
                deserializer: new ResourceDeserializer(
                    resource: new DomainResource(),
                ),
                input: $builder->getInsertEntities(plan: $planner->getEntities(resource: DomainResource::class)),
            ),
            update: null,
            keep: null,
            remove: null,
        ),
    ),
    data: new WriteDocumentParser()->insert(document: DOCUMENT, resource: CertificateWithDetailResource::class),
    route: null,
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
