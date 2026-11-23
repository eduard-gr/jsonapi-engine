<?php

declare(strict_types=1);


const QUERY1 = <<<SQL
    select 
        cc.id as certificate_id,
        cc.provider_id,
        cc.code,
        cc.nominal,
        cc.currency,
        cc.status,
        cc.created_at,
    
        cd.certificate_id as detail_id,
        cd.buyer_email,
        cd.recipient_name,
        cd.recipient_email,
        cd.greeting_text,
        cd.design_template_id,
        
        cdm.certificate_id as meta_id,
        cdm.delivery_channel,
        cdm.is_sent,
        cdm.sent_at,
        cdm.external_provider_id,
        cdm.delivery_error_log
    from 
        certificates cc
        join certificate_details cd on
            cd.certificate_id = cc.id
        left join certificate_delivery_meta cdm on
            cdm.certificate_id = cc.id
    order by
        cc.id
SQL;

const QUERY2 = <<<SQL
    select 
        id as domain_id, 
        certificate_id,
        domain_name,
        is_primary,
        created_at
    from
        domains as d
    order by
        -- MergeNestJoinReader соединяет домены с логами по domain id: оба потока должны идти по нему
        d.id
SQL;

const QUERY3 = <<<SQL
    select
        id as log_id,
        domain_id,
        log_level,
        message,
        created_at
    from
        domain_logs as l
    order by
        -- MergeNestJoinReader соединяет логи с доменами по domain id: оба потока должны идти по нему;
        -- l.id задаёт порядок логов внутри домена
        l.domain_id,
        l.id
SQL;

const QUERY4 = <<<SQL
    select
        id as provider_id,
        name,
        website,
        created_at
    from
        providers as p
    order by
        -- Справочник: PreloadReader загружает его в реестр целиком, порядок строк не важен
        p.id
SQL;


/*
 * Записи колонок ресурсов для деревьев, собранных руками (example/document.php, DocumentTreeTest): то, что по плану
 * даёт `EntityScanNode::columns`, а здесь написано руками под алиасы QUERY1–QUERY4. Каждая запись — аргумент `columns`
 * узла `SerializeReader`:
 *  - `columns` — «колонка модели ресурса → колонка (алиас) в строке запроса»;
 *  - `fields` — атрибуты и связи по внешнему ключу, которые выводятся, в порядке вывода;
 *  - `keys` — колонки модели, которые станут служебными ключами соединений `ResourceRecord`;
 *  - `alias` — только подпись в explain().
 */

/** Сертификат из строки QUERY1: связь `provider` — по внешнему ключу `provider_id` из своей строки */
const QUERY1_CERTIFICATE = [
    'resource' => \Ggs\JsonApiEngine\Example\Resource\CertificateResource::class,
    'alias' => 'cc',
    'columns' => [
        'id' => 'certificate_id',
        'code' => 'code',
        'nominal' => 'nominal',
        'currency' => 'currency',
        'status' => 'status',
        'created_at' => 'created_at',
        'provider_id' => 'provider_id',
    ],
    'fields' => ['code', 'nominal', 'currency', 'status', 'created_at', 'provider'],
    'keys' => [],
];

/** Детали из строки QUERY1: первичный ключ `certificate_details.certificate_id` — алиас `detail_id` */
const QUERY1_DETAIL = [
    'resource' => \Ggs\JsonApiEngine\Example\Resource\DetailResource::class,
    'alias' => 'cd',
    'columns' => [
        'certificate_id' => 'detail_id',
        'buyer_email' => 'buyer_email',
        'recipient_name' => 'recipient_name',
        'recipient_email' => 'recipient_email',
        'greeting_text' => 'greeting_text',
        'design_template_id' => 'design_template_id',
    ],
    'fields' => ['buyer_email', 'recipient_name', 'recipient_email', 'greeting_text', 'design_template_id'],
    'keys' => [],
];

/** Доставка из строки QUERY1: первичный ключ — алиас `meta_id`; NULL в нём (LEFT JOIN) — доставки нет */
const QUERY1_DELIVERY = [
    'resource' => \Ggs\JsonApiEngine\Example\Resource\DeliveryResource::class,
    'alias' => 'cdm',
    'columns' => [
        'certificate_id' => 'meta_id',
        'delivery_channel' => 'delivery_channel',
        'is_sent' => 'is_sent',
        'external_provider_id' => 'external_provider_id',
    ],
    'fields' => ['delivery_channel', 'is_sent', 'external_provider_id'],
    'keys' => [],
];

/** Домен из строки QUERY2: ключ `certificate_id` — для соединения с сертификатом */
const QUERY2_DOMAIN = [
    'resource' => \Ggs\JsonApiEngine\Example\Resource\DomainResource::class,
    'alias' => 'd',
    'columns' => [
        'id' => 'domain_id',
        'certificate_id' => 'certificate_id',
        'domain_name' => 'domain_name',
        'is_primary' => 'is_primary',
        'created_at' => 'created_at',
    ],
    'fields' => ['domain_name', 'is_primary', 'created_at'],
    'keys' => ['certificate_id'],
];

/** Лог из строки QUERY3: ключ `domain_id` — для соединения с доменом */
const QUERY3_LOG = [
    'resource' => \Ggs\JsonApiEngine\Example\Resource\LogResource::class,
    'alias' => 'l',
    'columns' => [
        'id' => 'log_id',
        'domain_id' => 'domain_id',
        'log_level' => 'log_level',
        'message' => 'message',
        'created_at' => 'created_at',
    ],
    'fields' => ['log_level', 'message', 'created_at'],
    'keys' => ['domain_id'],
];

/** Провайдер из строки QUERY4 */
const QUERY4_PROVIDER = [
    'resource' => \Ggs\JsonApiEngine\Example\Resource\ProviderResource::class,
    'alias' => 'p',
    'columns' => [
        'id' => 'provider_id',
        'name' => 'name',
        'website' => 'website',
        'created_at' => 'created_at',
    ],
    'fields' => ['name', 'website', 'created_at'],
    'keys' => [],
];

/**
 * Подключение к тестовой SQLite базе.
 *
 * Использование:
 *   require_once __DIR__ . '/db/connection.php';
 *   $pdo = getPDO();
 *
 * @param string $path Путь к файлу базы, по умолчанию example/db/database.sqlite
 * @throws RuntimeException если файл базы не найден
 */
function getPDO(string $path = __DIR__ . '/database.sqlite'): PDO
{
    // Без этой проверки SQLite молча создаст пустую базу по неверному пути
    if (!file_exists($path)) {
        throw new RuntimeException("Database not found: {$path}. Run: php example/db/seed.php");
    }

    $pdo = new PDO('sqlite:' . $path, options: [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    // Внешние ключи и ON DELETE CASCADE в SQLite выключены по умолчанию и включаются на каждое подключение
    $pdo->exec('PRAGMA foreign_keys = ON');

    return $pdo;
}
