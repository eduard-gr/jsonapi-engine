<?php

declare(strict_types=1);

/**
 * Пример полиморфной связи (polymorphic relationship), только чтение. Два ресурса, оба из базы: `certificate`
 * (таблица `certificates`) и `ValidationResource` — проверка владения доменом, 1:N от сертификата. Связь
 * `validations` сертификата полиморфная: в ней лежат ресурсы трёх типов, у каждого свой набор атрибутов.
 *
 * Данные проверки — в четырёх таблицах, модель ресурса (`ValidationResource::getModel()`) — их LEFT JOIN:
 *
 *     certificate_validations (id, certificate_id, method, status)   — главная строка, есть у каждой проверки
 *         left join validation_emails      (validation_id, approver_email)             — method = 'email'
 *         left join validation_dns_records (validation_id, record_name, record_value)  — method = 'dns'
 *         left join validation_http_files  (validation_id, file_url, file_content)     — method = 'http'
 *
 * В строке запроса есть колонки всех трёх таблиц, заполнены — только одной. Ресурс собирает его ручная
 * сериализация `ValidationResource::serialize()`: по внутренней колонке `method` она выбирает тип и поля:
 *
 *     'email' → type `validation-email`: status, approver_email
 *     'dns'   → type `validation-dns`:   status, record_name, record_value
 *     'http'  → type `validation-http`:  status, file_url, file_content
 *
 * Две части ручной обработки:
 *  - `getRequiredColumns()` — `method` не атрибут, и запрос плана её бы не выбрал; ресурс называет её сам;
 *  - `serialize()` — раз ресурс реализует `SerializeInterface`, вместо `SerializeReader` ставится `ManualSerializeReader`: он не собирает ресурс по `ATTRIBUTES`, а отдаёт
 *    методу строку «колонка модели → значение» (с `method`).
 *
 * Запрос проверок строит план, а не рука: `EntityScanNodeDraft::withSelect()` по модели и `fields` ресурса (`--sql`
 * печатает его; с `--fields=status` видно, что `method` в запросе остаётся, а три LEFT JOIN отбрасываются). Остальное
 * дерево собрано руками — у `CertificateResource` нет связи `validations`, поэтому целиком по плану (через парсер и
 * `SelectPlanner`) пример не идёт:
 *
 *     MergeNestJoinReader 'validations' (id = certificate_id)
 *     ├─ outer: UniqueAscendingKeyReader 'id' (строго)
 *     │         └─ RegisterReader ← ManualSerializeReader(CertificateResource) ← EntityScanReader(CERTIFICATES_QUERY)
 *     └─ inner: AscendingKeyReader 'certificate_id' (повторы допустимы)
 *               └─ RegisterReader ← ManualSerializeReader(ValidationResource) ← EntityScanReader(запрос плана)
 *
 * Особых узлов полиморфизм не требует: он целиком в сериализации ресурса, узлу соединения тип связанного не важен.
 *
 * Таблиц проверок в example/db нет: пример создаёт их временными (`CREATE TEMP TABLE`) на своём подключении,
 * файл базы не меняется.
 *
 * Ограничения примера:
 *  - идентификаторы трёх типов идут из одной колонки `certificate_validations.id`, поэтому уникальны и между
 *    типами;
 *  - `fields` клиент может задать только по общему `TYPE` (`validation`): парсер типов вариантов не знает;
 *  - запрос проверок не ограничен сертификатами 1–3 (цепочки предков нет — дерево собрано руками): проверки есть
 *    только у сертификатов 1 и 2, иначе слияние встретило бы проверку без сертификата;
 *  - фильтры и запись для трёх типов не рассматривались.
 *
 * Запуск:
 *     php example/polymorphic-document.php                   # документ JSON:API
 *     php example/polymorphic-document.php --fields=status   # только общий атрибут: тип всё равно по method
 *     php example/polymorphic-document.php --sql             # запрос проверок, построенный планом
 *     php example/polymorphic-document.php --explain         # дерево читателей без выполнения
 */

namespace Ggs\JsonApiEngine\Example\PolymorphicDocument;

