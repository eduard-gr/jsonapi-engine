<?php

/**
 * Запись (POST, PATCH и DELETE) ресурса со связями на девяти сценариях: каждый печатает документ, дерево операций,
 * план, дерево исполнителей, ключи корня и состояние базы до и после; SQL каждого запроса — в stderr. Весь конвейер —
 * в `src/`, здесь только сценарии и печать деревьев:
 *
 *     документ → `Parser\Write\WriteDocumentParser` (`insert()`/`update()`/`delete()`) → дерево операций
 *     (`WriteOperation`) → `Plan\Write\WritePlanner` → план (неизменяемые узлы `…Node`) →
 *     `Executor\Write\WriterBuilder` → дерево исполнителей (`…Writer`) → `WriteRootWriter::execute()`
 *
 *     php example/patch-document.php            # всё в stdout, SQL — в stderr
 *     php example/patch-document.php 2>sql.log  # SQL отдельно
 *
 * Формат документа, правила `meta.op`, проверки и ограничения описаны в докблоках классов (`WriteDocumentParser`,
 * `RelationshipOperation`, `WritePlanner`, исполнители). Пошаговый разбор обновления на сценарии 1 (как строится и
 * меняется дерево, как по нему идут данные, с диаграммами) — example/patch-document.md.
 *
 * Сценарии, каждый в своей транзакции, которая откатывается:
 *  1. `PATCH /certificates/1` над `CertificateWithDetailResource` (обе таблицы модели, NULL в колонку деталей, замена
 *     to-one `delivery` через `lid`, частичное изменение `domains`);
 *  2. PATCH сертификата без строки деталей (вставлен в транзакции): сначала только NULL — строка не вставляется, потом
 *     значения — вставляется;
 *  3. `POST /certificates` с деталями, доставкой и доменами: ответ `201 Created` + `Location` + `{data: {type, id}}`;
 *  4. `POST /certificates/1/domains` со списком из двух доменов: вложенный маршрут, `RouteParentNode` в плане;
 *  5. тот же план с несуществующим родителем `POST /certificates/999/domains` — 404;
 *  6. `DELETE /certificates/1`: строки до и после, зависимое удалил каскад базы; ответ `204 No Content`;
 *  7. тот же план для `DELETE /certificates/999` — 404;
 *  8. `PATCH /certificates/1` с полной заменой списка `domains` (`WriteReplaceNode` под `WriteJoinNode`);
 *  9. замена пустым — `delivery: null`, `domains: []` (только `WriteReplaceNode`, узлов связи нет).
 *
 * Ответ на успешный PATCH — `204 No Content` без тела; ключи корня печатаются только для наглядности.
 */

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Example\PatchPrototype;

use Ggs\JsonApiEngine\Example\Resource\CertificateResource;
use Ggs\JsonApiEngine\Example\Resource\CertificateWithDetailResource;
use Ggs\JsonApiEngine\Example\Resource\DomainResource;
use Ggs\JsonApiEngine\Executor\Write\WriteNotFoundException;
use Ggs\JsonApiEngine\Executor\Write\WriterBuilder;
use Ggs\JsonApiEngine\Parser\InvalidDocumentException;
use Ggs\JsonApiEngine\Parser\Write\DeleteOperation;
use Ggs\JsonApiEngine\Parser\Write\InsertOperation;
use Ggs\JsonApiEngine\Parser\Write\KeepOperation;
use Ggs\JsonApiEngine\Parser\Write\UpdateOperation;
use Ggs\JsonApiEngine\Parser\Write\WriteDocumentParser;
use Ggs\JsonApiEngine\Parser\Write\WriteOperation;
use Ggs\JsonApiEngine\Plan\Write\WritePlanner;
use LogicException;
use PDO;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/db/connection.php';

// ---------------------------------------------------------------------------------------------------------------
// Печать: дерево операций, план и дерево исполнителей текстом

