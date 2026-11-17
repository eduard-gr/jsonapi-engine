<?php

/**
 * Вставка по вложенному маршруту: `POST /certificates/1/domains` — новые домены существующего сертификата 1.
 * Документ → `WriteDocumentParser::insert()` → `WritePlanner` (план с родителем маршрута) → `WriterBuilder::root()` с
 * id из маршрута → строки в SQLite базе example/db.
 *
 *     php example/insert-route.php            # дерево исполнителей (explain) и ключи корней; SQL каждого INSERT — в stderr
 *     php example/insert-route.php 2>sql.log  # SQL отдельно
 *
 * Сертификат — не в документе, а в маршруте: `WritePlanner::plan()` получает родителя и его связь
 * (`CertificateResource::DOMAINS`, `relation` `[id, certificate_id]`) и кладёт в план `RouteParentNode`. Сам id —
 * значение, а не форма плана, поэтому в плане его нет: его получает `WriterBuilder::root()`. `RouteParentWriter` один
 * раз, до первой вставки, проверяет, что сертификат есть (иначе `WriteNotFoundException`, 404), и даёт каждому корню
 * колонку связи `certificate_id = 1`. Своей связи `certificate` у `DomainResource` нет и не нужно.
 *
 * Разбор маршрута — забота вызывающего (роутера); здесь id просто задан. Вставка идёт в транзакции, которая в конце
 * откатывается: база не меняется.
 */

declare(strict_types=1);

use Ggs\JsonApiEngine\Example\Resource\CertificateResource;
use Ggs\JsonApiEngine\Example\Resource\DomainResource;
use Ggs\JsonApiEngine\Executor\Write\WriterBuilder;
use Ggs\JsonApiEngine\Parser\Write\WriteDocumentParser;
use Ggs\JsonApiEngine\Plan\Write\WritePlanner;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/db/connection.php';

/** id родителя из маршрута `POST /certificates/{id}/domains` */
const CERTIFICATE_ID = 1;

const DOCUMENT = [
    'data' => [
        ['type' => 'domain', 'lid' => 'd1', 'attributes' => ['domain_name' => 'api.example.org', 'is_primary' => true]],
        ['type' => 'domain', 'lid' => 'd2', 'attributes' => ['domain_name' => 'cdn.example.org', 'is_primary' => false]],
    ],
];

$pdo = getPDO();

$roots = new WriteDocumentParser()->insert(document: DOCUMENT, resource: DomainResource::class);
$plan = new WritePlanner()->plan(
    resource: DomainResource::class,
    roots: $roots,
    parent: CertificateResource::class,
    relationship: CertificateResource::DOMAINS,
);
// lock — FOR KEY SHARE в проверке родителя: в PostgreSQL true; SQLite примера такой конструкции не знает
$insert = new WriterBuilder(pdo: $pdo, lock: false)->root(
    plan: $plan,
    data: $roots,
    route_id: CERTIFICATE_ID,
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
