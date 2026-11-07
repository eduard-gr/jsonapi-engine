<?php

declare(strict_types=1);

/**
 * Создаёт SQLite базу по example/db/schema.sql и наполняет её тестовыми данными.
 *
 * Запуск: php example/db/seed.php [количество сертификатов] [путь к базе]
 * По умолчанию 20 сертификатов в example/db/database.sqlite. Существующая база пересоздаётся.
 * Генератор случайных чисел инициализирован константой, поэтому данные одинаковы при каждом запуске.
 */

$certificateCount = (int) ($argv[1] ?? 20);
$dbPath = $argv[2] ?? __DIR__ . '/database.sqlite';

if (file_exists($dbPath)) {
    unlink($dbPath);
}

$pdo = new PDO('sqlite:' . $dbPath, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
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
$lastNames = ['Иванов' => 'Иванова', 'Петров' => 'Петрова', 'Смирнов' => 'Смирнова', 'Кузнецов' => 'Кузнецова', 'Соколов' => 'Соколова'];
$firstNames = [
    'male' => ['Иван', 'Пётр', 'Алексей', 'Дмитрий'],
    'female' => ['Анна', 'Мария', 'Елена', 'Ольга'],
];
$greetings = ['С днём рождения!', 'Поздравляем с праздником!', 'Спасибо за вашу работу!', 'С Новым годом!'];
$zones = ['ru', 'com', 'net', 'org', 'io'];
$words = ['alpha', 'shop', 'cloud', 'store', 'media', 'tech', 'nova', 'pixel', 'data', 'green'];
$subdomains = ['www', 'api', 'shop', 'mail', 'cdn', 'admin'];

$insertProvider = $pdo->prepare(
    'INSERT INTO providers (name, website, created_at) VALUES (?, ?, ?)'
);
$insertCertificate = $pdo->prepare(
    'INSERT INTO certificates (provider_id, code, nominal, currency, status, created_at) VALUES (?, ?, ?, ?, ?, ?)'
);
$insertDetails = $pdo->prepare(
    'INSERT INTO certificate_details (certificate_id, buyer_email, recipient_name, recipient_email, greeting_text, design_template_id)
     VALUES (?, ?, ?, ?, ?, ?)'
);
$insertDelivery = $pdo->prepare(
    'INSERT INTO certificate_delivery_meta (certificate_id, delivery_channel, is_sent, sent_at, external_provider_id, delivery_error_log)
     VALUES (?, ?, ?, ?, ?, ?)'
);
$insertDomain = $pdo->prepare(
    'INSERT INTO domains (certificate_id, domain_name, is_primary, created_at) VALUES (?, ?, ?, ?)'
);
$insertConfig = $pdo->prepare(
    'INSERT INTO domain_configs (domain_id, auto_renew, dns_provider, ssl_redirect_enabled, custom_nginx_config)
     VALUES (?, ?, ?, ?, ?)'
);
$insertLog = $pdo->prepare(
    'INSERT INTO domain_logs (domain_id, log_level, message, created_at) VALUES (?, ?, ?, ?)'
);

$logTemplates = [
    'INFO' => ['Certificate issued for %s', 'Certificate renewed for %s', 'DNS validation passed for %s'],
    'WARNING' => ['Certificate for %s expires in 14 days', 'DNS record for %s changed'],
    'ERROR' => ['DNS validation failed for %s', 'ACME challenge timeout for %s'],
];

$usedDomains = [];

// Провайдеры выбираются отдельным генератором, а не mt_rand(): так остальные данные
// получаются теми же, что и до появления провайдеров
$providerRandom = new Random\Randomizer(new Random\Engine\Mt19937(42));

$pdo->beginTransaction();

// 0. providers: небольшой справочник, у одного провайдера много сертификатов
$providerIds = [];
foreach ([
    ['GiftCard Pro', 'https://giftcard-pro.example.com', '2025-06-01 09:00:00'],
    ['Подарочный Дом', 'https://podarok.example.ru', '2025-08-15 12:30:00'],
    ['CertExpress', null, '2025-10-20 18:45:00'],
    ['Бонус Плюс', 'https://bonusplus.example.ru', '2025-12-01 08:00:00'],
] as $provider) {
    $insertProvider->execute($provider);
    $providerIds[] = (int) $pdo->lastInsertId();
}

for ($n = 1; $n <= $certificateCount; $n++) {
    // 1. certificates
    $createdAt = date('Y-m-d H:i:s', strtotime('2026-01-01') + mt_rand(0, 280 * 86400));
    $insertCertificate->execute([
        $providerIds[$providerRandom->getInt(0, count($providerIds) - 1)],
        sprintf('CERT-2026-%05d', $n),
        number_format(pick([1000, 2500, 5000, 10000, 15000]), 2, '.', ''),
        chance(80) ? 'RUB' : pick(['USD', 'EUR']),
        pick(['ACTIVE', 'ACTIVE', 'ACTIVE', 'USED', 'EXPIRED', 'CANCELLED']),
        $createdAt,
    ]);
    $certificateId = (int) $pdo->lastInsertId();

    // 2. certificate_details: получатель и поздравление необязательны
    $hasRecipient = chance(75);
    $gender = pick(['male', 'female']);
    $lastName = pick(array_keys($lastNames));
    $insertDetails->execute([
        $certificateId,
        "buyer{$certificateId}@example.com",
        $hasRecipient ? pick($firstNames[$gender]) . ' ' . ($gender === 'male' ? $lastName : $lastNames[$lastName]) : null,
        $hasRecipient && chance(85) ? "recipient{$certificateId}@example.org" : null,
        chance(60) ? pick($greetings) : null,
        chance(70) ? mt_rand(1, 12) : null,
    ]);

    // 3. certificate_delivery_meta: отправлено / ошибка доставки / ещё не отправлено
    $channel = pick(['EMAIL', 'EMAIL', 'SMS', 'PRINT']);
    $outcome = pick(['sent', 'sent', 'sent', 'failed', 'pending']);
    $insertDelivery->execute([
        $certificateId,
        $channel,
        $outcome === 'sent' ? 1 : 0,
        $outcome === 'sent' ? addSeconds($createdAt, mt_rand(60, 3600)) : null,
        $outcome === 'sent' ? sprintf('%s-%08x', strtolower($channel), mt_rand()) : null,
        $outcome === 'failed' ? pick(['SMTP 550: mailbox unavailable', 'SMS gateway timeout', 'Invalid recipient address']) : null,
    ]);

    // 4. domains: от 1 до 5 на сертификат, первый — основной
    $baseDomain = pick($words) . $certificateId . '.' . pick($zones);
    $domainNames = [$baseDomain];
    $extra = mt_rand(0, 4);
    for ($i = 0; $i < $extra; $i++) {
        $domainNames[] = pick($subdomains) . '.' . $baseDomain;
    }

    foreach (array_unique($domainNames) as $index => $domainName) {
        if (isset($usedDomains[$domainName])) {
            continue;
        }
        $usedDomains[$domainName] = true;

        $domainCreatedAt = addSeconds($createdAt, mt_rand(0, 86400));
        $insertDomain->execute([$certificateId, $domainName, $index === 0 ? 1 : 0, $domainCreatedAt]);
        $domainId = (int) $pdo->lastInsertId();

        // 5. domain_configs: ровно одна запись на домен
        $insertConfig->execute([
            $domainId,
            chance(80) ? 1 : 0,
            chance(85) ? pick(['Cloudflare', 'Route53', 'Yandex Cloud DNS', 'Selectel']) : null,
            chance(90) ? 1 : 0,
            chance(20) ? "location /health {\n    return 200;\n}" : null,
        ]);

        // 6. domain_logs: от 0 до 8 записей, у части доменов логов нет
        $logCount = chance(25) ? 0 : mt_rand(1, 8);
        $logAt = $domainCreatedAt;
        for ($i = 0; $i < $logCount; $i++) {
            $level = pick(['INFO', 'INFO', 'INFO', 'WARNING', 'ERROR']);
            $logAt = addSeconds($logAt, mt_rand(600, 7 * 86400));
            $insertLog->execute([$domainId, $level, sprintf(pick($logTemplates[$level]), $domainName), $logAt]);
        }
    }
}

$pdo->commit();

foreach (['providers', 'certificates', 'certificate_details', 'certificate_delivery_meta', 'domains', 'domain_configs', 'domain_logs'] as $table) {
    printf("%-27s %d\n", $table, $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn());
}
echo "Database: {$dbPath}\n";
