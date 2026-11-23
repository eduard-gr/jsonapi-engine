<?php

declare(strict_types=1);

/**
 * Пример: дерево читателей исполнителя, собранное руками, только чтение. Два ресурса из разных мест: `domain` —
 * таблица `domains` базы example/db, `domain-note` — файл example/db/domain-notes.csv. Соединяет их
 * `MergeNestJoinReader` (1:N, связь `notes` домена): оба потока идут по id домена, поэтому ни одна сторона
 * не читается в память целиком — файл и курсор запроса читаются построчно навстречу друг другу.
 *
 *     MergeNestJoinReader 'notes' (id = domain_id)
 *     ├─ outer: UniqueAscendingKeyReader 'id' (строго)
 *     │         └─ RegisterReader ← SerializeReader(DomainResource) ← EntityScanReader(DOMAINS_QUERY)
 *     └─ inner: AscendingKeyReader 'domain_id' (повторы допустимы)
 *               └─ RegisterReader ← SerializeReader(DomainNoteResource) ← CsvScanReader(файл)
 *
 * Порядок: запрос сортирует домены сам (`order by d.id`), а файл отсортировать некому — его строки должны лежать
 * по `domain_id` заранее. Проверяет это `AscendingKeyReader` над каждым входом: файл не по порядку —
 * `UnexpectedValueException`, а не молча неполные связи.
 *
 * Ограничения примера:
 *  - соединение строгое, как между двумя таблицами: заметка, домена которой нет в потоке доменов, — `LogicException`
 *    узла слияния. Файл условий запроса не знает, поэтому заметки в нём есть только у доменов из `DOMAINS_QUERY`
 *    (сертификаты 1–3, домены 1–8). «Свободное» соединение, которое такие строки пропускает, — в прототипе
 *    example/source-document.php (`MergeNestJoinReader`);
 *  - связь `notes` записывает только это дерево: в `DomainResource::getRelationships()` её нет, планировщик о ней
 *    не знает;
 *  - механизма источников (`SelectForeignDataInterface`, контейнер) здесь нет: лист читает файл напрямую, `DomainNotesSource` —
 *    только описание его колонок.
 *
 * Оба ресурса собирает `SerializeReader` по их описанию; где в строке лежат колонки ресурса, говорит запись
 * колонок, написанная руками (по плану её даёт `EntityScanNode::columns`): `QUERY2_DOMAIN` и `NOTE_COLUMNS`. В файле все
 * значения — строки: к целым их приводит сериализатор по `type` атрибута и как ключ соединения.
 *
 * Запуск:
 *     php example/file-merge-document.php             # документ JSON:API
 *     php example/file-merge-document.php --explain   # дерево читателей без выполнения
 */

namespace Ggs\JsonApiEngine\Example\FileMergeDocument;

use Generator;
use Ggs\JsonApiEngine\Document\Document;
use Ggs\JsonApiEngine\Document\ResourceRegistry;
use Ggs\JsonApiEngine\Example\Resource\DomainResource;
use Ggs\JsonApiEngine\Executor\PullExecutorInterface;
use Ggs\JsonApiEngine\Executor\Select\AscendingKeyReader;
use Ggs\JsonApiEngine\Executor\Select\MergeNestJoinReader;
use Ggs\JsonApiEngine\Executor\Select\RegisterReader;
use Ggs\JsonApiEngine\Executor\Select\SerializeReader;
use Ggs\JsonApiEngine\Executor\Select\EntityScanReader;
use Ggs\JsonApiEngine\Executor\Select\UniqueAscendingKeyReader;
use Ggs\JsonApiEngine\Model\DataSource;
use Ggs\JsonApiEngine\Model\ForeignDataSource;
use Ggs\JsonApiEngine\Resource;
use RuntimeException;

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/db/connection.php';

/**
 * Домены сертификатов 1–3 с алиасами как в QUERY2: колонки ресурса в строке находит запись `QUERY2_DOMAIN`
 * (example/db/connection.php).
 * Сортировка по id домена — требование слияния с заметками.
 */
const DOMAINS_QUERY = <<<SQL
    select
        d.id as domain_id,
        d.certificate_id,
        d.domain_name,
        d.is_primary,
        d.created_at
    from
        domains as d
    where
        d.certificate_id <= 3
    order by
        d.id
SQL;

const NOTES_FILE = __DIR__ . '/db/domain-notes.csv';

/**
 * Лист дерева: читает CSV-файл и отдаёт его построчно. Пара к `EntityScanReader`, только вместо запроса — файл.
 *
 * Поток: вход — нет (лист) → выход — строки array `имя колонки → значение`; имена берутся из первой строки файла
 * (заголовка), значения — всегда строки, как они записаны в файле: приводит типы тот, кто собирает ресурс.
 *
 * Узел ленивый: файл открывается при первом обращении к генератору и читается по одной строке, в память целиком
 * не загружается. Закрывается, когда генератор завершён или уничтожен. Каждый вызов getIterator() читает файл заново.
 *
 * Порядок строк — порядок файла: сортировать узел не умеет. Условий и списка колонок у него тоже нет — отдаёт всё.
 *
 * Ошибки: файл не открылся или пуст (нет заголовка) — `RuntimeException`; в строке другое число значений, чем
 * в заголовке, — `RuntimeException` с номером строки. Пустые строки файла пропускаются.
 */
