<?php

/**
 * Прототип INSERT: входной документ JSON:API → дерево вставляемых ресурсов (парсер документа) → дерево вставки
 * (push) → строки в таблицах. Основной код (`src/`), `example/Resource` и `example/Entity` не меняются; все классы
 * прототипа живут в этом файле.
 *
 *     php example/insert-document.php
 *
 * Работает на SQLite базе example/db; каждый пример — в своей транзакции, которая в конце откатывается: база не
 * меняется.
 *
 * **Входной документ.** Базовый JSON:API создаёт одним запросом только один ресурс, а связи в нём — ссылки
 * `{type, id}` на уже существующие ресурсы. Здесь новые связанные ресурсы передаются в `included` (нестандартный
 * sideposting), ссылки на них — через локальный идентификатор `lid` (JSON:API 1.1) вместо `id`; `data` может быть
 * списком (несколько корней, тоже расширение). `id` (генерирует БД) и `created_at` (значение по умолчанию)
 * не передаются.
 *
 * **Стадии** (как в SELECT: парсер → дерево → исполнитель):
 *
 * 1. `DocumentParser` разворачивает плоские `data` + `included` по ссылкам `lid` во вложенное дерево: каждый корень
 *    несёт своё поддерево (ссылка `{type, lid}` заменяется самим ресурсом), ссылки `{type, id}` на существующие
 *    ресурсы остаются ссылками. Все ошибки клиента — здесь (`InvalidDocumentException`, 400/422).
 * 2. Дерево узлов вставки. Уровень ресурсов (`InsertJoinNode` по связям) строится руками, потом его будет строить
 *    планировщик; уровень таблиц под каждым ресурсом строит `TableTreeBuilder` из `model()` ресурса.
 * 3. `InsertDocument` отправляет каждый корень в дерево узлов. Общего контекста нет: ключи возвращаются из `push()`.
 *
 * **Два уровня дерева** (как `SharedRowJoin` и `HashNestJoin` в SELECT):
 *
 * - уровень ресурсов: по дереву идут объекты JSON:API с детьми в `relationships`; `InsertJoinNode` вставляет
 *   владельца связи, берёт детей из `relationships[name]` и отправляет их дальше с колонкой связи;
 * - граница — `InsertResourceNode`: ресурс → строка «колонка модели → значение» (атрибуты, внешние ключи), строка
 *   уходит в поддерево таблиц;
 * - уровень таблиц: `InsertTableJoinNode` делит строку по таблицам модели (`LeftJoin`/`InnerJoin` из `model()`),
 *   `InsertTableNode` — лист, `INSERT` в одну таблицу, про JSON:API ничего не знает.
 *
 * Примеры:
 *
 * `CERTIFICATE_DOCUMENT` (`POST /certificates`): `detail`, `delivery`, `domains` — отдельные ресурсы, каждый
 * из одной таблицы:
 *
 *     InsertJoin(domains, [id, certificate_id])
 *       ├─ first: InsertJoin(delivery, [id, certificate_id])
 *       │    ├─ first: InsertJoin(detail, [id, certificate_id])
 *       │    │    ├─ first: InsertResource(certificate) └─ InsertTable(certificates)
 *       │    │    └─ then:  InsertResource(detail)      └─ InsertTable(certificate_details)
 *       │    └─ then:  InsertResource(delivery)         └─ InsertTable(certificate_delivery_meta)
 *       └─ then:  InsertResource(domain)                └─ InsertTable(domains)
 *
 *   Порядок `detail` раньше `delivery` важен: `certificate_delivery_meta.certificate_id` ссылается на
 *   `certificate_details`, а не на `certificates` (SQLite с `foreign_keys = ON` иначе отказывает). Связи ресурсов
 *   этой зависимости между соседями не видят, её должен учитывать планировщик (по `references` сущностей).
 *
 * `CERTIFICATES_WITH_DETAIL_DOCUMENT` (`POST /certificates`, два корня): ресурс `CertificateWithDetailResource`
 * из двух таблиц, `LeftJoin(certificates, certificate_details, [id, certificate_id])`; модель разворачивается
 * в поддерево таблиц:
 *
 *     InsertJoin(domains, [id, certificate_id])
 *       ├─ first: InsertJoin(delivery, [id, certificate_id])
 *       │    ├─ first: InsertResource(certificate)
 *       │    │    └─ InsertTableJoin(LEFT, [id → certificate_id])
 *       │    │         ├─ first: InsertTable(certificates)
 *       │    │         └─ then:  InsertTable(certificate_details)   ← пропускается, если колонок деталей нет
 *       │    └─ then:  InsertResource(delivery) └─ InsertTable(certificate_delivery_meta)
 *       └─ then:  InsertResource(domain) └─ InsertTable(domains)
 *
 *   Детали вставляются внутри строки сертификата, раньше любых детей, поэтому проблема порядка `delivery` здесь
 *   исчезает. У второго сертификата колонок деталей нет: строка `certificate_details` не вставляется.
 *
 * `DOMAINS_DOCUMENT` (`POST /domains`, два корня): дерево — `InsertResource(domain) └─ InsertTable(domains)`;
 * `certificate_id` приходит из связи по внешнему ключу `certificate: {type, id}`.
 *
 * **Порядок таблиц внутри модели** — из метаданных сущностей (`TableTreeBuilder`): для соединения с `relation`
 * `[l, r]` первой вставляется левая сторона, если `l` — её первичный ключ или `r` ссылается (`references`) на её
 * сущность; правая — если `r` — первичный ключ правой или `l` ссылается на неё. Вторая сторона получает колонку
 * связи из ключей первой. `LeftJoin` делает правую сторону необязательной: если в строке нет ни одной её колонки,
 * она не вставляется (в SELECT это отсутствующая часть, `null`); `InnerJoin` вставляет всегда.
 *
 * **Сущности только для чтения** (`READ_ONLY_ENTITIES`): в таблицу такой сущности вставки нет (справочник,
 * таблица другой системы). В основном коде это будет флаг в классе сущности (например
 * `public const bool READ_ONLY = true;`, по умолчанию false); в прототипе — список, чтобы не менять `example/Entity`.
 * Для таблицы только для чтения нет листа: присоединённая выпадает из поддерева таблиц, её колонки писать нельзя;
 * основная (крайняя левая) делает ресурс несоздаваемым — ни корнем, ни ребёнком по `lid` (парсер отказывает),
 * но сослаться на существующую запись `{type, id}` можно (`provider` у сертификата).
 *
 * Ограничения прототипа:
 * - дерево вставляемых ресурсов — дерево: каждый `lid` из `included` указан ровно в одном месте. Ссылка по `lid`
 *   на ресурс из другой ветки (ресурс с двумя родителями) запрещена парсером;
 * - связь по внешнему ключу ресурса (`provider`, `certificate`) принимает только `id` существующего ресурса;
 *   связь «к многим» и обратная «к одному» (`domains`, `detail`) — только новые ресурсы по `lid`;
 * - порядок «сторона с первичным ключом первой» на уровне ресурсов задан положением ветки `first`, его должен
 *   выбирать планировщик;
 * - вставка по одной строке, без многострочного `INSERT` и без одного SQL с изменяющими данные CTE на модель;
 * - типы значений и обязательные атрибуты парсер не проверяет;
 * - ответ (созданные ресурсы через SELECT-конвейер) не строится: печатаются ключи и строки из базы.
 */

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Example\Insert;

