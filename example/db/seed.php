<?php

declare(strict_types=1);

/**
 * Создаёт SQLite базу по example/db/schema.sql и наполняет её тестовыми данными.
 *
 * Запуск: php example/db/seed.php [количество сертификатов] [путь к базе]
 * По умолчанию 20 сертификатов в example/db/database.sqlite. Существующая база пересоздаётся.
 * Генератор случайных чисел инициализирован константой, поэтому данные одинаковы при каждом запуске.
 */

$certificate_count = (int) ($argv[1] ?? 20);
$database_path = $argv[2] ?? __DIR__ . '/database.sqlite';

if (file_exists($database_path)) {
    unlink($database_path);
}

$pdo = new PDO('sqlite:' . $database_path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec(file_get_contents(__DIR__ . '/schema.sql'));

mt_srand(17);

function pick(array $items): mixed
{
    return $items[mt_rand(0, count($items) - 1)];
}

function chance(int $percent): bool
{
    return mt_rand(1, 100) <= $percent;
}

function addSeconds(string $timestamp, int $seconds): string
{
    return date('Y-m-d H:i:s', strtotime($timestamp) + $seconds);
}

// Мужская фамилия => женская, чтобы имя и фамилия получателя совпадали по роду
$last_names = ['Иванов' => 'Иванова', 'Петров' => 'Петрова', 'Смирнов' => 'Смирнова', 'Кузнецов' => 'Кузнецова', 'Соколов' => 'Соколова'];
$first_names = [
    'male' => ['Иван', 'Пётр', 'Алексей', 'Дмитрий'],
    'female' => ['Анна', 'Мария', 'Елена', 'Ольга'],
];
$greetings = ['С днём рождения!', 'Поздравляем с праздником!', 'Спасибо за вашу работу!', 'С Новым годом!'];
$zones = ['ru', 'com', 'net', 'org', 'io'];
$words = ['alpha', 'shop', 'cloud', 'store', 'media', 'tech', 'nova', 'pixel', 'data', 'green'];
$subdomains = ['www', 'api', 'shop', 'mail', 'cdn', 'admin'];

$insert_provider = $pdo->prepare(
    'INSERT INTO providers (name, website, created_at) VALUES (?, ?, ?)'
);
$insert_certificate = $pdo->prepare(
    'INSERT INTO certificates (provider_id, code, nominal, currency, status, created_at) VALUES (?, ?, ?, ?, ?, ?)'
);
$insert_details = $pdo->prepare(
    'INSERT INTO certificate_details (certificate_id, buyer_email, recipient_name, recipient_email, greeting_text, design_template_id)
     VALUES (?, ?, ?, ?, ?, ?)'
);
$insert_delivery = $pdo->prepare(
    'INSERT INTO certificate_delivery_meta (certificate_id, delivery_channel, is_sent, sent_at, external_provider_id, delivery_error_log)
     VALUES (?, ?, ?, ?, ?, ?)'
);
$insert_domain = $pdo->prepare(
    'INSERT INTO domains (certificate_id, domain_name, is_primary, created_at) VALUES (?, ?, ?, ?)'
);
$insert_config = $pdo->prepare(
    'INSERT INTO domain_configs (domain_id, auto_renew, dns_provider, ssl_redirect_enabled, custom_nginx_config)
     VALUES (?, ?, ?, ?, ?)'
);
$insert_log = $pdo->prepare(
    'INSERT INTO domain_logs (domain_id, log_level, message, created_at) VALUES (?, ?, ?, ?)'
);

$log_templates = [
    'INFO' => ['Certificate issued for %s', 'Certificate renewed for %s', 'DNS validation passed for %s'],
    'WARNING' => ['Certificate for %s expires in 14 days', 'DNS record for %s changed'],
    'ERROR' => ['DNS validation failed for %s', 'ACME challenge timeout for %s'],
];

$used_domains = [];

// Провайдеры выбираются отдельным генератором, а не mt_rand(): так остальные данные
// получаются теми же, что и до появления провайдеров
$provider_random = new Random\Randomizer(new Random\Engine\Mt19937(42));

$pdo->beginTransaction();

// 0. providers: небольшой справочник, у одного провайдера много сертификатов
$provider_ids = [];
foreach ([
    ['GiftCard Pro', 'https://giftcard-pro.example.com', '2025-06-01 09:00:00'],
    ['Подарочный Дом', 'https://podarok.example.ru', '2025-08-15 12:30:00'],
    ['CertExpress', null, '2025-10-20 18:45:00'],
    ['Бонус Плюс', 'https://bonusplus.example.ru', '2025-12-01 08:00:00'],
] as $provider) {
    $insert_provider->execute($provider);
    $provider_ids[] = (int) $pdo->lastInsertId();
}

for ($certificate_number = 1; $certificate_number <= $certificate_count; $certificate_number++) {
    // 1. certificates
    $created_at = date('Y-m-d H:i:s', strtotime('2026-01-01') + mt_rand(0, 280 * 86400));
    $insert_certificate->execute([
        $provider_ids[$provider_random->getInt(0, count($provider_ids) - 1)],
        sprintf('CERT-2026-%05d', $certificate_number),
        number_format(pick([1000, 2500, 5000, 10000, 15000]), 2, '.', ''),
        chance(80) ? 'RUB' : pick(['USD', 'EUR']),
        pick(['ACTIVE', 'ACTIVE', 'ACTIVE', 'USED', 'EXPIRED', 'CANCELLED']),
        $created_at,
    ]);
    $certificate_id = (int) $pdo->lastInsertId();

    // 2. certificate_details: получатель и поздравление необязательны
    $has_recipient = chance(75);
    $gender = pick(['male', 'female']);
    $last_name = pick(array_keys($last_names));
    $insert_details->execute([
        $certificate_id,
        "buyer{$certificate_id}@example.com",
        $has_recipient ? pick($first_names[$gender]) . ' ' . ($gender === 'male' ? $last_name : $last_names[$last_name]) : null,
        $has_recipient && chance(85) ? "recipient{$certificate_id}@example.org" : null,
        chance(60) ? pick($greetings) : null,
        chance(70) ? mt_rand(1, 12) : null,
    ]);

    // 3. certificate_delivery_meta: отправлено / ошибка доставки / ещё не отправлено
    $channel = pick(['EMAIL', 'EMAIL', 'SMS', 'PRINT']);
    $outcome = pick(['sent', 'sent', 'sent', 'failed', 'pending']);
    $insert_delivery->execute([
        $certificate_id,
        $channel,
        $outcome === 'sent' ? 1 : 0,
        $outcome === 'sent' ? addSeconds($created_at, mt_rand(60, 3600)) : null,
        $outcome === 'sent' ? sprintf('%s-%08x', strtolower($channel), mt_rand()) : null,
        $outcome === 'failed' ? pick(['SMTP 550: mailbox unavailable', 'SMS gateway timeout', 'Invalid recipient address']) : null,
    ]);

    // 4. domains: от 1 до 5 на сертификат, первый — основной
    $base_domain = pick($words) . $certificate_id . '.' . pick($zones);
    $domain_names = [$base_domain];
    $extra = mt_rand(0, 4);
    for ($index = 0; $index < $extra; $index++) {
        $domain_names[] = pick($subdomains) . '.' . $base_domain;
    }

    foreach (array_unique($domain_names) as $index => $domain_name) {
        if (isset($used_domains[$domain_name])) {
            continue;
        }
        $used_domains[$domain_name] = true;

        $domain_created_at = addSeconds($created_at, mt_rand(0, 86400));
        $insert_domain->execute([$certificate_id, $domain_name, $index === 0 ? 1 : 0, $domain_created_at]);
        $domain_id = (int) $pdo->lastInsertId();

        // 5. domain_configs: ровно одна запись на домен
        $insert_config->execute([
            $domain_id,
            chance(80) ? 1 : 0,
            chance(85) ? pick(['Cloudflare', 'Route53', 'Yandex Cloud DNS', 'Selectel']) : null,
            chance(90) ? 1 : 0,
            chance(20) ? "location /health {\n    return 200;\n}" : null,
        ]);

        // 6. domain_logs: от 0 до 8 записей, у части доменов логов нет
        $log_count = chance(25) ? 0 : mt_rand(1, 8);
        $log_at = $domain_created_at;
        for ($index = 0; $index < $log_count; $index++) {
            $level = pick(['INFO', 'INFO', 'INFO', 'WARNING', 'ERROR']);
            $log_at = addSeconds($log_at, mt_rand(600, 7 * 86400));
            $insert_log->execute([$domain_id, $level, sprintf(pick($log_templates[$level]), $domain_name), $log_at]);
        }
    }
}

$pdo->commit();

foreach (['providers', 'certificates', 'certificate_details', 'certificate_delivery_meta', 'domains', 'domain_configs', 'domain_logs'] as $table) {
    printf("%-27s %d\n", $table, $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn());
}
echo "Database: {$database_path}\n";