use Ggs\JsonApiEngine\Document\Document;
use Ggs\JsonApiEngine\Document\ResourceRecord;
use Ggs\JsonApiEngine\Document\ResourceRegistry;
use Ggs\JsonApiEngine\Example\Entity\CertificatesEntity;
use Ggs\JsonApiEngine\Example\Resource\CertificateResource;
use Ggs\JsonApiEngine\Executor\Select\AscendingKeyReader;
use Ggs\JsonApiEngine\Executor\Select\ManualSerializeReader;
use Ggs\JsonApiEngine\Executor\Select\MergeNestJoinReader;
use Ggs\JsonApiEngine\Executor\Select\RegisterReader;
use Ggs\JsonApiEngine\Executor\Select\SerializeReader;
use Ggs\JsonApiEngine\Executor\Select\EntityScanReader;
use Ggs\JsonApiEngine\Executor\Select\UniqueAscendingKeyReader;
use Ggs\JsonApiEngine\Model\DatabaseEntitySource;
use Ggs\JsonApiEngine\Model\LeftJoin;
use Ggs\JsonApiEngine\Plan\Select\ResourceNode;
use Ggs\JsonApiEngine\Plan\Select\EntityScanNodeDraft;
use Ggs\JsonApiEngine\Resource;
use Ggs\JsonApiEngine\Resource\SerializeInterface;
use LogicException;
use sad_spirit\pg_builder\SqlBuilderWalker;

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/db/connection.php';

/**
 * Временные таблицы проверок и их данные. Проверка 5 (`dns`) без строки в своей таблице; строка
 * `validation_emails` 99 — без главной строки (в запрос не попадает); у сертификата 3 проверок нет.
 */
const SCHEMA = <<<SQL
    create temp table certificate_validations (
        id integer primary key,
        certificate_id int not null,
        method varchar(16) not null,
        status varchar(16) not null
    );
    create temp table validation_emails (
        validation_id int primary key,
        approver_email varchar(255) not null
    );
    create temp table validation_dns_records (
        validation_id int primary key,
        record_name varchar(255) not null,
        record_value varchar(255) not null
    );
    create temp table validation_http_files (
        validation_id int primary key,
        file_url varchar(255) not null,
        file_content varchar(255) not null
    );

    insert into certificate_validations (id, certificate_id, method, status) values
        (1, 1, 'email', 'PASSED'),
        (2, 1, 'dns', 'PENDING'),
        (3, 2, 'http', 'PASSED'),
        (4, 2, 'email', 'FAILED'),
        (5, 2, 'dns', 'PENDING'),
        (6, 1, 'http', 'PENDING');
    insert into validation_emails (validation_id, approver_email) values
        (1, 'admin@green1.net'),
        (4, 'webmaster@media2.ru'),
        (99, 'nobody@example.com');
    insert into validation_dns_records (validation_id, record_name, record_value) values
        (2, '_dcv.green1.net', 'a1b2c3.validation.example');
    insert into validation_http_files (validation_id, file_url, file_content) values
        (3, 'http://media2.ru/.well-known/pki-validation/3.txt', 'd4e5f6'),
        (6, 'http://green1.net/.well-known/pki-validation/6.txt', '0a9b8c');
SQL;

/** Сертификаты 1–3 с алиасами как в QUERY1: колонки ресурса в строке находит запись `QUERY1_CERTIFICATE` */
const CERTIFICATES_QUERY = <<<SQL
    select
        cc.id as certificate_id,
        cc.provider_id,
        cc.code,
        cc.nominal,
        cc.currency,
        cc.status,
        cc.created_at
    from
        certificates as cc
    where
        cc.id <= 3
    order by
        cc.id
SQL;

/**
 * Временная таблица `certificate_validations`: главная строка проверки, 1:N от `certificates`. `method` говорит,
 * в какой из трёх таблиц лежат остальные данные проверки.
 */
final class CertificateValidationsEntity extends DatabaseEntitySource
{
    public const string TABLE = 'certificate_validations';

    public const string ID = 'id';
    public const string CERTIFICATE_ID = 'certificate_id';
    public const string METHOD = 'method';
    public const string STATUS = 'status';

