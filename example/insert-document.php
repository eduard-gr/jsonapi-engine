<?php

/**
 * Вставка: входной документ JSON:API → `Parser\DocumentParser` (вложенное дерево новых ресурсов) → дерево вставки
 * (`Executor\…Writer`, push) → строки в таблицах SQLite базы example/db.
 *
 *     php example/insert-document.php            # дерево (explain) и ключи корней; SQL каждого INSERT — в stderr
 *     php example/insert-document.php 2>sql.log  # SQL отдельно
 *
 * Каждый пример выполняется в своей транзакции, которая в конце откатывается: база не меняется.
 *
 * Планировщика вставки пока нет: дерево уровня ресурсов (`InsertJoinWriter` по связям, сторона с первичным ключом —
 * `first`) собирается здесь руками, поддерево таблиц под каждым ресурсом строит `TableTreeBuilder` из `model()`.
 *
 * Примеры:
 *  - `CERTIFICATE_DOCUMENT` (`POST /certificates`): сертификат, `detail`, `delivery` и три домена — отдельные ресурсы
 *    из одной таблицы каждый. `detail` стоит глубже `delivery`: `certificate_delivery_meta.certificate_id` ссылается
 *    на `certificate_details` (SQLite с `foreign_keys = ON` иначе отказывает);
 *  - `CERTIFICATES_WITH_DETAIL_DOCUMENT` (`POST /certificates`, два корня): `CertificateWithDetailResource` из двух
 *    таблиц, модель разворачивается в `InsertTableJoinWriter(certificates → certificate_details)`; у второго
 *    сертификата колонок деталей нет — строка `certificate_details` не вставляется;
 *  - `DOMAINS_DOCUMENT` (`POST /certificates/1/domains`, два корня): домены к существующему сертификату 1.
 *    `certificate_id` берётся из маршрута: связь родителя `CertificateResource::DOMAINS` (`[id, certificate_id]`)
 *    даёт `link` `['certificate_id' => 1]` для всех корней (`InsertDocument::$link`); своей связи `certificate`
 *    у `DomainResource` нет;
 *  - `INVALID_DOCUMENTS`: что отсекает парсер (`InvalidDocumentException` с JSON Pointer).
 */

declare(strict_types=1);

use Ggs\JsonApiEngine\Example\Resource\CertificateResource;
use Ggs\JsonApiEngine\Example\Resource\CertificateWithDetailResource;
use Ggs\JsonApiEngine\Example\Resource\DeliveryResource;
use Ggs\JsonApiEngine\Example\Resource\DetailResource;
use Ggs\JsonApiEngine\Example\Resource\DomainResource;
use Ggs\JsonApiEngine\Example\Resource\ProviderResource;
use Ggs\JsonApiEngine\Executor\ExecutorWriter;
use Ggs\JsonApiEngine\Executor\InsertDocument;
use Ggs\JsonApiEngine\Executor\InsertJoinWriter;
use Ggs\JsonApiEngine\Executor\InsertResourceWriter;
use Ggs\JsonApiEngine\Executor\TableTreeBuilder;
use Ggs\JsonApiEngine\Parser\DocumentParser;
use Ggs\JsonApiEngine\Parser\InvalidDocumentException;
use Ggs\JsonApiEngine\Resource;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/db/connection.php';

/** `POST /certificates`: сертификат, его детали, доставка и три домена — отдельные ресурсы. */
const CERTIFICATE_DOCUMENT = [
    'data' => [
        'type' => 'certificate',
        'lid' => 'c1',
        'attributes' => [
            'code' => 'CERT-0001',
            'nominal' => '100.00',
            'currency' => 'EUR',
            'status' => 'ACTIVE',
        ],
        'relationships' => [
            'provider' => ['data' => ['type' => 'provider', 'id' => '1']],
            'detail' => ['data' => ['type' => 'detail', 'lid' => 'det1']],
            'delivery' => ['data' => ['type' => 'delivery', 'lid' => 'del1']],
            'domains' => [
                'data' => [
                    ['type' => 'domain', 'lid' => 'd1'],
                    ['type' => 'domain', 'lid' => 'd2'],
                    ['type' => 'domain', 'lid' => 'd3'],
                ],
            ],
        ],
    ],
    'included' => [
        [
            'type' => 'detail',
            'lid' => 'det1',
            'attributes' => [
                'buyer_email' => 'buyer@example.com',
                'recipient_name' => 'Anna',
                'recipient_email' => 'anna@example.com',
                'greeting_text' => 'Happy birthday!',
            ],
        ],
        [
            'type' => 'delivery',
            'lid' => 'del1',
            'attributes' => ['delivery_channel' => 'EMAIL', 'is_sent' => false],
        ],
        [
            'type' => 'domain',
            'lid' => 'd1',
            'attributes' => ['domain_name' => 'example.com', 'is_primary' => true],
        ],
        [
            'type' => 'domain',
            'lid' => 'd2',
            'attributes' => ['domain_name' => 'www.example.com', 'is_primary' => false],
        ],
        [
            'type' => 'domain',
            'lid' => 'd3',
            'attributes' => ['domain_name' => 'shop.example.com', 'is_primary' => false],
        ],
    ],
];