final readonly class CsvScanReader implements PullExecutorInterface
{
    /**
     * @param string $path Путь к файлу: разделитель — запятая, значения в двойных кавычках, первая строка — заголовок
     */
    public function __construct(
        private string $path,
    ) {}

    #[\Override]
    public function explain(): array
    {
        return [
            'node' => static::class,
            'path' => $this->path,
        ];
    }

    /**
     * @return Generator<int, array<string, string>>
     * @throws RuntimeException если файл не читается или строка не совпадает с заголовком
     */
    #[\Override]
    public function getIterator(): Generator
    {
        $file = @fopen($this->path, 'r');
        if ($file === false) {
            throw new RuntimeException("Cannot open file: {$this->path}");
        }

        try {
            $header = fgetcsv($file, null, ',', '"', '');
            if ($header === false || $header === [null]) {
                throw new RuntimeException("File has no header line: {$this->path}");
            }

            $line = 1;
            while (($values = fgetcsv($file, null, ',', '"', '')) !== false) {
                $line++;
                // Пустая строка файла: fgetcsv() отдаёт её как [null]
                if ($values === [null]) {
                    continue;
                }
                if (\count($values) !== \count($header)) {
                    throw new RuntimeException(
                        "Line {$line} of {$this->path} has " . \count($values) . ' values, header has ' . \count($header),
                    );
                }

                yield array_combine($header, $values);
            }
        } finally {
            fclose($file);
        }
    }
}

/**
 * Описание колонок файла example/db/domain-notes.csv: чужой источник, строки лежат по `domain_id`.
 * Только описание: читает файл `CsvScanReader`.
 */
final class DomainNotesSource extends ForeignDataSource
{
    public const string ID = 'id';
    public const string DOMAIN_ID = 'domain_id';
    public const string AUTHOR = 'author';
    public const string TEXT = 'text';

    public const array COLUMNS = [
        self::ID => ['type' => self::INT, 'constraint' => self::PRIMARY],
        self::DOMAIN_ID => ['type' => self::INT],
        self::AUTHOR => ['type' => self::STRING],
        self::TEXT => ['type' => self::STRING],
    ];
}

/**
 * `domain-note`: заметка о домене из файла (`DomainNotesSource`).
 */
final class DomainNoteResource extends Resource
{
    public const string TYPE = 'domain-note';

    public const string ID = 'id';
    public const string AUTHOR = 'author';
    public const string TEXT = 'text';

    public const array ATTRIBUTES = [
        self::ID => ['column' => DomainNotesSource::ID, 'type' => self::INT, 'constraint' => self::PRIMARY],
        self::AUTHOR => ['column' => DomainNotesSource::AUTHOR, 'type' => self::STRING],
        self::TEXT => ['column' => DomainNotesSource::TEXT, 'type' => self::STRING],
    ];

    #[\Override]
    public static function getRelationships(): array
    {
        return [];
    }

    public static function getModel(): string
    {
        return DomainNotesSource::class;
    }
}

/**
 * Запись колонок `DomainNoteResource` для `SerializeReader`: колонка модели → колонка файла (имена совпадают),
 * атрибуты для вывода и ключ соединения с доменом.
 */
const NOTE_COLUMNS = [
    'resource' => DomainNoteResource::class,
    'alias' => 'notes',
    'columns' => [
        DomainNotesSource::ID => 'id',
        DomainNotesSource::DOMAIN_ID => 'domain_id',
        DomainNotesSource::AUTHOR => 'author',
        DomainNotesSource::TEXT => 'text',
    ],
    'fields' => [DomainNoteResource::AUTHOR, DomainNoteResource::TEXT],
    'keys' => [DomainNotesSource::DOMAIN_ID],
];

$pdo = getPDO();

// Реестр ресурсов этого выполнения: один на всё дерево и на Document
$registry = new ResourceRegistry();

// Домены из базы, по возрастанию id
$domains = new RegisterReader(
    source: new SerializeReader(
        source: new EntityScanReader(
            pdo: $pdo,
            sql: DOMAINS_QUERY,
        ),
        resource: new DomainResource(),
        columns: QUERY2_DOMAIN,
    ),
    registry: $registry,
);

// Заметки из файла, в порядке файла — по domain_id
$notes = new RegisterReader(
    source: new SerializeReader(
        source: new CsvScanReader(
            path: NOTES_FILE,
        ),
        resource: new DomainNoteResource(),
        columns: NOTE_COLUMNS,
    ),
    registry: $registry,
);

// Домены с заметками: курсор запроса и файл открыты одновременно и читаются навстречу друг другу.
// Порядок ключей проверяют узлы на входах: домены — строго по id (UniqueAscendingKeyReader), заметки —
// по domain_id с повторами (AscendingKeyReader)
$flow = new MergeNestJoinReader(
    outer: new UniqueAscendingKeyReader(
        source: $domains,
        key: 'id',
    ),
    inner: new AscendingKeyReader(
        source: $notes,
        key: DomainNotesSource::DOMAIN_ID,
    ),
    outer_key: 'id',
    inner_key: DomainNotesSource::DOMAIN_ID,
    name: 'notes',
);

$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

if (\in_array('--explain', $argv, true)) {
    echo json_encode($flow->explain(), $flags), "\n";

    return;
}

// Домены уходят в data, заметки — в included по связи notes. Дерево выполняется внутри json_encode()
$document = new Document($flow, $registry);
echo json_encode($document, $flags), "\n";