    public const array COLUMNS = [
        self::ID => ['type' => self::INT, 'constraint' => self::PRIMARY],
        self::CERTIFICATE_ID => [
            'type' => self::INT,
            'constraint' => self::FOREIGN,
            'references' => CertificatesEntity::class,
        ],
        self::METHOD => ['type' => self::STRING],
        self::STATUS => ['type' => self::STRING],
    ];
}

/**
 * Временная таблица `validation_emails`: данные проверки методом `email`, 1:1 с `certificate_validations`.
 * `validation_id` — первичный ключ и внешний ключ на главную строку; связь задаётся в модели ресурса.
 */
final class ValidationEmailsEntity extends DatabaseEntitySource
{
    public const string TABLE = 'validation_emails';

    public const string VALIDATION_ID = 'validation_id';
    public const string APPROVER_EMAIL = 'approver_email';

    public const array COLUMNS = [
        self::VALIDATION_ID => ['type' => self::INT, 'constraint' => self::PRIMARY],
        self::APPROVER_EMAIL => ['type' => self::STRING],
    ];
}

/**
 * Временная таблица `validation_dns_records`: данные проверки методом `dns`, 1:1 с `certificate_validations`.
 */
final class ValidationDnsRecordsEntity extends DatabaseEntitySource
{
    public const string TABLE = 'validation_dns_records';

    public const string VALIDATION_ID = 'validation_id';
    public const string RECORD_NAME = 'record_name';
    public const string RECORD_VALUE = 'record_value';

    public const array COLUMNS = [
        self::VALIDATION_ID => ['type' => self::INT, 'constraint' => self::PRIMARY],
        self::RECORD_NAME => ['type' => self::STRING],
        self::RECORD_VALUE => ['type' => self::STRING],
    ];
}

/**
 * Временная таблица `validation_http_files`: данные проверки методом `http`, 1:1 с `certificate_validations`.
 */
final class ValidationHttpFilesEntity extends DatabaseEntitySource
{
    public const string TABLE = 'validation_http_files';

    public const string VALIDATION_ID = 'validation_id';
    public const string FILE_URL = 'file_url';
    public const string FILE_CONTENT = 'file_content';

    public const array COLUMNS = [
        self::VALIDATION_ID => ['type' => self::INT, 'constraint' => self::PRIMARY],
        self::FILE_URL => ['type' => self::STRING],
        self::FILE_CONTENT => ['type' => self::STRING],
    ];
}

/**
 * Проверка владения доменом — полиморфный ресурс: один класс, одна модель и один запрос, а ресурсов JSON:API
 * получается три типа. Модель — главная таблица `certificate_validations` и три таблицы методов через LEFT JOIN;
 * какой тип и какие атрибуты у ресурса, решает ручная сериализация `serialize()` по внутренней колонке `method`
 * (в документ она не выводится):
 *
 *     'email' → `validation-email`: status, approver_email
 *     'dns'   → `validation-dns`:   status, record_name, record_value
 *     'http'  → `validation-http`:  status, file_url, file_content
 *
 * `ATTRIBUTES` перечисляет атрибуты всех трёх типов (атрибуты методов — `nullable`: в строке другого метода их
 * колонки NULL) — по ним работают `fields`, фильтры и список колонок запроса. Какие из них относятся к какому
 * методу, описание не говорит: это знает только `serialize()` (`METHODS`). `TYPE` — общее имя для `fields` клиента,
 * в документ не попадает.
 */
final class ValidationResource extends Resource implements SerializeInterface
{
    public const string TYPE = 'validation';

    public const string ID = 'id';
    public const string STATUS = 'status';
    public const string APPROVER_EMAIL = 'approver_email';
    public const string RECORD_NAME = 'record_name';
    public const string RECORD_VALUE = 'record_value';
    public const string FILE_URL = 'file_url';
    public const string FILE_CONTENT = 'file_content';