function json(mixed $value): string
{
    return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * Имя класса без пространства имён.
 */
function shortClassName(string $class): string
{
    return substr((string) strrchr('\\' . $class, '\\'), 1);
}

/**
 * Дерево операций текстом: класс операции, ресурс, id или lid, присланные атрибуты; под ним связи по внешнему ключу
 * (если есть) и связи по владению с признаком замены и операциями над детьми.
 */
function operations(WriteOperation $operation, string $indent = ''): string
{
    $output = $indent . shortClassName($operation::class) . ' ' . shortClassName($operation->resource);
    if ($operation instanceof KeepOperation || $operation instanceof DeleteOperation) {
        return "{$output} {$operation->identity->key()}\n";
    }
    if (!$operation instanceof InsertOperation && !$operation instanceof UpdateOperation) {
        throw new LogicException('Unknown operation ' . $operation::class);
    }
    $output .= ' ' . ($operation->identity === null ? 'new' : $operation->identity->key());
    $output .= $operation->attributes === [] ? '' : ' ' . json_encode($operation->attributes, JSON_UNESCAPED_UNICODE);
    $output .= "\n";
    $references = [];
    foreach ($operation->references as $name => $target) {
        $references[] = "{$name} → " . ($target === null ? 'null' : $target->key());
    }
    if ($references !== []) {
        $output .= "{$indent}    references: " . implode(', ', $references) . "\n";
    }
    foreach ($operation->relationships as $name => $relationship) {
        $replace = $relationship->replace ? 'true' : 'false';
        $output .= "{$indent}    RelationshipOperation {$name}, replace: {$replace}\n";
        foreach ($relationship->children as $child) {
            $output .= operations(operation: $child, indent: "{$indent}        ");
        }
    }

    return $output;
}

/**
 * Дерево исполнителей текстом из explain(): короткое имя узла, его скалярные и списочные свойства, входы с отступом
 * под своим именем (`parent`, `child`, `add`, `input`…).
 */
function executionTree(array $explain, string $indent = '', string $role = ''): string
{
    $name = shortClassName($explain['node']);
    $properties = [];
    $inputs = [];
    foreach ($explain as $key => $value) {
        if ($key === 'node') {
            continue;
        }
        if (is_array($value) && isset($value['node'])) {
            $inputs[$key] = $value;
        } else {
            $properties[] = "{$key}: " . (is_string($value) && str_contains($value, '\\') ? shortClassName($value) : json_encode($value, JSON_UNESCAPED_SLASHES));
        }
    }
    $output = $indent . ($role === '' ? '' : "{$role}: ") . $name . ($properties === [] ? '' : ' (' . implode(', ', $properties) . ')') . "\n";
    foreach ($inputs as $key => $input) {
        $output .= executionTree($input, "{$indent}    ", (string) $key);
    }

    return $output;
}

/**
 * План текстом из публичных свойств узлов: короткое имя узла, его свойства, входы (свойства-узлы) с отступом под
 * своим именем; пустые ветви (`null`) не печатаются.
 */
function planTree(object $node, string $indent = '', string $role = ''): string
{
    $properties = [];
    $inputs = [];
    foreach (get_object_vars($node) as $key => $value) {
        if ($value === null) {
            continue;
        }
        if (is_object($value) && str_ends_with($value::class, 'Node')) {
            $inputs[$key] = $value;
        } elseif (is_object($value)) {
            $properties[] = "{$key}: " . shortClassName($value::class);
        } else {
            $properties[] = "{$key}: " . (is_string($value) && str_contains($value, '\\') ? shortClassName($value) : json_encode($value, JSON_UNESCAPED_SLASHES));
        }
    }
    $output = $indent . ($role === '' ? '' : "{$role}: ") . shortClassName($node::class) . ($properties === [] ? '' : ' (' . implode(', ', $properties) . ')') . "\n";
    foreach ($inputs as $key => $input) {
        $output .= planTree($input, "{$indent}    ", (string) $key);
    }

    return $output;
}

/**
 * Строки сертификата 1 и его детей: состояние до и после сценария.
 *
 * @return array<string, mixed>
 */
function certificateState(PDO $pdo): array
{
    return [
        'certificate' => $pdo->query('select c.nominal, d.buyer_email, d.recipient_name from certificates c left join certificate_details d on d.certificate_id = c.id where c.id = 1')->fetch(PDO::FETCH_ASSOC),
        'delivery' => $pdo->query('select certificate_id, delivery_channel, is_sent, external_provider_id from certificate_delivery_meta where certificate_id = 1')->fetch(PDO::FETCH_ASSOC),
        'domains' => $pdo->query('select id, domain_name, is_primary from domains where certificate_id = 1 order by id')->fetchAll(PDO::FETCH_ASSOC),
    ];
}

/**
 * Два документа PATCH для сертификата без строки деталей: только NULL, затем значения.
 *
 * @return list<array>
 */
function detailDocuments(string $id): array
{
    return [
        ['data' => ['type' => 'certificate', 'id' => $id, 'attributes' => ['recipient_name' => null]]],
        ['data' => ['type' => 'certificate', 'id' => $id, 'attributes' => ['buyer_email' => 'first-buyer@example.com', 'greeting_text' => 'Hello']]],
    ];
}

/**
 * @return array<string, mixed>|false строка деталей сертификата, false — строки нет
 */
function detailState(PDO $pdo, string $id): array|false
{
    return $pdo->query("select * from certificate_details where certificate_id = {$id}")->fetch(PDO::FETCH_ASSOC);
}

/**
 * Число строк сертификата 1 и его потомков по таблицам: что удалил каскад.
 *
 * @param string $domain_ids id доменов сертификата через запятую (после удаления их уже не найти)
 * @return array<string, int>
 */
function cascadeState(PDO $pdo, string $domain_ids): array
{
    return [
        'certificates' => (int) $pdo->query('select count(*) from certificates where id = 1')->fetchColumn(),
        'certificate_details' => (int) $pdo->query('select count(*) from certificate_details where certificate_id = 1')->fetchColumn(),
        'certificate_delivery_meta' => (int) $pdo->query('select count(*) from certificate_delivery_meta where certificate_id = 1')->fetchColumn(),
        'domains' => (int) $pdo->query('select count(*) from domains where certificate_id = 1')->fetchColumn(),
        'domain_logs' => (int) $pdo->query("select count(*) from domain_logs where domain_id in ({$domain_ids})")->fetchColumn(),
        'domain_configs' => (int) $pdo->query("select count(*) from domain_configs where domain_id in ({$domain_ids})")->fetchColumn(),
    ];
}

$pdo = getPDO();
// FOR KEY SHARE в проверках существования: в PostgreSQL true; SQLite примера такой конструкции не знает
$lock = false;

// ---------------------------------------------------------------------------------------------------------------
// 1. PATCH /certificates/1 над CertificateWithDetailResource (certificates + certificate_details): номинал
//    (certificates), buyer_email и recipient_name: null (certificate_details: NULL в колонку); to-one delivery —
//    замена через lid (текущая доставка удаляется, новая вставляется); связь по владению domains: домен 3 —
//    основной, домен 2 удалить, новый домен; домен 1 не назван — остаётся (частичное изменение)

$certificate_document = [
    'data' => [
        'type' => 'certificate',
        'id' => '1',
        'attributes' => ['nominal' => '2500.00', 'buyer_email' => 'new-buyer@example.com', 'recipient_name' => null],
        'relationships' => [
            'delivery' => ['data' => ['type' => 'delivery', 'lid' => 'del1']],
            'domains' => ['data' => [
                ['type' => 'domain', 'id' => '3'],
                ['type' => 'domain', 'id' => '2', 'meta' => ['op' => 'remove']],
                ['type' => 'domain', 'lid' => 'd1'],
            ]],
        ],
    ],
    'included' => [
        ['type' => 'domain', 'id' => '3', 'attributes' => ['is_primary' => true]],
        ['type' => 'domain', 'lid' => 'd1', 'attributes' => ['domain_name' => 'new.green1.net', 'is_primary' => false]],
        ['type' => 'delivery', 'lid' => 'del1', 'attributes' => ['delivery_channel' => 'SMS', 'is_sent' => false]],
    ],
];
echo "######## 1. PATCH /certificates/1\n", json($certificate_document), "\n\n";


$pdo->beginTransaction();
try {
    $certificate_root = new WriteDocumentParser()->update(document: $certificate_document, resource: CertificateWithDetailResource::class, id: '1');
    echo "######## operation tree\n", operations(operation: $certificate_root), "\n";
    $certificate_plan = new WritePlanner()->plan(resource: CertificateWithDetailResource::class, roots: [$certificate_root]);
    echo "######## plan\n", planTree($certificate_plan), "\n";
    $certificate_writer = new WriterBuilder(pdo: $pdo, lock: $lock)->root(plan: $certificate_plan, data: [$certificate_root]);
    echo "######## execution tree\n", executionTree($certificate_writer->explain()), "\n";
    echo "######## before\n", json(certificateState(pdo: $pdo)), "\n\n";
    echo "######## keys of the root\n", json($certificate_writer->execute()[0]), "\n\n";
    echo "######## after (before rollback)\n", json(certificateState(pdo: $pdo)), "\n\n";
} catch (InvalidDocumentException|WriteNotFoundException $exception) {
    echo "######## response\n", json(['errors' => [$exception->toError()]]), "\n\n";
} finally {
    $pdo->rollBack();
}

// ---------------------------------------------------------------------------------------------------------------
// 2. PATCH сертификата без строки certificate_details (вставлен в транзакции), два запроса подряд:
//    recipient_name: null — строки нет, все присланные значения NULL: вставлять нечего, только SELECT;
//    buyer_email + greeting_text — строки нет: UPDATE ничего не нашёл, строка вставляется


$pdo->beginTransaction();
try {
    $pdo->exec("insert into certificates (provider_id, code, nominal) values (1, 'NO-DETAIL', '1.00')");
    $detail_id = (string) $pdo->lastInsertId();
    echo "######## 2. PATCH /certificates/{$detail_id} (no certificate_details row)\n\n";
    foreach (detailDocuments(id: $detail_id) as $detail_document) {
        echo "######## document\n", json($detail_document), "\n";
        $detail_root = new WriteDocumentParser()->update(document: $detail_document, resource: CertificateWithDetailResource::class, id: $detail_id);
        $detail_plan = new WritePlanner()->plan(resource: CertificateWithDetailResource::class, roots: [$detail_root]);
        echo "######## plan\n", planTree($detail_plan);
        $detail_writer = new WriterBuilder(pdo: $pdo, lock: $lock)->root(plan: $detail_plan, data: [$detail_root]);
        echo "######## keys of the root\n", json($detail_writer->execute()[0]), "\n";
        echo "######## certificate_details after\n", json(detailState(pdo: $pdo, id: $detail_id)), "\n\n";
    }
} catch (InvalidDocumentException|WriteNotFoundException $exception) {
    echo "######## response\n", json(['errors' => [$exception->toError()]]), "\n\n";
} finally {
    $pdo->rollBack();
}

// ---------------------------------------------------------------------------------------------------------------
// 3. POST /certificates тем же парсером, планировщиком и исполнителями: корень новый (id нет, lid не обязателен),
//    у потомков только lid. Документ — как в example/insert-plan.php.
//    Ответ — 201 Created + Location + {data: {type, id}}

$post_document = [
    'data' => [
        'type' => 'certificate',
        'attributes' => [
            'code' => 'PLAN-1',
            'nominal' => '10.00',
            'buyer_email' => 'buyer@example.com',
            'recipient_name' => 'Anna',
        ],
        'relationships' => [
            'provider' => ['data' => ['type' => 'provider', 'id' => '1']],
            'delivery' => ['data' => ['type' => 'delivery', 'lid' => 'del1']],
            'domains' => ['data' => [['type' => 'domain', 'lid' => 'd1'], ['type' => 'domain', 'lid' => 'd2']]],
        ],
    ],
    'included' => [
        ['type' => 'delivery', 'lid' => 'del1', 'attributes' => ['delivery_channel' => 'EMAIL']],
        ['type' => 'domain', 'lid' => 'd1', 'attributes' => ['domain_name' => 'plan1.example.com']],
        ['type' => 'domain', 'lid' => 'd2', 'attributes' => ['domain_name' => 'plan2.example.com']],
    ],
];
echo "\n######## 3. POST /certificates\n", json($post_document), "\n\n";

$pdo->beginTransaction();
try {
    $post_roots = new WriteDocumentParser()->insert(document: $post_document, resource: CertificateWithDetailResource::class);
    echo "######## operation tree\n";
    echo implode('', array_map(static fn(WriteOperation $root): string => operations(operation: $root), $post_roots)), "\n";
    $post_plan = new WritePlanner()->plan(resource: CertificateWithDetailResource::class, roots: $post_roots);
    echo "######## plan\n", planTree($post_plan), "\n";
    $post_writer = new WriterBuilder(pdo: $pdo, lock: $lock)->root(plan: $post_plan, data: $post_roots);
    $post_keys = $post_writer->execute();
    $post_id = (string) $post_keys[0][CertificateWithDetailResource::primaryColumn()];
    echo "######## response\n201 Created\nLocation: /certificates/{$post_id}\n", json(['data' => ['type' => CertificateWithDetailResource::type(), 'id' => $post_id]]), "\n";
} catch (InvalidDocumentException|WriteNotFoundException $exception) {
    echo "######## response\n", json(['errors' => [$exception->toError()]]), "\n";
} finally {
    $pdo->rollBack();
}

// ---------------------------------------------------------------------------------------------------------------
// 4. POST /certificates/1/domains — несколько ресурсов одним запросом через вложенный маршрут: data — список, каждый
//    элемент — корень. Маршрут вложенный — в плане RouteParentNode (родитель и колонки связи, без id); id = 1 получает
//    сборщик: RouteParentWriter один раз проверяет, что сертификат есть, и даёт корням certificate_id = 1. Документ —
//    из example/insert-route.php. Ответ — 201 без Location, data — идентификаторы в порядке запроса

$route_document = [
    'data' => [
        ['type' => 'domain', 'lid' => 'd1', 'attributes' => ['domain_name' => 'api.example.org', 'is_primary' => true]],
        ['type' => 'domain', 'lid' => 'd2', 'attributes' => ['domain_name' => 'cdn.example.org', 'is_primary' => false]],
    ],
];
echo "\n######## 4. POST /certificates/1/domains\n", json($route_document), "\n\n";

$pdo->beginTransaction();
try {
    $route_roots = new WriteDocumentParser()->insert(document: $route_document, resource: DomainResource::class);
    echo "######## operation tree\n";
    echo implode('', array_map(static fn(WriteOperation $root): string => operations(operation: $root), $route_roots)), "\n";
    $route_plan = new WritePlanner()->plan(
        resource: DomainResource::class,
        roots: $route_roots,
        parent: CertificateResource::class,
        relationship: CertificateResource::DOMAINS,
    );
    echo "######## plan\n", planTree($route_plan), "\n";
    $route_writer = new WriterBuilder(pdo: $pdo, lock: $lock)->root(plan: $route_plan, data: $route_roots, route_id: 1);
    echo "######## execution tree\n", executionTree($route_writer->explain()), "\n";
    $route_keys = $route_writer->execute();
    echo "######## response\n201 Created\n", json(['data' => array_map(
        static fn(array $keys): array => ['type' => DomainResource::type(), 'id' => (string) $keys[DomainResource::primaryColumn()]],
        $route_keys,
    )]), "\n";
} catch (InvalidDocumentException|WriteNotFoundException $exception) {
    echo "######## response\n", json(['errors' => [$exception->toError()]]), "\n";
} finally {
    $pdo->rollBack();
}

// ---------------------------------------------------------------------------------------------------------------
// 5. POST /certificates/999/domains — тот же план (он переиспользуемый, id маршрута — значение), сертификата 999 нет:
//    RouteParentWriter отвечает 404 до первой вставки

echo "\n######## 5. POST /certificates/999/domains (same plan, route id 999)\n";
$pdo->beginTransaction();
try {
    new WriterBuilder(pdo: $pdo, lock: $lock)->root(plan: $route_plan, data: $route_roots, route_id: 999)->execute();
} catch (InvalidDocumentException|WriteNotFoundException $exception) {
    echo "######## response\n", json(['errors' => [$exception->toError()]]), "\n";
} finally {
    $pdo->rollBack();
}

// ---------------------------------------------------------------------------------------------------------------
// 6. DELETE /certificates/1 — тела нет: WriteDocumentParser::delete() проверяет Deletable и даёт корень remove; план и
//    исполнители те же. Удаляется строка certificates, остальное — каскад базы: детали, доставка, домены, их логи и
//    конфиги (у domain_configs ресурса нет — движок о ней не знает). Ответ — 204 No Content

echo "\n######## 6. DELETE /certificates/1\n\n";

$domain_ids = implode(', ', $pdo->query('select id from domains where certificate_id = 1')->fetchAll(PDO::FETCH_COLUMN));

$pdo->beginTransaction();
try {
    $delete_root = new WriteDocumentParser()->delete(resource: CertificateWithDetailResource::class, id: '1');
    echo "######## operation tree\n", operations(operation: $delete_root), "\n";
    $delete_plan = new WritePlanner()->plan(resource: CertificateWithDetailResource::class, roots: [$delete_root]);
    echo "######## plan\n", planTree($delete_plan), "\n";
    $delete_writer = new WriterBuilder(pdo: $pdo, lock: $lock)->root(plan: $delete_plan, data: [$delete_root]);
    echo "######## execution tree\n", executionTree($delete_writer->explain()), "\n";
    echo "######## rows of certificate 1 before\n", json(cascadeState(pdo: $pdo, domain_ids: $domain_ids)), "\n\n";
    $delete_writer->execute();
    echo "######## response\n204 No Content\n\n";
    echo "######## rows of certificate 1 after (before rollback)\n", json(cascadeState(pdo: $pdo, domain_ids: $domain_ids)), "\n";
} catch (InvalidDocumentException|WriteNotFoundException $exception) {
    echo "######## response\n", json(['errors' => [$exception->toError()]]), "\n";
} finally {
    $pdo->rollBack();
}

// ---------------------------------------------------------------------------------------------------------------
// 7. DELETE /certificates/999 — тот же план, сертификата нет: DELETE … RETURNING пуст → 404 с ресурсом в meta

echo "\n######## 7. DELETE /certificates/999 (same plan)\n";
$pdo->beginTransaction();
try {
    $missing_root = new WriteDocumentParser()->delete(resource: CertificateWithDetailResource::class, id: '999');
    new WriterBuilder(pdo: $pdo, lock: $lock)->root(plan: $delete_plan, data: [$missing_root])->execute();
} catch (InvalidDocumentException|WriteNotFoundException $exception) {
    echo "######## response\n", json(['errors' => [$exception->toError()]]), "\n";
} finally {
    $pdo->rollBack();
}

// ---------------------------------------------------------------------------------------------------------------
// 8. PATCH /certificates/1 — полная замена списка domains: ни у одного элемента нет meta.op, названные (1 и 3)
//    остаются, остальные (2) удаляются. В плане WriteReplaceNode под WriteJoinNode той же связи; у места domains одна
//    ветвь update (её же исполняет keep)

$replace_document = [
    'data' => [
        'type' => 'certificate',
        'id' => '1',
        'relationships' => [
            'domains' => ['data' => [
                ['type' => 'domain', 'id' => '1'],
                ['type' => 'domain', 'id' => '3'],
            ]],
        ],
    ],
];
echo "\n######## 8. PATCH /certificates/1 (full replacement of domains)\n", json($replace_document), "\n\n";
$pdo->beginTransaction();
try {
    $replace_root = new WriteDocumentParser()->update(document: $replace_document, resource: CertificateWithDetailResource::class, id: '1');
    echo "######## operation tree\n", operations(operation: $replace_root), "\n";
    $replace_plan = new WritePlanner()->plan(resource: CertificateWithDetailResource::class, roots: [$replace_root]);
    echo "######## plan\n", planTree($replace_plan), "\n";
    echo "######## before\n", json(certificateState(pdo: $pdo)), "\n\n";
    new WriterBuilder(pdo: $pdo, lock: $lock)->root(plan: $replace_plan, data: [$replace_root])->execute();
    echo "######## after (before rollback)\n", json(certificateState(pdo: $pdo)), "\n";
} catch (InvalidDocumentException|WriteNotFoundException $exception) {
    echo "######## response\n", json(['errors' => [$exception->toError()]]), "\n";
} finally {
    $pdo->rollBack();
}

// ---------------------------------------------------------------------------------------------------------------
// 9. PATCH /certificates/1 — замена пустым: delivery: null (текущая доставка удаляется) и domains: [] (удаляются все
//    домены). Детей нет, передавать нечего: в плане только WriteReplaceNode, узлов связи нет

$empty_document = [
    'data' => [
        'type' => 'certificate',
        'id' => '1',
        'relationships' => [
            'delivery' => ['data' => null],
            'domains' => ['data' => []],
        ],
    ],
];
echo "\n######## 9. PATCH /certificates/1 (replacement by empty: delivery null, domains [])\n", json($empty_document), "\n\n";
$pdo->beginTransaction();
try {
    $empty_root = new WriteDocumentParser()->update(document: $empty_document, resource: CertificateWithDetailResource::class, id: '1');
    echo "######## operation tree\n", operations(operation: $empty_root), "\n";
    $empty_plan = new WritePlanner()->plan(resource: CertificateWithDetailResource::class, roots: [$empty_root]);
    echo "######## plan\n", planTree($empty_plan), "\n";
    echo "######## before\n", json(certificateState(pdo: $pdo)), "\n\n";
    new WriterBuilder(pdo: $pdo, lock: $lock)->root(plan: $empty_plan, data: [$empty_root])->execute();
    echo "######## after (before rollback)\n", json(certificateState(pdo: $pdo)), "\n";
} catch (InvalidDocumentException|WriteNotFoundException $exception) {
    echo "######## response\n", json(['errors' => [$exception->toError()]]), "\n";
} finally {
    $pdo->rollBack();
}
