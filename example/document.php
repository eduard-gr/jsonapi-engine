<?php

/**
 * Пример: дерево итераторов исполнителя, собранное руками, и документ JSON:API по SQLite базе example/db.
 *
 *     php example/document.php
 *     php example/db/seed.php [количество сертификатов]   # пересоздать базу
 */

declare(strict_types=1);

use Ggs\JsonApiEngine\Document\Document;
use Ggs\JsonApiEngine\Document\ResourceRegistry;
use Ggs\JsonApiEngine\Example\Resource\CertificateResource;
use Ggs\JsonApiEngine\Example\Resource\DeliveryResource;
use Ggs\JsonApiEngine\Example\Resource\DetailResource;
use Ggs\JsonApiEngine\Example\Resource\DomainResource;
use Ggs\JsonApiEngine\Example\Resource\LogResource;
use Ggs\JsonApiEngine\Example\Resource\ProviderResource;
use Ggs\JsonApiEngine\Executor\HashNestJoinIterator;
use Ggs\JsonApiEngine\Executor\MergeNestJoinIterator;
use Ggs\JsonApiEngine\Executor\PreloadIterator;
use Ggs\JsonApiEngine\Executor\SerializeIterator;
use Ggs\JsonApiEngine\Executor\SharedDataIterator;
use Ggs\JsonApiEngine\Executor\SharedRowJoinIterator;
use Ggs\JsonApiEngine\Executor\SharedScanIterator;
use Ggs\JsonApiEngine\Executor\SqlScanIterator;

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/db/connection.php';

$pdo = getPDO();

// Реестр ресурсов этого выполнения: один на всё дерево. Его получают все SerializeIterator
// (каждый ресурс существует в одном экземпляре) и Document (по нему собирается included)
$registry = new ResourceRegistry();

$data = new SharedDataIterator(
    source: new SqlScanIterator(
        pdo: $pdo,
        sql: QUERY1)
);

// Сертификат, детали и доставка из одной строки QUERY1: каждый ресурс собирает свой serialize()
$certificates = new SharedScanIterator(
    data: $data,
    source: new SharedRowJoinIterator(
        outer:new SharedRowJoinIterator(
            outer: new SerializeIterator( // Использование $data
                source: $data,
                resource: CertificateResource::class,
                registry: $registry),
            inner: new SerializeIterator( // Тот же самый $data!
                source: $data,
                resource: DetailResource::class,
                registry: $registry),
            name: 'detail'),
        inner: new SerializeIterator( // Тот же самый $data! Без доставки (LEFT JOIN) — null
            source: $data,
            resource: DeliveryResource::class,
            registry: $registry),
        name: 'delivery'));


// Справочник провайдеров: PreloadIterator вычитывает его целиком, и каждый провайдер
// регистрируется в реестре
$flow4 = new SerializeIterator(
    source: new SqlScanIterator(
        pdo: $pdo,
        sql: QUERY4),
    resource: ProviderResource::class,
    registry: $registry);

// Сертификаты с провайдером: связь provider уже записана CertificateResource::serialize() из provider_id,
// узел только загружает провайдеров в реестр — так они попадут в included (include=provider).
// Провайдеры читаются после последнего сертификата, до обхода связей в Document.
// Провайдер один в реестре, и Document добавит его в included один раз
$flow1 = new PreloadIterator(
    source: $certificates,
    preload: $flow4);


// Домены: служебный ключ certificate_id — ключ соединения с сертификатом для HashNestJoinIterator
$flow2 = new SerializeIterator(
    source: new SqlScanIterator(
        pdo: $pdo,
        sql: QUERY2),
    resource: DomainResource::class,
    registry: $registry);


// Логи: служебный ключ domain_id — ключ соединения с доменом для MergeNestJoinIterator
$flow3 = new SerializeIterator(
    source: new SqlScanIterator(
        pdo: $pdo,
        sql: QUERY3),
    resource: LogResource::class,
    registry: $registry);


// Домены с логами: доменам записывается связь logs, наружу идут домены. Логи попадут в included,
// потому что Document дойдёт до них по связям сертификат → домен → лог.
// MergeNestJoinIterator: QUERY2 и QUERY3 отсортированы по domain id. Порядок доменов по id
// не подходит для соединения с сертификатами по certificate_id, поэтому внешнее
// соединение остаётся HashNestJoinIterator — ему порядок не важен
$domains = new MergeNestJoinIterator(
    outer: $flow2,
    inner: $flow3,
    outer_key: 'id',
    inner_key: 'domain_id',
    name: 'logs');


$flow = new HashNestJoinIterator(
    outer: $flow1,
    inner: $domains,
    outer_key: 'id',
    inner_key: 'certificate_id',
    name: 'domains');

// Документ читает поток ресурсов от корня дерева при сериализации: они уходят в data,
// included собирается обходом связей по реестру. Само дерево выполняется внутри json_encode()
$document = new Document($flow, $registry);
echo json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

//echo json_encode($flow->explain(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