/**
 * `POST /certificates` для `CertificateWithDetailResource` (детали — атрибуты сертификата): два корня. Первый —
 * с деталями, доставкой и доменом; второй — без колонок деталей (строка `certificate_details` не вставляется).
 */
const CERTIFICATES_WITH_DETAIL_DOCUMENT = [
    'data' => [
        [
            'type' => 'certificate',
            'lid' => 'c1',
            'attributes' => [
                'code' => 'CERT-0002',
                'nominal' => '50.00',
                'currency' => 'EUR',
                'buyer_email' => 'buyer@example.com',
                'recipient_name' => 'Boris',
            ],
            'relationships' => [
                'provider' => ['data' => ['type' => 'provider', 'id' => '1']],
                'delivery' => ['data' => ['type' => 'delivery', 'lid' => 'del1']],
                'domains' => ['data' => [['type' => 'domain', 'lid' => 'd1']]],
            ],
        ],
        [
            'type' => 'certificate',
            'lid' => 'c2',
            'attributes' => ['code' => 'CERT-0003', 'nominal' => '75.00'],
            'relationships' => [
                'provider' => ['data' => ['type' => 'provider', 'id' => '2']],
            ],
        ],
    ],
    'included' => [
        [
            'type' => 'delivery',
            'lid' => 'del1',
            'attributes' => ['delivery_channel' => 'SMS', 'is_sent' => false],
        ],
        [
            'type' => 'domain',
            'lid' => 'd1',
            'attributes' => ['domain_name' => 'gift.example.com', 'is_primary' => true],
        ],
    ],
];

/** `POST /certificates/1/domains`: два домена; сертификат — из маршрута, не из документа. */
const DOMAINS_DOCUMENT = [
    'data' => [
        [
            'type' => 'domain',
            'lid' => 'd1',
            'attributes' => ['domain_name' => 'api.example.org', 'is_primary' => true],
        ],
        [
            'type' => 'domain',
            'lid' => 'd2',
            'attributes' => ['domain_name' => 'cdn.example.org', 'is_primary' => false],
        ],
    ],
];

/**
 * Ошибочные документы: что отсекает парсер (имя → [корневой ресурс, документ]).
 */
const INVALID_DOCUMENTS = [
    'lid used twice' => [CertificateResource::class, [
        'data' => [
            'type' => 'certificate',
            'lid' => 'c1',
            'attributes' => ['code' => 'X', 'nominal' => '1.00'],
            'relationships' => [
                'domains' => ['data' => [['type' => 'domain', 'lid' => 'd1'], ['type' => 'domain', 'lid' => 'd1']]],
            ],
        ],
        'included' => [['type' => 'domain', 'lid' => 'd1', 'attributes' => ['domain_name' => 'a.com']]],
    ]],
    'unknown lid' => [CertificateResource::class, [
        'data' => [
            'type' => 'certificate',
            'lid' => 'c1',
            'relationships' => ['domains' => ['data' => [['type' => 'domain', 'lid' => 'd9']]]],
        ],
    ]],
    'unused included' => [CertificateResource::class, [
        'data' => ['type' => 'certificate', 'lid' => 'c1', 'attributes' => ['code' => 'X']],
        'included' => [['type' => 'domain', 'lid' => 'd1', 'attributes' => ['domain_name' => 'a.com']]],
    ]],
    'read-only attribute' => [CertificateResource::class, [
        'data' => ['type' => 'certificate', 'lid' => 'c1', 'attributes' => ['id' => '5']],
    ]],
    'existing resource in to-many' => [CertificateResource::class, [
        'data' => [
            'type' => 'certificate',
            'lid' => 'c1',
            'relationships' => ['domains' => ['data' => [['type' => 'domain', 'id' => '7']]]],
        ],
    ]],
    'not insertable resource as root (POST /providers)' => [ProviderResource::class, [
        'data' => ['type' => 'provider', 'lid' => 'p1', 'attributes' => ['name' => 'New provider']],
    ]],
    'not insertable resource as new related resource' => [CertificateResource::class, [
        'data' => [
            'type' => 'certificate',
            'lid' => 'c1',
            'attributes' => ['code' => 'X', 'nominal' => '1.00'],
            'relationships' => ['provider' => ['data' => ['type' => 'provider', 'lid' => 'p1']]],
        ],
        'included' => [['type' => 'provider', 'lid' => 'p1', 'attributes' => ['name' => 'New provider']]],
    ]],
    'not insertable child (log via sideposting)' => [DomainResource::class, [
        'data' => [
            'type' => 'domain',
            'lid' => 'd1',
            'attributes' => ['domain_name' => 'a.com'],
            'relationships' => ['logs' => ['data' => [['type' => 'log', 'lid' => 'l1']]]],
        ],
        'included' => [['type' => 'log', 'lid' => 'l1', 'attributes' => ['message' => 'created']]],
    ]],
];

