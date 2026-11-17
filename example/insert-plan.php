<?php

/**
 * Вставка через планировщик: документ → `WriteDocumentParser::insert()` (дерево операций) → `WritePlanner` (план по
 * форме дерева операций) → `WriterBuilder` (исполнители) → строки в SQLite базе example/db. Ручная сборка того же
 * дерева — example/insert-document.php; тот же конвейер для PATCH и DELETE — example/patch-document.php.
 *
 *     php example/insert-plan.php             # дерево исполнителей (explain) и ключи корня; SQL каждого INSERT — в stderr
 *     php example/insert-plan.php 2>sql.log   # SQL отдельно
 *
 * `POST /certificates` для `CertificateWithDetailResource`: детали (`buyer_email`, `recipient_name`) — атрибуты
 * сертификата из второй таблицы модели (`LeftJoin(certificates, certificate_details)`), плюс `delivery` и два домена.
 * Планировщик сам строит:
 *  - уровень ресурсов — цепочку связей `WriteJoinNode` в порядке объявления (`delivery`, `domains`), у каждого места
 *    `WriteOperationNode` с одной ветвью `add`;
 *  - уровень таблиц сертификата — `InsertTableJoinNode(parent: certificates, child: InsertOptionalTableNode(
 *    certificate_details))`: детали — правая сторона `LeftJoin`, необязательная часть модели. Без атрибутов деталей
 *    строка `certificate_details` не вставилась бы, но тогда упала бы и `delivery`: `certificate_delivery_meta`
 *    ссылается на `certificate_details`.
 * Вставка идёт в транзакции, которая в конце откатывается: база не меняется.
 */

declare(strict_types=1);

use Ggs\JsonApiEngine\Example\Resource\CertificateWithDetailResource;
use Ggs\JsonApiEngine\Executor\Write\WriterBuilder;
use Ggs\JsonApiEngine\Parser\Write\WriteDocumentParser;
use Ggs\JsonApiEngine\Plan\Write\WritePlanner;

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

$roots = new WriteDocumentParser()->insert(document: DOCUMENT, resource: CertificateWithDetailResource::class);
$plan = new WritePlanner()->plan(resource: CertificateWithDetailResource::class, roots: $roots);
// lock — FOR KEY SHARE в проверках существования: в PostgreSQL true; SQLite примера такой конструкции не знает
$insert = new WriterBuilder(pdo: $pdo, lock: false)->root(plan: $plan, data: $roots);

echo json_encode($insert->explain(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n\n";

$pdo->beginTransaction();
try {
    foreach ($insert->execute() as $keys) {
        echo json_encode($keys), "\n";
    }
} finally {
    $pdo->rollBack();
}
