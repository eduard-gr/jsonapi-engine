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
        -- MergeNestJoinIterator соединяет домены с логами по domain id: оба потока должны идти по нему
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
        -- MergeNestJoinIterator соединяет логи с доменами по domain id: оба потока должны идти по нему;
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
        -- Справочник: PreloadIterator загружает его в реестр целиком, порядок строк не важен
        p.id
SQL;


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
