<?php

/**
 * Вставка по вложенному маршруту: `POST /certificates/1/domains` — новые домены существующего сертификата 1.
 * Документ → `DocumentParser` → `InsertPlanner` (план по форме документа) → `WriterBuilder::root()` с колонками связи
 * из маршрута → строки в SQLite базе example/db.
 *
 *     php example/insert-route.php            # дерево исполнителей (explain) и ключи корней; SQL каждого INSERT — в stderr
 *     php example/insert-route.php 2>sql.log  # SQL отдельно
 *
 * Сертификат — не в документе, а в маршруте: `InsertPlanner::routeLink()` берёт связь родителя
 * (`CertificateResource::DOMAINS`, `relation` `[id, certificate_id]`) и превращает id из маршрута в колонки связи
 * `['certificate_id' => 1]`. Это значение, а не форма плана, поэтому в плане его нет: `WriterBuilder::root()` ставит
 * над деревом узел `InsertRouteLinkWriter`, который добавляет `certificate_id` к каждому корню. Своей связи
 * `certificate` у `DomainResource` нет и не нужно. Существование сертификата не проверяется: несуществующий id —
 * ошибка внешнего ключа в базе.
 *
 * Разбор маршрута — забота вызывающего (роутера); здесь id просто задан. Вставка идёт в транзакции, которая в конце
 * откатывается: база не меняется.
 */

declare(strict_types=1);

use Ggs\JsonApiEngine\Example\Resource\CertificateResource;
use Ggs\JsonApiEngine\Example\Resource\DomainResource;
use Ggs\JsonApiEngine\Executor\Insert\WriterBuilder;
use Ggs\JsonApiEngine\Parser\Insert\DocumentParser;
use Ggs\JsonApiEngine\Plan\Insert\InsertPlanner;

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
$planner = new InsertPlanner();

$data = new DocumentParser()->parse(document: DOCUMENT, resource: DomainResource::class);
$plan = $planner->plan(resource: DomainResource::class, data: $data);
$link = $planner->routeLink(
    parent: CertificateResource::class,
    relationship: CertificateResource::DOMAINS,
    id: CERTIFICATE_ID,
);
$insert = new WriterBuilder(pdo: $pdo)->root(
    plan: $plan,
    data: $data,
    link: $link,
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
