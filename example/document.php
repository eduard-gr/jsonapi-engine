<?php

/**
 * Пример: дерево читателей исполнителя, собранное руками, и документ JSON:API по SQLite базе example/db.
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
use Ggs\JsonApiEngine\Executor\Select\AscendingKeyReader;
use Ggs\JsonApiEngine\Executor\Select\HashNestJoinReader;
use Ggs\JsonApiEngine\Executor\Select\SerializeReader;
use Ggs\JsonApiEngine\Executor\Select\ManualSerializeReader;
use Ggs\JsonApiEngine\Executor\Select\MergeNestJoinReader;
use Ggs\JsonApiEngine\Executor\Select\PreloadReader;
use Ggs\JsonApiEngine\Executor\Select\RegisterReader;
use Ggs\JsonApiEngine\Executor\Select\EntitySharedWindowReader;
use Ggs\JsonApiEngine\Executor\Select\EntitySharedJoinReader;
use Ggs\JsonApiEngine\Executor\Select\EntitySharedAdvanceReader;
use Ggs\JsonApiEngine\Executor\Select\EntityScanReader;
use Ggs\JsonApiEngine\Executor\Select\UniqueAscendingKeyReader;

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/db/connection.php';

$pdo = getPDO();

// Реестр ресурсов этого выполнения: один на всё дерево. Его получают все RegisterReader
// (каждый ресурс существует в одном экземпляре) и Document (по нему собирается included)
$registry = new ResourceRegistry();

$data = new EntitySharedWindowReader(
    source: new EntityScanReader(
        pdo: $pdo,
        sql: QUERY1)
);

// Сертификат, детали и доставка из одной строки QUERY1: каждый ресурс собирает свой SerializeReader по описанию
// (сертификат реализует SerializeInterface — у него ManualSerializeReader)
// ресурса; где в строке лежат его колонки, говорит запись колонок (QUERY1_CERTIFICATE и другие в db/connection.php —
// по плану её даёт EntityScanNode::columns, здесь она написана руками под алиасы запроса)
$certificates = new EntitySharedAdvanceReader(
    data: $data,
    source: new EntitySharedJoinReader(
        outer:new EntitySharedJoinReader(
            outer: new RegisterReader(
                source: new ManualSerializeReader( // Использование $data
                    source: $data,
                    resource: new CertificateResource(),
                    columns: QUERY1_CERTIFICATE,
                ),
                registry: $registry,
            ),
            inner: new RegisterReader(
                source: new SerializeReader( // Тот же самый $data!
                    source: $data,
                    resource: new DetailResource(),
                    columns: QUERY1_DETAIL,
                ),
                registry: $registry,
            ),
            name: 'detail'),
        inner: new RegisterReader(
            source: new SerializeReader( // Тот же самый $data! Без доставки (LEFT JOIN) — null
                source: $data,
                resource: new DeliveryResource(),
                columns: QUERY1_DELIVERY,
            ),
            registry: $registry,
        ),
        name: 'delivery'));


// Справочник провайдеров: PreloadReader вычитывает его целиком, и каждый провайдер
// регистрируется в реестре
$flow4 = new RegisterReader(
    source: new SerializeReader(
        source: new EntityScanReader(
            pdo: $pdo,
            sql: QUERY4),
        resource: new ProviderResource(),
        columns: QUERY4_PROVIDER,
    ),
    registry: $registry,
);

// Сертификаты с провайдером: связь provider уже записана SerializeReader сертификата из provider_id,
// узел только загружает провайдеров в реестр — так они попадут в included (include=provider).
// Провайдеры читаются после последнего сертификата, до обхода связей в Document.
// Провайдер один в реестре, и Document добавит его в included один раз
$flow1 = new PreloadReader(
    source: $certificates,
    preload: $flow4);


// Домены: служебный ключ certificate_id — ключ соединения с сертификатом для HashNestJoinReader
$flow2 = new RegisterReader(
    source: new SerializeReader(
        source: new EntityScanReader(
            pdo: $pdo,
            sql: QUERY2),
        resource: new DomainResource(),
        columns: QUERY2_DOMAIN,
    ),
    registry: $registry,
);


// Логи: служебный ключ domain_id — ключ соединения с доменом для MergeNestJoinReader
$flow3 = new RegisterReader(
    source: new SerializeReader(
        source: new EntityScanReader(
            pdo: $pdo,
            sql: QUERY3),
        resource: new LogResource(),
        columns: QUERY3_LOG,
    ),
    registry: $registry,
);


// Домены с логами: доменам записывается связь logs, наружу идут домены. Логи попадут в included,
// потому что Document дойдёт до них по связям сертификат → домен → лог.
// MergeNestJoinReader: QUERY2 и QUERY3 отсортированы по domain id. Порядок доменов по id
// не подходит для соединения с сертификатами по certificate_id, поэтому внешнее
// соединение остаётся HashNestJoinReader — ему порядок не важен.
// Порядок ключей слиянию проверяют узлы на входах: домены — строго по id (UniqueAscendingKeyReader), логи —
// по domain_id с повторами (AscendingKeyReader)
$domains = new MergeNestJoinReader(
    outer: new UniqueAscendingKeyReader(
        source: $flow2,
        key: 'id',
    ),
    inner: new AscendingKeyReader(
        source: $flow3,
        key: 'domain_id',
    ),
    outer_key: 'id',
    inner_key: 'domain_id',
    name: 'logs');


$flow = new HashNestJoinReader(
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