use Ggs\JsonApiEngine\Example\Entity\CertificateDetailsEntity;
use Ggs\JsonApiEngine\Example\Entity\CertificateDeliveryMetaEntity;
use Ggs\JsonApiEngine\Example\Entity\CertificatesEntity;
use Ggs\JsonApiEngine\Example\Entity\DomainsEntity;
use Ggs\JsonApiEngine\Example\Entity\ProvidersEntity;
use Ggs\JsonApiEngine\Example\Resource\CertificateResource;
use Ggs\JsonApiEngine\Example\Resource\DeliveryResource;
use Ggs\JsonApiEngine\Example\Resource\DetailResource;
use Ggs\JsonApiEngine\Example\Resource\DomainResource;
use Ggs\JsonApiEngine\Example\Resource\ProviderResource;
use Ggs\JsonApiEngine\Model\Entity;
use Ggs\JsonApiEngine\Model\Join;
use Ggs\JsonApiEngine\Model\LeftJoin;
use Ggs\JsonApiEngine\Model\ModelTree;
use Ggs\JsonApiEngine\Resource;
use Ggs\JsonApiEngine\Resource\ManyToOne;
use Ggs\JsonApiEngine\Resource\OneToMany;
use Ggs\JsonApiEngine\Resource\OneToOne;
use LogicException;
use PDO;
use RuntimeException;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/db/connection.php';

// --- сущности только для чтения ---

/**
 * Сущности только для чтения: вставки в их таблицы нет. Замена флага в классе сущности, см. docblock файла.
 *
 * @var list<class-string<Entity>>
 */
const READ_ONLY_ENTITIES = [ProvidersEntity::class];

/**
 * @param class-string<Entity> $entity
 */
function isReadOnly(string $entity): bool
{
    return in_array($entity, READ_ONLY_ENTITIES, true);
}

/**
 * Таблицы модели слева направо (нумерация `ModelTree`: 0 — основная).
 *
 * @return list<class-string<Entity>>
 */
function leaves(Join|string $model): array
{
    return is_string($model) ? [$model] : [...leaves($model->left), ...leaves($model->right)];
}

/**
 * Можно ли создать ресурс: основная таблица его модели не только для чтения.
 *
 * @param class-string<Resource> $resource
 */
function insertable(string $resource): bool
{
    return !isReadOnly(leaves($resource::model())[0]);
}

/**
 * Можно ли писать колонку модели ресурса: её таблица не только для чтения.
 *
 * @param class-string<Resource> $resource
 */
function writableColumn(string $resource, string $column): bool
{
    $leaf = ModelTree::of($resource)->columnTable($column)
        ?? throw new LogicException("{$resource}: unknown model column {$column}");

    return !isReadOnly(leaves($resource::model())[$leaf]);
}

// --- документы ---

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