    public const array ATTRIBUTES = [
        self::ID => ['column' => CertificateValidationsEntity::ID, 'type' => self::INT, 'constraint' => self::PRIMARY],
        self::STATUS => ['column' => CertificateValidationsEntity::STATUS, 'type' => self::STRING],
        self::APPROVER_EMAIL => [
            'column' => ValidationEmailsEntity::APPROVER_EMAIL,
            'type' => self::STRING,
            'nullable' => true,
        ],
        self::RECORD_NAME => [
            'column' => ValidationDnsRecordsEntity::RECORD_NAME,
            'type' => self::STRING,
            'nullable' => true,
        ],
        self::RECORD_VALUE => [
            'column' => ValidationDnsRecordsEntity::RECORD_VALUE,
            'type' => self::STRING,
            'nullable' => true,
        ],
        self::FILE_URL => [
            'column' => ValidationHttpFilesEntity::FILE_URL,
            'type' => self::STRING,
            'nullable' => true,
        ],
        self::FILE_CONTENT => [
            'column' => ValidationHttpFilesEntity::FILE_CONTENT,
            'type' => self::STRING,
            'nullable' => true,
        ],
    ];

    /**
     * Значение колонки `method` → тип ресурса JSON:API и его атрибуты, кроме общего `status`.
     *
     * @var array<string, array{type: string, attributes: list<string>}>
     */
    private const array METHODS = [
        'email' => [
            'type' => 'validation-email',
            'attributes' => [self::APPROVER_EMAIL],
        ],
        'dns' => [
            'type' => 'validation-dns',
            'attributes' => [self::RECORD_NAME, self::RECORD_VALUE],
        ],
        'http' => [
            'type' => 'validation-http',
            'attributes' => [self::FILE_URL, self::FILE_CONTENT],
        ],
    ];

    #[\Override]
    public static function getRelationships(): array
    {
        return [];
    }

    /**
     * Главная таблица и три таблицы методов, каждая 1:1 по `validation_id`. LEFT: у проверки есть строка только
     * в таблице своего метода.
     */
    public static function getModel(): LeftJoin
    {
        return new LeftJoin(
            left: new LeftJoin(
                left: new LeftJoin(
                    left: CertificateValidationsEntity::class,
                    right: ValidationEmailsEntity::class,
                    relation: [CertificateValidationsEntity::ID, ValidationEmailsEntity::VALIDATION_ID],
                ),
                right: ValidationDnsRecordsEntity::class,
                relation: [CertificateValidationsEntity::ID, ValidationDnsRecordsEntity::VALIDATION_ID],
            ),
            right: ValidationHttpFilesEntity::class,
            relation: [CertificateValidationsEntity::ID, ValidationHttpFilesEntity::VALIDATION_ID],
        );
    }

    /**
     * `method` — не атрибут, но без неё ресурс не собрать: по ней `serialize()` выбирает тип и атрибуты. Запрос
     * плана выбирает только колонки запрошенных атрибутов, поэтому ресурс называет её сам, при любых `fields`.
     */
    #[\Override]
    public static function getRequiredColumns(array $fields): array
    {
        return [CertificateValidationsEntity::METHOD];
    }

    /**
     * Ручная сериализация: раз метод объявлен, `ManualSerializeReader` вызывает его на каждой строке вместо сборки
     * по `ATTRIBUTES`. `$tuple` — «колонка модели → значение»: первичный ключ, колонки запрошенных атрибутов,
     * `method` (её заказал `getRequiredColumns()`) и ключи соединений; имена колонок здесь совпадают с именами
     * атрибутов.
     *
     * `method` выбирает тип ресурса и то, какие атрибуты у него есть: общий `status` и атрибуты своего метода;
     * колонки чужих методов не читаются. Из них выводятся только запрошенные — названные в `$fields`, в его
     * порядке. Строки в таблице своего метода нет (пустой LEFT JOIN) — атрибуты null. Ключи соединений дописывает
     * `ManualSerializeReader`.
     *
     * @param array<string, mixed> $tuple
     * @param list<string>         $fields запрошенные атрибуты
     * @throws LogicException если значение `method` неизвестно: молча выдать ресурс без типа нельзя
     */
    #[\Override]
    public function serialize(array $tuple, array $fields): ResourceRecord
    {
        $id = (int) $tuple[CertificateValidationsEntity::ID];
        $method = self::METHODS[$tuple[CertificateValidationsEntity::METHOD]] ?? null;
        if ($method === null) {
            throw new LogicException(
                "Unknown validation method '{$tuple[CertificateValidationsEntity::METHOD]}' of validation {$id}",
            );
        }

        // Запрошенное клиентом, но только то, что есть у этого метода проверки
        $own = [self::STATUS, ...$method['attributes']];
        $attributes = [];
        foreach ($fields as $attribute) {
            if (\in_array($attribute, $own, true)) {
                $attributes[$attribute] = $tuple[$attribute];
            }
        }

        return new ResourceRecord(
            id: $id,
            type: $method['type'],
            attributes: $attributes,
            relationships: [],
        );
    }
}

