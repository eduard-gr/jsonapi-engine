<?php

/**
 * Вставка с деревом исполнителей, собранным руками: документ → `Parser\Insert\DocumentParser` (вложенное дерево
 * новых ресурсов) → дерево вставки (`Executor\Insert\…Writer`, push) → строки в таблицах SQLite базы example/db.
 * То же дерево по плану строит `InsertPlanner` + `WriterBuilder` — example/insert-plan.php.
 *
 *     php example/insert-document.php            # дерево (explain) и ключи корня; SQL каждого INSERT — в stderr
 *     php example/insert-document.php 2>sql.log  # SQL отдельно
 *
 * `POST /certificates`: сертификат, `detail`, `delivery` и три домена — отдельные ресурсы из одной таблицы каждый.
 * Дерево уровня ресурсов — левый глубокий список `InsertJoinWriter` (родитель, сторона с первичным ключом, — `parent`);
 * поддерево таблиц под каждым ресурсом строит `TableTreeBuilder` из `model()`. `detail` стоит глубже `delivery`:
 * `certificate_delivery_meta.certificate_id` ссылается на `certificate_details` (SQLite с `foreign_keys = ON` иначе
 * отказывает). Вставка идёт в транзакции, которая в конце откатывается: база не меняется.
 */

declare(strict_types=1);

use Ggs\JsonApiEngine\Example\Resource\CertificateResource;
use Ggs\JsonApiEngine\Example\Resource\DeliveryResource;
use Ggs\JsonApiEngine\Example\Resource\DetailResource;
use Ggs\JsonApiEngine\Example\Resource\DomainResource;
use Ggs\JsonApiEngine\Executor\Insert\InsertJoinWriter;
use Ggs\JsonApiEngine\Executor\Insert\InsertResourceWriter;
use Ggs\JsonApiEngine\Executor\Insert\InsertRootWriter;
use Ggs\JsonApiEngine\Executor\Insert\TableTreeBuilder;
use Ggs\JsonApiEngine\Parser\Insert\DocumentParser;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/db/connection.php';

const DOCUMENT = [
    'data' => [
        'type' => 'certificate',
        'lid' => 'c1',
        'attributes' => ['code' => 'CERT-0001', 'nominal' => '100.00', 'currency' => 'EUR', 'status' => 'ACTIVE'],
        'relationships' => [
            'provider' => ['data' => ['type' => 'provider', 'id' => '1']],
            'detail' => ['data' => ['type' => 'detail', 'lid' => 'det1']],
            'delivery' => ['data' => ['type' => 'delivery', 'lid' => 'del1']],
            'domains' => ['data' => [
                ['type' => 'domain', 'lid' => 'd1'],
                ['type' => 'domain', 'lid' => 'd2'],
                ['type' => 'domain', 'lid' => 'd3'],
            ]],
        ],
    ],
    'included' => [
        ['type' => 'detail', 'lid' => 'det1', 'attributes' => [
            'buyer_email' => 'buyer@example.com',
            'recipient_name' => 'Anna',
            'recipient_email' => 'anna@example.com',
            'greeting_text' => 'Happy birthday!',
        ]],
        ['type' => 'delivery', 'lid' => 'del1', 'attributes' => ['delivery_channel' => 'EMAIL', 'is_sent' => false]],
        ['type' => 'domain', 'lid' => 'd1', 'attributes' => ['domain_name' => 'example.com', 'is_primary' => true]],
        ['type' => 'domain', 'lid' => 'd2', 'attributes' => ['domain_name' => 'www.example.com', 'is_primary' => false]],
        ['type' => 'domain', 'lid' => 'd3', 'attributes' => ['domain_name' => 'shop.example.com', 'is_primary' => false]],
    ],
];

$pdo = getPDO();
$tables = new TableTreeBuilder(pdo: $pdo);
$resource = static fn(string $class): InsertResourceWriter => new InsertResourceWriter(
    resource: $class,
    input: $tables->build($class),
);

$insert = new InsertRootWriter(
    root: new InsertJoinWriter(
        name: CertificateResource::DOMAINS,
        relation: CertificateResource::relationship(CertificateResource::DOMAINS)->relation,
        parent: new InsertJoinWriter(
            name: CertificateResource::DELIVERY,
            relation: CertificateResource::relationship(CertificateResource::DELIVERY)->relation,
            parent: new InsertJoinWriter(
                name: CertificateResource::DETAIL,
                relation: CertificateResource::relationship(CertificateResource::DETAIL)->relation,
                parent: $resource(CertificateResource::class),
                child: $resource(DetailResource::class),
            ),
            child: $resource(DeliveryResource::class),
        ),
        child: $resource(DomainResource::class),
    ),
    data: new DocumentParser()->parse(document: DOCUMENT, resource: CertificateResource::class),
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
