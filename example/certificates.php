<?php

/**
 * GET /certificates через `CertificatesController`: запрос JSON:API → план select → документ JSON:API по SQLite
 * базе example/db.
 *
 * Без аргумента — запрос со связями дерева из `example/document.php` (документ совпадает с
 * test/Fixture/document.json). По умолчанию печатает дерево плана и SQL, с --document — только документ.
 *
 *     php example/certificates.php
 *     php example/certificates.php '/certificates?include=domains.logs&fields[certificate]=code'
 *     php example/certificates.php '/certificates?include=delivery&filter[status]=ACTIVE' --document
 *
 * Ошибка запроса печатается как ответ 400/404.
 */

declare(strict_types=1);

use Ggs\JsonApiEngine\Example\Controller\CertificatesController;
use Ggs\JsonApiEngine\Parser\InvalidQueryException;
use Ggs\JsonApiEngine\Parser\ResourceNotFoundException;
use Ggs\JsonApiEngine\Plan\Select\SelectPlanPrinter;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/db/connection.php';

$arguments = array_slice($argv, 1);
$document = in_array('--document', $arguments, true);
$arguments = array_values(array_diff($arguments, ['--document']));

$url = $arguments[0] ?? '/certificates?include=provider,detail,delivery,domains.logs';
parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

// Роутинг — дело вызывающего кода: путь выбирает контроллер
$path = (string) parse_url($url, PHP_URL_PATH);
if ($path !== '/certificates') {
    fwrite(STDERR, "404 Not Found: no endpoint for {$path}\n");
    exit(1);
}

$controller = new CertificatesController(pdo: getPDO());
try {
    if ($document) {
        echo json_encode($controller->get(query: $query), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
        exit(0);
    }
    $plan = $controller->plan(query: $query);
} catch (InvalidQueryException $exception) {
    fwrite(STDERR, "400 Bad Request: {$exception->getMessage()}\n");
    exit(1);
} catch (ResourceNotFoundException $exception) {
    fwrite(STDERR, "404 Not Found: {$exception->getMessage()}\n");
    exit(1);
}

echo "GET {$url}\n\n";
echo SelectPlanPrinter::tree(node: $plan), "\n";
echo SelectPlanPrinter::queries(node: $plan);