$pdo = getPDO();
$json = static fn(mixed $value): string => (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

/**
 * Парсит документ, строит дерево вставки, выполняет его в транзакции и откатывает.
 *
 * @param class-string<Resource>                                               $resource корневой ресурс точки входа
 * @param callable(callable(class-string<Resource>): ExecutorWriter): ExecutorWriter $tree     дерево уровня ресурсов
 *        (руками, вместо планировщика); аргумент строит `InsertResourceWriter` с поддеревом таблиц
 * @param array<string, mixed>                                                 $link     колонки связи из маршрута
 *        вложенного ресурса, всем корням
 */
$run = static function (
    string $title,
    array $document,
    string $resource,
    callable $tree,
    array $link = [],
) use ($pdo, $json): void {
    echo "######## {$title}\n\n";

    $roots = new DocumentParser()->parse(document: $document, resource: $resource);

    $tables = new TableTreeBuilder(pdo: $pdo);
    $insert = new InsertDocument(
        root: $tree(static fn(string $class): ExecutorWriter => new InsertResourceWriter(
            resource: $class,
            input: $tables->build($class),
        )),
        roots: $roots,
        link: $link,
    );
    echo "== explain\n", $json($insert->explain()), "\n\n";

    $pdo->beginTransaction();
    try {
        echo "== statements (error_log)\n";
        $keys = $insert->execute();

        echo "\n== root keys\n";
        foreach ($keys as $key) {
            echo json_encode($key), "\n";
        }
        echo "\n";
    } finally {
        $pdo->rollBack();
    }
};

$run(
    title: 'POST /certificates: detail, delivery and domains as separate resources',
    document: CERTIFICATE_DOCUMENT,
    resource: CertificateResource::class,
    tree: static fn(callable $resource): ExecutorWriter => new InsertJoinWriter(
        name: CertificateResource::DOMAINS,
        relation: CertificateResource::relationship(CertificateResource::DOMAINS)->relation,
        first: new InsertJoinWriter(
            name: CertificateResource::DELIVERY,
            relation: CertificateResource::relationship(CertificateResource::DELIVERY)->relation,
            first: new InsertJoinWriter(
                name: CertificateResource::DETAIL,
                relation: CertificateResource::relationship(CertificateResource::DETAIL)->relation,
                first: $resource(CertificateResource::class),
                then: $resource(DetailResource::class),
            ),
            then: $resource(DeliveryResource::class),
        ),
        then: $resource(DomainResource::class),
    ),
);

$run(
    title: 'POST /certificates: certificate over two tables (LEFT JOIN certificate_details), 2 roots',
    document: CERTIFICATES_WITH_DETAIL_DOCUMENT,
    resource: CertificateWithDetailResource::class,
    tree: static fn(callable $resource): ExecutorWriter => new InsertJoinWriter(
        name: CertificateWithDetailResource::DOMAINS,
        relation: CertificateWithDetailResource::relationship(CertificateWithDetailResource::DOMAINS)->relation,
        first: new InsertJoinWriter(
            name: CertificateWithDetailResource::DELIVERY,
            relation: CertificateWithDetailResource::relationship(CertificateWithDetailResource::DELIVERY)->relation,
            first: $resource(CertificateWithDetailResource::class),
            then: $resource(DeliveryResource::class),
        ),
        then: $resource(DomainResource::class),
    ),
);

// Маршрут POST /certificates/{id}/domains: id родителя → колонка связи по связи родителя (relation[1])
$certificate = 1;
$domains = CertificateResource::relationship(CertificateResource::DOMAINS);
$run(
    title: "POST /certificates/{$certificate}/domains: 2 domains of an existing certificate",
    document: DOMAINS_DOCUMENT,
    resource: DomainResource::class,
    tree: static fn(callable $resource): ExecutorWriter => $resource(DomainResource::class),
    link: [$domains->relation[1] => $certificate],
);

echo "######## invalid documents\n\n";
foreach (INVALID_DOCUMENTS as $name => [$resource, $document]) {
    try {
        new DocumentParser()->parse(document: $document, resource: $resource);
        echo "{$name}: accepted (unexpected)\n";
    } catch (InvalidDocumentException $e) {
        echo "{$name}: {$e->pointer}: {$e->getMessage()}\n";
    }
}