/** `POST /domains`: два домена к существующему сертификату 1, `data` — список. */
const DOMAINS_DOCUMENT = [
    'data' => [
        [
            'type' => 'domain',
            'lid' => 'd1',
            'attributes' => ['domain_name' => 'api.example.org', 'is_primary' => true],
            'relationships' => [
                'certificate' => ['data' => ['type' => 'certificate', 'id' => '1']],
            ],
        ],
        [
            'type' => 'domain',
            'lid' => 'd2',
            'attributes' => ['domain_name' => 'cdn.example.org', 'is_primary' => false],
            'relationships' => [
                'certificate' => ['data' => ['type' => 'certificate', 'id' => '1']],
            ],
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
    'read-only entity as root (POST /providers)' => [ProviderResource::class, [
        'data' => ['type' => 'provider', 'lid' => 'p1', 'attributes' => ['name' => 'New provider']],
    ]],
    'read-only entity as new related resource' => [CertificateResource::class, [
        'data' => [
            'type' => 'certificate',
            'lid' => 'c1',
            'attributes' => ['code' => 'X', 'nominal' => '1.00'],
            'relationships' => ['provider' => ['data' => ['type' => 'provider', 'lid' => 'p1']]],
        ],
        'included' => [['type' => 'provider', 'lid' => 'p1', 'attributes' => ['name' => 'New provider']]],
    ]],
];

// --- ресурсы прототипа ---

/**
 * `DomainResource` со связью по внешнему ключу `certificate` (`domains.certificate_id → certificates.id`): нужна,
 * чтобы домен можно было вставить отдельно, к существующему сертификату. Объявлен в прототипе, чтобы не менять
 * `example/Resource`.
 */
final class DomainWithCertificateResource extends Resource
{
    public const string TYPE = DomainResource::TYPE;

    public const string CERTIFICATE = 'certificate';

    public const array ATTRIBUTES = DomainResource::ATTRIBUTES;

    #[\Override]
    public static function relationships(): array
    {
        return [
            ...DomainResource::relationships(),
            self::CERTIFICATE => new ManyToOne(
                resource: CertificateResource::class,
                relation: [DomainsEntity::CERTIFICATE_ID, CertificatesEntity::ID],
            ),
        ];
    }

    public static function model(): string
    {
        return DomainsEntity::class;
    }
}

/**
 * Сертификат из двух таблиц: `LeftJoin(certificates, certificate_details, [id, certificate_id])`; атрибуты деталей —
 * атрибуты сертификата. Связи — как у `CertificateResource`, без `detail` (детали теперь внутри модели). Объявлен
 * в прототипе, чтобы не менять `example/Resource`.
 */
final class CertificateWithDetailResource extends Resource
{
    public const string TYPE = CertificateResource::TYPE;

    public const string BUYER_EMAIL = 'buyer_email';
    public const string RECIPIENT_NAME = 'recipient_name';
    public const string RECIPIENT_EMAIL = 'recipient_email';
    public const string GREETING_TEXT = 'greeting_text';
    public const string DESIGN_TEMPLATE_ID = 'design_template_id';

    public const array ATTRIBUTES = [
        ...CertificateResource::ATTRIBUTES,
        self::BUYER_EMAIL => ['column' => CertificateDetailsEntity::BUYER_EMAIL, 'type' => self::STRING],
        self::RECIPIENT_NAME => [
            'column' => CertificateDetailsEntity::RECIPIENT_NAME,
            'type' => self::STRING,
            'nullable' => true,
        ],
        self::RECIPIENT_EMAIL => [
            'column' => CertificateDetailsEntity::RECIPIENT_EMAIL,
            'type' => self::STRING,
            'nullable' => true,
        ],
        self::GREETING_TEXT => [
            'column' => CertificateDetailsEntity::GREETING_TEXT,
            'type' => self::STRING,
            'nullable' => true,
        ],
        self::DESIGN_TEMPLATE_ID => [
            'column' => CertificateDetailsEntity::DESIGN_TEMPLATE_ID,
            'type' => self::INT,
            'nullable' => true,
        ],
    ];

    #[\Override]
    public static function relationships(): array
    {
        $relationships = CertificateResource::relationships();
        unset($relationships[CertificateResource::DETAIL]);

        return $relationships;
    }

    public static function model(): LeftJoin
    {
        return new LeftJoin(
            left: CertificatesEntity::class,
            right: CertificateDetailsEntity::class,
            relation: [CertificatesEntity::ID, CertificateDetailsEntity::CERTIFICATE_ID],
        );
    }
}

// --- парсер документа ---

/**
 * Ошибка клиента во входном документе (400/422). Бросается только `DocumentParser`; исполнитель считает дерево
 * корректным и бросает `LogicException`.
 */
final class InvalidDocumentException extends RuntimeException
{
    /**
     * @param string $pointer JSON Pointer на место ошибки (`/data/relationships/domains/data/1`)
     */
    public function __construct(string $detail, public readonly string $pointer)
    {
        parent::__construct("{$pointer}: {$detail}");
    }
}

/**
 * Парсер входного документа: плоские `data` + `included` → список корней, каждый со своим поддеревом.
 *
 * Поток: документ JSON:API + класс корневого ресурса → `list<ресурс>`, где ресурс — объект JSON:API
 * (`type`, `lid`, `attributes`, `relationships`), а в `relationships[name].data` ссылка `{type, lid}` заменена
 * самим ресурсом (рекурсивно), ссылка `{type, id}` на существующий ресурс оставлена как есть.
 *
 * Проверки (всё — `InvalidDocumentException`): `data` — ресурс или список ресурсов; у каждого нового ресурса есть
 * `lid`, без `id`; `type:lid` уникален в документе; `type` совпадает с ресурсом места (корень — корневой ресурс,
 * ребёнок — цель связи); ресурс можно создать (основная таблица модели не только для чтения); атрибут существует
 * и записываемый (не первичный ключ, не `mode` READ, все его колонки — в таблицах не только для чтения); связь
 * существует; связь по внешнему ключу — только `{type, id}`, остальные — только `{type, lid}` из `included`; каждый
 * ресурс из `included` указан ровно один раз и достижим из `data` (поэтому циклов нет: корни в `included` не лежат).
 *
 * Не проверяет: типы значений атрибутов, обязательные атрибуты (`NOT NULL` без значения по умолчанию упадёт
 * в базе).
 */
final class DocumentParser
{
    /** @var array<string, array{resource: array, pointer: string}> type:lid → ресурс из included */
    private array $included = [];

    /** @var array<string, true> type:lid, уже подставленные в дерево */
    private array $used = [];

    /**
     * @param class-string<Resource> $resource корневой ресурс точки входа (`POST /certificates` → CertificateResource)
     * @return list<array> корни с поддеревьями
     */
    public function parse(array $document, string $resource): array
    {
        $this->included = [];
        $this->used = [];

        $data = $document['data'] ?? throw new InvalidDocumentException('data is required', '');
        if (!is_array($data) || $data === []) {
            throw new InvalidDocumentException('data must be a resource or a list of resources', '/data');
        }
        $roots = array_is_list($data) ? $data : [$data];
        $pointers = array_is_list($data)
            ? array_map(static fn(int $i): string => "/data/{$i}", array_keys($data))
            : ['/data'];

        $seen = [];
        foreach ($roots as $i => $root) {
            $key = self::identity($root, $pointers[$i]);
            if (isset($seen[$key])) {
                throw new InvalidDocumentException("duplicate resource {$key}", $pointers[$i]);
            }
            $seen[$key] = true;
        }
        foreach ($document['included'] ?? [] as $i => $item) {
            $pointer = "/included/{$i}";
            $key = self::identity($item, $pointer);
            if (isset($seen[$key])) {
                throw new InvalidDocumentException("duplicate resource {$key}", $pointer);
            }
            $seen[$key] = true;
            $this->included[$key] = ['resource' => $item, 'pointer' => $pointer];
        }

        $tree = [];
        foreach ($roots as $i => $root) {
            $tree[] = $this->resource($root, $resource, $pointers[$i]);
        }

        foreach ($this->included as $key => $item) {
            if (!isset($this->used[$key])) {
                throw new InvalidDocumentException("resource {$key} is not referenced from data", $item['pointer']);
            }
        }

        return $tree;
    }

    /**
     * Новый ресурс места `$class` → он же с подставленными детьми.
     *
     * @param class-string<Resource> $class
     */
    private function resource(array $item, string $class, string $pointer): array
    {
        if ($item['type'] !== $class::type()) {
            throw new InvalidDocumentException("expected type {$class::type()}, got {$item['type']}", "{$pointer}/type");
        }
        if (!insertable($class)) {
            throw new InvalidDocumentException("resource {$class::type()} is read-only and can not be created", $pointer);
        }

        foreach (array_keys($item['attributes'] ?? []) as $name) {
            $at = "{$pointer}/attributes/{$name}";
            $attribute = $class::ATTRIBUTES[$name] ?? throw new InvalidDocumentException("unknown attribute {$name}", $at);
            $writable = $name !== $class::primaryAttribute()
                && ($attribute['mode'] ?? null) !== Resource::READ
                && array_all(
                    $class::attributeColumns($name),
                    static fn(string $column): bool => writableColumn($class, $column),
                );
            if (!$writable) {
                throw new InvalidDocumentException("attribute {$name} is read-only", $at);
            }
        }

        $relationships = [];
        foreach ($item['relationships'] ?? [] as $name => $relationship) {
            $at = "{$pointer}/relationships/{$name}/data";
            $definition = $class::relationship($name)
                ?? throw new InvalidDocumentException("unknown relationship {$name}", "{$pointer}/relationships/{$name}");
            if (!array_key_exists('data', $relationship)) {
                throw new InvalidDocumentException('data is required', $at);
            }
            $data = $relationship['data'];
            $toMany = $definition instanceof OneToMany;
            if ($toMany !== (is_array($data) && array_is_list($data))) {
                throw new InvalidDocumentException($toMany ? 'expected a list' : 'expected a resource identifier or null', $at);
            }

            if ($class::foreignRelationship($name) !== null) {
                $relationships[$name] = ['data' => $data === null ? null : $this->reference($data, $definition->resource, $at)];
                continue;
            }
            $relationships[$name] = ['data' => match (true) {
                $data === null => null,
                $toMany => array_map(
                    fn(int $i): array => $this->child($data[$i], $definition->resource, "{$at}/{$i}"),
                    array_keys($data),
                ),
                default => $this->child($data, $definition->resource, $at),
            }];
        }

        return [...$item, 'relationships' => $relationships];
    }

    /**
     * Ссылка по внешнему ключу: только существующий ресурс `{type, id}`.
     *
     * @param class-string<Resource> $class
     */
    private function reference(array $identifier, string $class, string $pointer): array
    {
        if (($identifier['type'] ?? null) !== $class::type()) {
            throw new InvalidDocumentException("expected type {$class::type()}", "{$pointer}/type");
        }
        if (!isset($identifier['id']) || isset($identifier['lid'])) {
            if (!insertable($class)) {
                throw new InvalidDocumentException(
                    "resource {$class::type()} is read-only: only an existing resource {type, id} can be linked",
                    $pointer,
                );
            }
            throw new InvalidDocumentException('foreign key relationship needs an existing resource {type, id}', $pointer);
        }

        return ['type' => $identifier['type'], 'id' => (string) $identifier['id']];
    }

    /**
     * Ссылка `{type, lid}` на новый ресурс из `included` → сам ресурс с поддеревом; каждый — ровно один раз.
     *
     * @param class-string<Resource> $class
     */
    private function child(array $identifier, string $class, string $pointer): array
    {
        if (isset($identifier['id']) || !isset($identifier['lid'])) {
            throw new InvalidDocumentException('only new resources {type, lid} can be linked here', $pointer);
        }
        $key = "{$identifier['type']}:{$identifier['lid']}";
        $item = $this->included[$key] ?? throw new InvalidDocumentException("unknown resource {$key}", $pointer);
        if (isset($this->used[$key])) {
            throw new InvalidDocumentException("resource {$key} is linked more than once", $pointer);
        }
        $this->used[$key] = true;

        return $this->resource($item['resource'], $class, $item['pointer']);
    }

    private static function identity(mixed $item, string $pointer): string
    {
        if (!is_array($item) || !is_string($item['type'] ?? null)) {
            throw new InvalidDocumentException('resource object with type is required', $pointer);
        }
        if (isset($item['id'])) {
            throw new InvalidDocumentException('new resource must not have id', "{$pointer}/id");
        }
        if (!is_string($item['lid'] ?? null) || $item['lid'] === '') {
            throw new InvalidDocumentException('new resource needs lid', "{$pointer}/lid");
        }

        return "{$item['type']}:{$item['lid']}";
    }
}

// --- уровень таблиц ---

/**
 * Журнал выполненных `INSERT` для печати; удобство прототипа, не часть замысла.
 */
final class StatementLog
{
    /** @var list<array{sql: string, parameters: array<string, mixed>, table: string, primary: string, id: int}> */
    public array $statements = [];
}

/**
 * Узел уровня таблиц: получает строку одного ресурса «колонка модели → значение», вставляет её части в таблицы
 * своего поддерева, возвращает их ключи.
 *
 * Поток: строка ресурса + колонки связи (`link`) → строки в таблицах → ключи «колонка → значение» (записанные
 * колонки и первичные ключи всех вставленных таблиц).
 */
interface TableInsertNode
{
    /**
     * @param array<string, mixed> $row  колонки модели ресурса → значение
     * @param array<string, mixed> $link колонки связи (не обязательно колонки модели: правая колонка `relation`
     *                                   или колонка связи между ресурсами); лист берёт те, что есть в его сущности
     * @return array<string, mixed>
     */
    public function push(array $row, array $link = []): array;

    /**
     * Колонки модели, которые пишет поддерево: по ним необязательная сторона `LeftJoin` понимает, что её нет.
     *
     * @return list<string>
     */
    public function columns(): array;

    public function explain(): array;
}

/**
 * Лист: вставка в одну таблицу.
 *
 * Поток: строка + `link` → свои колонки (колонки модели этой таблицы из строки, колонки связи, которые есть
 * в сущности) → `INSERT INTO <table> (…) VALUES (…) RETURNING <pk>` → ключи: записанные колонки и первичный ключ
 * (по именам колонок сущности, ключам `COLUMNS`). Нет ни одной колонки — `INSERT … DEFAULT VALUES`. Имя колонки
 * в базе — `Entity::column()`.
 */
final class InsertTableNode implements TableInsertNode
{
    /**
     * @param class-string<Entity> $entity
     * @param list<string>         $columns колонки модели ресурса, которые лежат в этой таблице
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $entity,
        private readonly array $columns,
        private readonly StatementLog $log,
    ) {
        if (isReadOnly($entity)) {
            throw new LogicException("{$entity}: entity is read-only, no insert node for it");
        }
    }

    #[\Override]
    public function push(array $row, array $link = []): array
    {
        $entity = $this->entity;
        $values = [
            ...array_intersect_key($row, array_flip($this->columns)),
            ...array_intersect_key($link, $entity::COLUMNS),
        ];
        $primary = $entity::primaryColumn();

        $names = array_map(static fn(string $name): string => $entity::column($name), array_keys($values));
        $sql = $values === []
            ? sprintf('INSERT INTO %s DEFAULT VALUES RETURNING %s', $entity::TABLE, $entity::column($primary))
            : sprintf(
                'INSERT INTO %s (%s) VALUES (%s) RETURNING %s',
                $entity::TABLE,
                implode(', ', $names),
                implode(', ', array_map(static fn(string $name): string => ":{$name}", $names)),
                $entity::column($primary),
            );
        $statement = $this->pdo->prepare($sql);
        foreach ($values as $name => $value) {
            $statement->bindValue(':' . $entity::column($name), $value, match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            });
        }
        $statement->execute();
        $id = (int) $statement->fetchColumn();
        $statement->closeCursor();

        $this->log->statements[] = [
            'sql' => $sql,
            'parameters' => $values,
            'table' => $entity::TABLE,
            'primary' => $entity::column($primary),
            'id' => $id,
        ];

        return [...$values, $primary => $id];
    }

    #[\Override]
    public function columns(): array
    {
        return $this->columns;
    }

    #[\Override]
    public function explain(): array
    {
        return ['node' => 'InsertTable', 'table' => $this->entity::TABLE, 'columns' => $this->columns];
    }
}

/**
 * Соединение таблиц модели при вставке: строка ресурса делится между двумя сторонами; сторона с первичным ключом
 * связи (`first`) вставляется первой, вторая (`then`) получает колонку связи из её ключей.
 *
 * Поток: строка + `link` → `first->push()` → ключи → `then->push(строка, link + [relation[1] => keys[relation[0]]])`
 * → объединённые ключи обеих сторон.
 *
 * `optional` (сторона `LeftJoin` справа — `first` или `then`): если в строке нет ни одной колонки модели этой
 * стороны, она не вставляется; пропущенная `first` не даёт колонку связи (`then` пишет её значение по умолчанию,
 * обычно NULL). Входящий `link` уходит в обе стороны: лист берёт из него только колонки своей сущности.
 */
final class InsertTableJoinNode implements TableInsertNode
{
    /**
     * @param array{0: string, 1: string} $relation [колонка `first`, колонка `then`]
     * @param 'first'|'then'|null         $optional необязательная сторона (`LeftJoin`); null — `InnerJoin`
     */
    public function __construct(
        private readonly array $relation,
        private readonly TableInsertNode $first,
        private readonly TableInsertNode $then,
        private readonly ?string $optional,
    ) {
    }

    #[\Override]
    public function push(array $row, array $link = []): array
    {
        $first = $this->present('first', $row) ? $this->first->push($row, $link) : [];

        $thenLink = $link;
        if ($first !== []) {
            $thenLink[$this->relation[1]] = $first[$this->relation[0]]
                ?? throw new LogicException("No key {$this->relation[0]} after insert");
        }
        $then = $this->present('then', $row) ? $this->then->push($row, $thenLink) : [];

        return [...$first, ...$then];
    }

    /**
     * Вставлять ли сторону: обязательная — всегда, необязательная — если в строке есть её колонка.
     */
    private function present(string $side, array $row): bool
    {
        return $this->optional !== $side
            || array_intersect_key($row, array_flip($this->{$side}->columns())) !== [];
    }

    #[\Override]
    public function columns(): array
    {
        return [...$this->first->columns(), ...$this->then->columns()];
    }

    #[\Override]
    public function explain(): array
    {
        return [
            'node' => 'InsertTableJoin',
            'relation' => $this->relation,
            'optional' => $this->optional,
            'first' => $this->first->explain(),
            'then' => $this->then->explain(),
        ];
    }
}

/**
 * Разворачивает модель ресурса (`model()`: сущность или дерево `LeftJoin`/`InnerJoin`) в поддерево таблиц.
 * Прообраз части будущего планировщика вставки.
 *
 * Поток: класс ресурса → `TableInsertNode`: сущность → `InsertTableNode` со своими колонками модели
 * (`ModelTree::columnTable()`), соединение → `InsertTableJoinNode` с порядком по метаданным сущностей:
 * `relation` `[l, r]`, левая первой, если `l` — первичный ключ левой сущности или `r` ссылается на неё
 * (`references`); правая первой, если `r` — первичный ключ правой или `l` ссылается на неё; иначе `LogicException`.
 * Сущность колонки `relation` ищется по имени в своём поддереве (первая таблица слева, где она есть), как
 * в `ModelTree`. Таблицы только для чтения листа не получают: присоединённая сторона выпадает (остаётся другая),
 * основная — `LogicException` (такой ресурс парсер не пропускает).
 */
final class TableTreeBuilder
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly StatementLog $log,
    ) {
    }

    /**
     * @param class-string<Resource> $resource
     */
    public function build(string $resource): TableInsertNode
    {
        $tree = ModelTree::of($resource);
        $columns = [];
        foreach ($tree->columns() as $column) {
            $columns[$tree->columnTable($column)][] = $column;
        }
        $leaf = 0;

        return $this->node($resource::model(), $columns, $leaf)
            ?? throw new LogicException("{$resource}: main table is read-only, nothing to insert");
    }

    /**
     * @param array<int, list<string>> $columns номер таблицы → её колонки модели
     * @param int                      $leaf    номер следующей таблицы слева направо
     */
    private function node(Join|string $model, array $columns, int &$leaf): ?TableInsertNode
    {
        if (is_string($model)) {
            $number = $leaf++;

            return isReadOnly($model) ? null : new InsertTableNode($this->pdo, $model, $columns[$number] ?? [], $this->log);
        }

        $left = $this->node($model->left, $columns, $leaf);
        $right = $this->node($model->right, $columns, $leaf);
        if ($left === null || $right === null) {
            return $left;
        }

        [$l, $r] = $model->relation;
        $leftEntity = self::owner($model->left, $l);
        $rightEntity = self::owner($model->right, $r);
        $optional = $model instanceof LeftJoin;

        if ($leftEntity::primaryColumn() === $l || ($rightEntity::COLUMNS[$r]['references'] ?? null) === $leftEntity) {
            return new InsertTableJoinNode(relation: [$l, $r], first: $left, then: $right, optional: $optional ? 'then' : null);
        }
        if ($rightEntity::primaryColumn() === $r || ($leftEntity::COLUMNS[$l]['references'] ?? null) === $rightEntity) {
            return new InsertTableJoinNode(relation: [$r, $l], first: $right, then: $left, optional: $optional ? 'first' : null);
        }

        throw new LogicException("Can not order join on [{$l}, {$r}]: no primary key or reference between the tables");
    }

    /**
     * Сущность колонки в поддереве: первая таблица слева, где колонка есть.
     *
     * @return class-string<Entity>
     */
    private static function owner(Join|string $model, string $column): string
    {
        foreach (leaves($model) as $entity) {
            if (isset($entity::COLUMNS[$column])) {
                return $entity;
            }
        }

        throw new LogicException("Column {$column} is not in the join subtree");
    }
}

// --- уровень ресурсов ---

/**
 * Узел уровня ресурсов (push): получает ресурс с поддеревом сверху, вставляет его (и, если это связь, детей),
 * возвращает ключи строки ресурса.
 *
 * Поток: ресурс из дерева `DocumentParser` + колонки связи от родителя → строки в базе → ключи ресурса.
 */
interface InsertNode
{
    /**
     * @param array                $resource ресурс из дерева `DocumentParser` (с детьми в `relationships`)
     * @param array<string, mixed> $link     колонки связи от родительского узла (`certificate_id => 21`)
     * @return array<string, mixed> ключи строки ресурса: записанные колонки + первичные ключи его таблиц
     */
    public function push(array $resource, array $link = []): array;

    /**
     * Описание поддерева без выполнения.
     */
    public function explain(): array;
}

/**
 * Граница уровней: ресурс JSON:API → строка «колонка модели → значение» → поддерево таблиц (`input`).
 *
 * Поток: ресурс + колонки связи → строка: атрибуты из `attributes` (по `ATTRIBUTES[...]['column']`), связи по
 * внешнему ключу (`provider` → `provider_id` из `{type, id}`, `null` → NULL) → `input->push(строка, link)` → ключи.
 * Остальные связи (`domains`) не трогает: их детей вставляет `InsertJoinNode`. Составной атрибут требует
 * статического `deserialize<Attribute>($value): array<column, value>` у ресурса (обратного `serialize<Attribute>`).
 * Вход проверен парсером; несоответствие — `LogicException`.
 */
final class InsertResourceNode implements InsertNode
{
    /**
     * @param class-string<Resource> $resource
     */
    public function __construct(
        private readonly string $resource,
        private readonly TableInsertNode $input,
    ) {
    }

    #[\Override]
    public function push(array $resource, array $link = []): array
    {
        $class = $this->resource;
        if ($resource['type'] !== $class::type()) {
            throw new LogicException("Expected {$class::type()}, got {$resource['type']}");
        }

        return $this->input->push(
            [...$this->attributes($resource['attributes'] ?? []), ...$this->foreignKeys($resource)],
            $link,
        );
    }

    /**
     * @param array<string, mixed> $attributes имя в API → значение
     * @return array<string, mixed> колонка модели → значение
     */
    private function attributes(array $attributes): array
    {
        $class = $this->resource;
        $row = [];
        foreach ($attributes as $name => $value) {
            $attribute = $class::ATTRIBUTES[$name] ?? throw new LogicException("{$class}: unknown attribute {$name}");
            if (isset($attribute['columns'])) {
                $method = 'deserialize' . str_replace('_', '', ucwords($name, '_'));
                if (!method_exists($class, $method)) {
                    throw new LogicException("{$class}: composite attribute {$name} needs {$method}()");
                }
                $row = [...$row, ...$class::$method($value)];
                continue;
            }
            $row[$attribute['column'] ?? $name] = $value;
        }

        return $row;
    }

    /**
     * Колонки связей по внешнему ключу ресурса: `{type, id}` → int, `null` → NULL.
     *
     * @return array<string, int|null> колонка модели → значение
     */
    private function foreignKeys(array $resource): array
    {
        $class = $this->resource;
        $row = [];
        foreach ($resource['relationships'] ?? [] as $name => $relationship) {
            $foreign = $class::foreignRelationship($name);
            if ($foreign === null) {
                continue;
            }
            $data = $relationship['data'];
            $row[$foreign->relation[0]] = $data === null
                ? null
                : (int) ($data['id'] ?? throw new LogicException("{$class}: {$name} needs an existing id"));
        }

        return $row;
    }

    #[\Override]
    public function explain(): array
    {
        return ['node' => 'InsertResource', 'resource' => $this->resource::type(), 'input' => $this->input->explain()];
    }
}

/**
 * Связь между ресурсами при вставке: сторона с первичным ключом связи (`first`, владелец связи `name`) вставляется
 * первой, её дети — после (`then`), с колонкой связи из ключей владельца.
 *
 * Поток: владелец с поддеревом → `first` (вставка, возвращает ключи) → дети из `relationships[name].data` (ресурс
 * или список) → каждый в `then` с `link = [relation[1] => keys[relation[0]]]` → ключи владельца наверх.
 *
 * Владелец передаётся в `first` целиком, поэтому `first` может быть и другим `InsertJoinNode` (левый глубокий
 * список связей одного владельца, как в SELECT): он вернёт ключи владельца. Детей нет (`data: null`, пустой список,
 * связи нет в документе) — `then` не вызывается.
 */
final class InsertJoinNode implements InsertNode
{
    /**
     * @param array{0: string, 1: string} $relation [колонка владельца, колонка ребёнка]
     */
    public function __construct(
        private readonly string $name,
        private readonly array $relation,
        private readonly InsertNode $first,
        private readonly InsertNode $then,
    ) {
    }

    #[\Override]
    public function push(array $resource, array $link = []): array
    {
        $keys = $this->first->push($resource, $link);

        $data = $resource['relationships'][$this->name]['data'] ?? null;
        if ($data === null || $data === []) {
            return $keys;
        }
        $value = $keys[$this->relation[0]]
            ?? throw new LogicException("No key {$this->relation[0]} for {$this->name}");

        foreach (array_is_list($data) ? $data : [$data] as $child) {
            $this->then->push($child, [$this->relation[1] => $value]);
        }

        return $keys;
    }

    #[\Override]
    public function explain(): array
    {
        return [
            'node' => 'InsertJoin',
            'name' => $this->name,
            'relation' => $this->relation,
            'first' => $this->first->explain(),
            'then' => $this->then->explain(),
        ];
    }
}

/**
 * Корень вставки: отправляет каждый корень дерева `DocumentParser` в одно дерево узлов.
 *
 * Поток: `list<ресурс>` → `root->push()` по очереди → `list<ключи корня>` в порядке `data` (по ним строится ответ
 * SELECT-конвейером).
 */
final class InsertDocument
{
    /**
     * @param list<array> $roots корни из `DocumentParser::parse()`
     */
    public function __construct(
        private readonly InsertNode $root,
        private readonly array $roots,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function execute(): array
    {
        return array_map(fn(array $resource): array => $this->root->push($resource), $this->roots);
    }

    public function explain(): array
    {
        return ['node' => 'InsertDocument', 'roots' => count($this->roots), 'root' => $this->root->explain()];
    }
}

// --- запуск примеров ---

$pdo = \getPDO();
$json = static fn(mixed $value): string => json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

/**
 * Парсит документ, строит дерево вставки, выполняет его в транзакции и откатывает.
 *
 * @param class-string<Resource>                                         $resource
 * @param callable(callable(class-string<Resource>): InsertNode): InsertNode $tree дерево уровня ресурсов (руками,
 *        вместо планировщика); аргумент строит `InsertResourceNode` с поддеревом таблиц
 */
$run = static function (string $title, array $document, string $resource, callable $tree) use ($pdo, $json): void {
    echo "######## {$title}\n\n";

    $roots = new DocumentParser()->parse($document, $resource);

    $log = new StatementLog();
    $builder = new TableTreeBuilder($pdo, $log);
    $insert = new InsertDocument(
        root: $tree(static fn(string $class): InsertNode => new InsertResourceNode($class, $builder->build($class))),
        roots: $roots,
    );
    echo "== explain\n", $json($insert->explain()), "\n\n";

    $pdo->beginTransaction();
    try {
        $keys = $insert->execute();

        echo "== statements\n";
        foreach ($log->statements as $statement) {
            echo $statement['sql'], "\n    ", json_encode($statement['parameters']), " → {$statement['id']}\n";
        }
        echo "\n== root keys\n";
        foreach ($keys as $key) {
            echo json_encode($key), "\n";
        }

        echo "\n== rows in database\n";
        foreach ($log->statements as $statement) {
            $select = $pdo->prepare("SELECT * FROM {$statement['table']} WHERE {$statement['primary']} = :id");
            $select->execute([':id' => $statement['id']]);
            echo $statement['table'], ': ', json_encode($select->fetch(PDO::FETCH_ASSOC)), "\n";
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
    tree: static fn(callable $resource): InsertNode => new InsertJoinNode(
        name: CertificateResource::DOMAINS,
        relation: CertificateResource::relationship(CertificateResource::DOMAINS)->relation,
        first: new InsertJoinNode(
            name: CertificateResource::DELIVERY,
            relation: CertificateResource::relationship(CertificateResource::DELIVERY)->relation,
            first: new InsertJoinNode(
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
    tree: static fn(callable $resource): InsertNode => new InsertJoinNode(
        name: CertificateResource::DOMAINS,
        relation: CertificateWithDetailResource::relationship(CertificateResource::DOMAINS)->relation,
        first: new InsertJoinNode(
            name: CertificateResource::DELIVERY,
            relation: CertificateWithDetailResource::relationship(CertificateResource::DELIVERY)->relation,
            first: $resource(CertificateWithDetailResource::class),
            then: $resource(DeliveryResource::class),
        ),
        then: $resource(DomainResource::class),
    ),
);

$run(
    title: 'POST /domains: 2 domains of existing certificate 1',
    document: DOMAINS_DOCUMENT,
    resource: DomainWithCertificateResource::class,
    tree: static fn(callable $resource): InsertNode => $resource(DomainWithCertificateResource::class),
);

echo "######## invalid documents\n\n";
foreach (INVALID_DOCUMENTS as $name => [$resource, $document]) {
    try {
        new DocumentParser()->parse($document, $resource);
        echo "{$name}: accepted (unexpected)\n";
    } catch (InvalidDocumentException $e) {
        echo "{$name}: {$e->getMessage()}\n";
    }
}
