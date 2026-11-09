<?php

/**
 * Стадии 2–6 выполнения запроса без парсера: дерево запроса → SelectPlanner (шаги плана, SQL) → IteratorBuilder
 * → документ JSON:API по SQLite базе example/db.
 *
 * Дерево в TREE — то, что QueryParser возвращает для `/certificates?include=provider,detail,delivery,domains.logs`
 * (`php example/query-tree.php`); его можно править руками, чтобы посмотреть, как планировщик обработает
 * другое дерево. Документ по нему совпадает с test/Fixture/document.json.
 *
 *     php example/query-plan.php             # документ
 *     php example/query-plan.php --steps     # дерево плана после каждого шага (nodes, lift, order, close, merge, sql)
 *     php example/query-plan.php --sql       # SQL и параметры каждого запроса плана
 *     php example/query-plan.php --explain   # дерево итераторов (explain())
 *
 * Тот же путь от URL, с парсером, — example/certificates.php.
 */

declare(strict_types=1);

use Ggs\JsonApiEngine\Document\ResourceRegistry;
use Ggs\JsonApiEngine\Example\Resource\CertificateResource;
use Ggs\JsonApiEngine\Example\Resource\DeliveryResource;
use Ggs\JsonApiEngine\Example\Resource\DetailResource;
use Ggs\JsonApiEngine\Example\Resource\DomainResource;
use Ggs\JsonApiEngine\Example\Resource\LogResource;
use Ggs\JsonApiEngine\Example\Resource\ProviderResource;
use Ggs\JsonApiEngine\Executor\Select\IteratorBuilder;
use Ggs\JsonApiEngine\Parser\QueryParser;
use Ggs\JsonApiEngine\Plan\Select\SelectPlanner;
use Ggs\JsonApiEngine\Plan\Select\SelectPlanPrinter;

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/db/connection.php';

/** вход SelectPlanner: дерево запроса от QueryParser */
const TREE = [
    'resource' => CertificateResource::class,
    'fields' => ['code', 'nominal', 'currency', 'status', 'created_at', 'provider'],
    'filters' => [],
    'sort' => [],
    'includes' => [
        'provider' => [
            'type' => QueryParser::MANY_TO_ONE,
            'relation' => ['provider_id', 'id'],
            'resource' => [
                'resource' => ProviderResource::class,
                'fields' => ['name', 'website', 'created_at'],
                'filters' => [],
                'sort' => [],
                'includes' => [],
            ],
        ],
        'detail' => [
            'type' => QueryParser::ONE_TO_ONE,
            'relation' => ['id', 'certificate_id'],
            'resource' => [
                'resource' => DetailResource::class,
                'fields' => ['buyer_email', 'recipient_name', 'recipient_email', 'greeting_text', 'design_template_id'],
                'filters' => [],
                'sort' => [],
                'includes' => [],
            ],
        ],
        'delivery' => [
            'type' => QueryParser::ONE_TO_ONE,
            'relation' => ['id', 'certificate_id'],
            'resource' => [
                'resource' => DeliveryResource::class,
                'fields' => ['delivery_channel', 'is_sent', 'external_provider_id'],
                'filters' => [],
                'sort' => [],
                'includes' => [],
            ],
        ],
        'domains' => [
            'type' => QueryParser::ONE_TO_MANY,
            'relation' => ['id', 'certificate_id'],
            'resource' => [
                'resource' => DomainResource::class,
                'fields' => ['domain_name', 'is_primary', 'created_at'],
                'filters' => [],
                'sort' => [],
                'includes' => [
                    'logs' => [
                        'type' => QueryParser::ONE_TO_MANY,
                        'relation' => ['id', 'domain_id'],
                        'resource' => [
                            'resource' => LogResource::class,
                            'fields' => ['log_level', 'message', 'created_at'],
                            'filters' => [],
                            'sort' => [],
                            'includes' => [],
                        ],
                    ],
                ],
            ],
        ],
    ],
];

// Стадии 2–3: дерево после каждого шага планировщика; последнее ('sql') — план с SQL у каждого владельца запроса
$steps = (new SelectPlanner())->plan(tree: TREE);
$plan = $steps['sql'];

if (in_array('--steps', $argv, true)) {
    foreach ($steps as $name => $root) {
        echo "== {$name}\n", SelectPlanPrinter::tree(node: $root), "\n";
    }
    exit;
}

if (in_array('--sql', $argv, true)) {
    echo SelectPlanPrinter::queries(node: $plan);
    exit;
}

// Стадия 4: дерево итераторов на одно выполнение
$builder = new IteratorBuilder(pdo: getPDO());

if (in_array('--explain', $argv, true)) {
    $flow = $builder->build(plan: $plan, registry: new ResourceRegistry());
    echo json_encode($flow->explain(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit;
}

// Стадии 5–6: дерево выполняется внутри json_encode(): data — поток от корня, included — обход связей по реестру
echo json_encode($builder->document(plan: $plan), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