/** Значение аргумента `--fields=a,b` или null, если аргумента нет */
function getFieldsArgument(array $arguments): ?string
{
    foreach ($arguments as $argument) {
        if (str_starts_with($argument, '--fields=')) {
            return substr($argument, \strlen('--fields='));
        }
    }

    return null;
}

$pdo = getPDO();
// Временные таблицы видны только этому подключению и исчезают вместе с ним
$pdo->exec(SCHEMA);

// Реестр ресурсов этого выполнения: один на всё дерево и на Document
$registry = new ResourceRegistry();

$certificates = new RegisterReader(
    source: new ManualSerializeReader(
        source: new EntityScanReader(
            pdo: $pdo,
            sql: CERTIFICATES_QUERY,
        ),
        resource: new CertificateResource(),
        columns: QUERY1_CERTIFICATE,
    ),
    registry: $registry,
);

// Запрос проверок строит план: владелец запроса над ресурсом с fields клиента (по умолчанию — все атрибуты),
// порядок и ключ certificate_id — для слияния с сертификатами. В список колонок попадают первичный ключ, колонки
// fields, method из getRequiredColumns() и ключ; LEFT JOIN таблиц, чьи колонки не нужны, отбрасывается
$fields = getFieldsArgument(arguments: $argv);
$validations_query = new EntityScanNodeDraft(
    input: new ResourceNode(
        resource: ValidationResource::class,
        fields: $fields === null ? ValidationResource::getPublicAttributes() : explode(',', $fields),
    ),
)
    ->withOrder(order: CertificateValidationsEntity::CERTIFICATE_ID)
    ->withSelect(keys: [CertificateValidationsEntity::CERTIFICATE_ID]);
$validations_sql = $validations_query->select->dispatch(new SqlBuilderWalker());

if (\in_array('--sql', $argv, true)) {
    echo $validations_sql, "\n";

    return;
}

// Проверки: один запрос, один класс ресурса — а в потоке ресурсы трёх типов. У ресурса есть свой serialize(),
// поэтому вместо SerializeReader стоит ManualSerializeReader: он отдаёт строку ему; запись колонок — от запроса плана ($validations_query->columns)
$validations = new RegisterReader(
    source: new ManualSerializeReader(
        source: new EntityScanReader(
            pdo: $pdo,
            sql: $validations_sql,
            parameters: $validations_query->parameters,
        ),
        resource: new ValidationResource(),
        columns: $validations_query->columns[0],
    ),
    registry: $registry,
);

// Сертификаты с проверками: связь validations полиморфная — узлу соединения тип связанного ресурса не важен,
// он записывает в связь getIdentifier() того, что пришло. Оба запроса идут по id сертификата, поэтому слияние
$flow = new MergeNestJoinReader(
    outer: new UniqueAscendingKeyReader(
        source: $certificates,
        key: 'id',
    ),
    inner: new AscendingKeyReader(
        source: $validations,
        key: 'certificate_id',
    ),
    outer_key: 'id',
    inner_key: 'certificate_id',
    name: 'validations',
);

$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

if (\in_array('--explain', $argv, true)) {
    echo json_encode($flow->explain(), $flags), "\n";

    return;
}

// Сертификаты уходят в data, проверки — в included по связи validations. Дерево выполняется внутри json_encode()
$document = new Document($flow, $registry);
echo json_encode($document, $flags), "\n";
