<?php

/**
 * Вставка через планировщик: документ → `DocumentParser` → `InsertPlanner` (план по форме документа) →
 * `WriterBuilder` (исполнители) → строки в SQLite базе example/db. Ручная сборка того же дерева —
 * example/insert-document.php.
 *
 *     php example/insert-plan.php             # дерево исполнителей по плану и ключи корней; SQL каждого INSERT — в stderr
 *     php example/insert-plan.php 2>sql.log   # SQL отдельно
 *
 * Каждый пример — в своей транзакции, которая в конце откатывается: база не меняется.
 *
 *  - `POST /certificates`: сертификат с `detail`, `delivery` и двумя доменами. Планировщик сам строит цепочку связей
 *    в порядке объявления (`detail`, `delivery`, `domains`) — то же дерево, что собрано руками в insert-document.php;
 *  - `POST /certificates` с двумя корнями, домены — только у второго: в плане `domains` (объединение по корням),
 *    `detail` и `delivery` нет — их нет в документе. (`delivery` без `detail` вставить нельзя:
 *    `certificate_delivery_meta.certificate_id` ссылается на `certificate_details`; парсер и планировщик этого не знают,
 *    отказывает база.);
 *  - `POST /certificates/1/domains`: домены к существующему сертификату; `InsertPlanner::routeLink()` превращает id
 *    из маршрута в колонку связи `certificate_id`.
 */

declare(strict_types=1);

use Ggs\JsonApiEngine\Example\Resource\CertificateResource;
use Ggs\JsonApiEngine\Example\Resource\DomainResource;
use Ggs\JsonApiEngine\Executor\WriterBuilder;
use Ggs\JsonApiEngine\Parser\DocumentParser;
use Ggs\JsonApiEngine\Plan\Insert\InsertPlanner;
use Ggs\JsonApiEngine\Resource;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/db/connection.php';

$pdo = getPDO();
$planner = new InsertPlanner();
$builder = new WriterBuilder(pdo: $pdo);

/**
 * Дерево исполнителей в несколько строк: узел, его параметры, дети с отступом.
 */
$tree = static function (array $node, string $indent = '', string $label = '') use (&$tree): string {
    $name = substr(strrchr((string) $node['node'], '\\') ?: '', 1);
    $details = match ($name) {
        'InsertDocument' => "roots: {$node['roots']}" . ($node['link'] !== [] ? ' link: ' . json_encode($node['link']) : ''),
        'InsertJoinWriter' => "{$node['name']} [" . implode(', ', $node['relation']) . ']',
        'InsertResourceWriter' => substr(strrchr((string) $node['resource'], '\\') ?: '', 1),
        'InsertTableJoinWriter' => '[' . implode(' → ', $node['relation']) . ']'
            . ($node['optional'] !== null ? " optional={$node['optional']}" : ''),
        'InsertTableWriter' => (string) $node['table'],
        default => '',
    };
    $out = "{$indent}{$label}{$name} {$details}\n";
    foreach (['root' => '', 'first' => 'first: ', 'then' => 'then:  ', 'input' => ''] as $key => $child) {
        if (isset($node[$key])) {
            $out .= $tree($node[$key], "{$indent}    ", $child);
        }
    }

    return $out;
};

/**
 * @param class-string<Resource> $resource корневой ресурс точки входа
 * @param array<string, int>     $link     колонки связи из маршрута вложенного ресурса
 */
$run = static function (string $title, string $resource, array $document, array $link = []) use (
    $pdo,
    $planner,
    $builder,
    $tree,
): void {
    echo "######## {$title}\n\n";

    $roots = new DocumentParser()->parse(document: $document, resource: $resource);
    $insert = $builder->document(plan: $planner->plan(resource: $resource, roots: $roots), roots: $roots, link: $link);
    echo $tree($insert->explain()), "\n";

    $pdo->beginTransaction();
    try {
        foreach ($insert->execute() as $keys) {
            echo json_encode($keys), "\n";
        }
        echo "\n";
    } finally {
        $pdo->rollBack();
    }
};

$run(title: 'POST /certificates: detail, delivery, 2 domains', resource: CertificateResource::class, document: [
    'data' => [
        'type' => 'certificate',
        'lid' => 'c1',
        'attributes' => ['code' => 'PLAN-1', 'nominal' => '10.00'],
        'relationships' => [
            'provider' => ['data' => ['type' => 'provider', 'id' => '1']],
            'detail' => ['data' => ['type' => 'detail', 'lid' => 'det1']],
            'delivery' => ['data' => ['type' => 'delivery', 'lid' => 'del1']],
            'domains' => ['data' => [['type' => 'domain', 'lid' => 'd1'], ['type' => 'domain', 'lid' => 'd2']]],
        ],
    ],
    'included' => [
        ['type' => 'detail', 'lid' => 'det1', 'attributes' => ['buyer_email' => 'buyer@example.com']],
        ['type' => 'delivery', 'lid' => 'del1', 'attributes' => ['delivery_channel' => 'EMAIL']],
        ['type' => 'domain', 'lid' => 'd1', 'attributes' => ['domain_name' => 'plan1.example.com']],
        ['type' => 'domain', 'lid' => 'd2', 'attributes' => ['domain_name' => 'plan2.example.com']],
    ],
]);

$run(title: 'POST /certificates: 2 roots, only the second has domains', resource: CertificateResource::class, document: [
    'data' => [
        [
            'type' => 'certificate',
            'lid' => 'c1',
            'attributes' => ['code' => 'PLAN-2', 'nominal' => '20.00'],
            'relationships' => ['provider' => ['data' => ['type' => 'provider', 'id' => '1']]],
        ],
        [
            'type' => 'certificate',
            'lid' => 'c2',
            'attributes' => ['code' => 'PLAN-3', 'nominal' => '30.00'],
            'relationships' => [
                'provider' => ['data' => ['type' => 'provider', 'id' => '2']],
                'domains' => ['data' => [['type' => 'domain', 'lid' => 'd1']]],
            ],
        ],
    ],
    'included' => [['type' => 'domain', 'lid' => 'd1', 'attributes' => ['domain_name' => 'plan3.example.com']]],
]);

$certificate = 1;
$run(
    title: "POST /certificates/{$certificate}/domains: 2 domains of an existing certificate",
    resource: DomainResource::class,
    document: ['data' => [
        ['type' => 'domain', 'lid' => 'd1', 'attributes' => ['domain_name' => 'plan-api.example.org']],
        ['type' => 'domain', 'lid' => 'd2', 'attributes' => ['domain_name' => 'plan-cdn.example.org']],
    ]],
    link: $planner->routeLink(parent: CertificateResource::class, relationship: CertificateResource::DOMAINS, id: $certificate),
);
