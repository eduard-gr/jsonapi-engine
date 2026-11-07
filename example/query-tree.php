<?php

/**
 * Шаг 1 выполнения запроса: GET-запрос JSON:API → дерево запроса (`QueryParser`) по корневому ресурсу `CertificateResource`.
 *
 * Без аргумента разбирается запрос со связями дерева из `example/document.php`: сертификаты с провайдером, деталями
 * и доставкой, доменами и логами доменов.
 *
 *     php example/query-tree.php
 *     php example/query-tree.php '/certificates?include=domains.logs&filter[status]=ACTIVE&sort=-created_at'
 *
 * Ошибка запроса печатается как ответ 400/404.
 */

declare(strict_types=1);

use Ggs\JsonApiEngine\Example\Resource\CertificateResource;
use Ggs\JsonApiEngine\Parser\InvalidQueryException;
use Ggs\JsonApiEngine\Parser\QueryParser;
use Ggs\JsonApiEngine\Parser\ResourceNotFoundException;
use Ggs\JsonApiEngine\Resource;

require __DIR__ . '/../vendor/autoload.php';

/** запрос дерева из example/document.php */
const DOCUMENT_QUERY = '/certificates?include=provider,detail,delivery,domains.logs';

/**
 * Роутинг — дело вызывающего кода, не библиотеки: путь выбирает корневой ресурс endpoint'а.
 *
 * @return class-string<Resource>
 */
function route(string $path): string
{
    return match ($path) {
        '/certificates' => CertificateResource::class,
        default => throw new InvalidArgumentException("No endpoint for {$path}"),
    };
}

/**
 * Дерево как PHP-массив: классы — `Short::class`, вид связи — `QueryParser::ONE_TO_*`, короткие списки в строку.
 */
function export(mixed $value, string $indent = ''): string
{
    if (is_array($value)) {
        if ($value === []) {
            return '[]';
        }
        $list = array_is_list($value);
        if ($list && array_filter($value, 'is_array') === []) {
            return '[' . implode(', ', array_map(static fn (mixed $item): string => export($item), $value)) . ']';
        }
        $inner = $indent . '    ';
        $lines = [];
        foreach ($value as $key => $item) {
            $lines[] = $inner . ($list ? '' : var_export($key, true) . ' => ') . export($item, $inner) . ',';
        }

        return "[\n" . implode("\n", $lines) . "\n{$indent}]";
    }
    if (is_string($value) && class_exists($value)) {
        return substr($value, strrpos($value, '\\') + 1) . '::class';
    }

    return match ($value) {
        QueryParser::ONE_TO_ONE => 'QueryParser::ONE_TO_ONE',
        QueryParser::MANY_TO_ONE => 'QueryParser::MANY_TO_ONE',
        QueryParser::ONE_TO_MANY => 'QueryParser::ONE_TO_MANY',
        default => var_export($value, true),
    };
}

$url = $argv[1] ?? DOCUMENT_QUERY;
parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

echo "GET {$url}\n\n";
try {
    $tree = (new QueryParser())->parse(route((string) parse_url($url, PHP_URL_PATH)), $query);
} catch (InvalidQueryException $e) {
    echo "400 Bad Request: {$e->getMessage()}\n";
    exit(1);
} catch (ResourceNotFoundException $e) {
    echo "404 Not Found: {$e->getMessage()}\n";
    exit(1);
}

echo export($tree), "\n";
