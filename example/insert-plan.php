<?php

/**
 * Вставка через планировщик: документ → `DocumentParser` → `InsertPlanner` (план по форме документа) →
 * `WriterBuilder` (исполнители) → строки в SQLite базе example/db. Ручная сборка того же дерева —
 * example/insert-document.php.
 *
 *     php example/insert-plan.php             # дерево исполнителей (explain) и ключи корня; SQL каждого INSERT — в stderr
 *     php example/insert-plan.php 2>sql.log   # SQL отдельно
 *
 * `POST /certificates` для `CertificateWithDetailResource`: детали (`buyer_email`, `recipient_name`) — атрибуты
 * сертификата из второй таблицы модели (`LeftJoin(certificates, certificate_details)`), плюс `delivery` и два домена.
 * Планировщик сам строит:
 *  - уровень ресурсов — цепочку связей в порядке объявления (`delivery`, `domains`);
 *  - уровень таблиц сертификата — `InsertTableJoinNode(parent: certificates, child: InsertOptionalTableNode(
 *    certificate_details))`: детали — правая сторона `LeftJoin`, необязательная часть модели. Без атрибутов деталей
 *    строка `certificate_details` не вставилась бы, но тогда упала бы и `delivery`: `certificate_delivery_meta`
 *    ссылается на `certificate_details`.
 * Вставка идёт в транзакции, которая в конце откатывается: база не меняется.
 */

declare(strict_types=1);

use Ggs\JsonApiEngine\Example\Resource\CertificateWithDetailResource;
use Ggs\JsonApiEngine\Executor\Insert\WriterBuilder;
use Ggs\JsonApiEngine\Parser\Insert\DocumentParser;
use Ggs\JsonApiEngine\Plan\Insert\InsertPlanner;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/db/connection.php';

const DOCUMENT = [
    'data' => [
        'type' => 'certificate',
        'lid' => 'c1',
        'attributes' => [
            'code' => 'PLAN-1',
            'nominal' => '10.00',
            'buyer_email' => 'buyer@example.com',
            'recipient_name' => 'Anna',
        ],
        'relationships' => [
            'provider' => ['data' => ['type' => 'provider', 'id' => '1']],
            'delivery' => ['data' => ['type' => 'delivery', 'lid' => 'del1']],
            'domains' => ['data' => [['type' => 'domain', 'lid' => 'd1'], ['type' => 'domain', 'lid' => 'd2']]],
        ],
    ],
    'included' => [
        ['type' => 'delivery', 'lid' => 'del1', 'attributes' => ['delivery_channel' => 'EMAIL']],
        ['type' => 'domain', 'lid' => 'd1', 'attributes' => ['domain_name' => 'plan1.example.com']],
        ['type' => 'domain', 'lid' => 'd2', 'attributes' => ['domain_name' => 'plan2.example.com']],
    ],
];

$pdo = getPDO();

$data = new DocumentParser()->parse(document: DOCUMENT, resource: CertificateWithDetailResource::class);
$plan = new InsertPlanner()->plan(resource: CertificateWithDetailResource::class, data: $data);
$insert = new WriterBuilder(pdo: $pdo)->root(plan: $plan, data: $data);

echo json_encode($insert->explain(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n\n";

$pdo->beginTransaction();
try {
    foreach ($insert->execute() as $keys) {
        echo json_encode($keys), "\n";
    }
} finally {
    $pdo->rollBack();
}
