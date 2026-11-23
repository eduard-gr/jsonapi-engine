<?php

declare(strict_types=1);

/**
 * Пример и прототип: чужой источник (`Model\ForeignDataSource`) на месте сущности. `Resource::getModel()` возвращает
 * имя такого класса так же, как имя сущности. Данные источника могут лежать где угодно (здесь — в памяти), чтение и
 * запись выполняет сам класс.
 *
 * Весь механизм — в `src`, в этом файле только источники, ресурсы и сценарии. Чтение: интерфейс
 * `Model\SelectForeignDataInterface`, узлы плана `ForeignDataScanNode` и `HashOneJoinNode` (их ставит
 * `SelectPlanner`), читатели `ForeignDataScanReader` и `HashOneJoinReader` (их ставит `ReaderBuilder`). Запись:
 * интерфейсы `Model\InsertForeignDataInterface`, `UpdateForeignDataInterface`, `DeleteForeignDataInterface`, узлы
 * плана `InsertForeignDataNode`, `UpdateForeignDataNode`, `KeepForeignDataNode`, `DeleteForeignDataNode` (их ставит
 * `WritePlanner`), исполнители `…ForeignDataWriter` (их ставит `WriterBuilder`). Аргументы маршрута — параметр
 * `ReaderBuilder::getDocument()` и `WriterBuilder::getRoot()`.
 *
 * Источник реализует те операции, которые поддерживает: `NotesSource` — все четыре, `RatingsSource` и
 * `VendorsSource` — только чтение.
 *
 * Правила (решения пользователя):
 *  - источник всегда отдельный лист плана: в общую строку SQL он не входит, связь с ним — соединение между потоками
 *    (Hash или Merge), в том числе связь 1:1 (`HashOneJoinNode`);
 *  - источник получает только то, что прислал клиент (условия, сортировку, поля), и аргументы маршрута; ключей
 *    родителей (`IN (…)`) он не получает. Таблица рядом с источником тоже не получает его условий: цепочка предков
 *    (CTE) на источнике обрывается;
 *  - поэтому в соединении с источником бывают связанные без главного; соединение их пропускает (как и между
 *    двумя таблицами: `HashNestJoinReader`, `MergeNestJoinReader`);
 *  - запись: место ресурса в дереве записи то же, меняется только уровень таблиц — вместо SQL вызывается метод
 *    источника; транзакции у источника нет.
 *
 * Источник — объект: описание (`COLUMNS`) статическое, операции — методы экземпляра. Экземпляры создаёт контейнер
 * зависимостей приложения (PSR-11, со всеми зависимостями источника); сборщики берут объект из него по имени класса.
 *
 * Чего нет: ветка с источником под 1:1 ресурсом внутри строки SQL, полная замена связи с детьми в источнике.
 *
 * Запуск: php example/source-document.php
 */

namespace Ggs\JsonApiEngine\Example\SourceDocument;

use DateTimeInterface;
use Generator;
use Ggs\JsonApiEngine\Example\Container\ArrayContainer;
use Ggs\JsonApiEngine\Example\Container\ExampleResources;
use Ggs\JsonApiEngine\Example\Entity\CertificatesEntity;
use Ggs\JsonApiEngine\Example\Entity\DomainsEntity;
use Ggs\JsonApiEngine\Example\Resource\DomainResource;
use Ggs\JsonApiEngine\Executor\Select\ReaderBuilder;
use Ggs\JsonApiEngine\Executor\Write\WriteNotFoundException;
use Ggs\JsonApiEngine\Executor\Write\WriterBuilder;
use Ggs\JsonApiEngine\Model\DatabaseEntitySource;
use Ggs\JsonApiEngine\Model\DeleteForeignDataInterface;
use Ggs\JsonApiEngine\Model\ForeignDataSource;
use Ggs\JsonApiEngine\Model\InsertForeignDataInterface;
use Ggs\JsonApiEngine\Model\UpdateForeignDataInterface;
use Ggs\JsonApiEngine\Model\SelectForeignDataInterface;
use Ggs\JsonApiEngine\Operator;
use Ggs\JsonApiEngine\Parser\InvalidDocumentException;
use Ggs\JsonApiEngine\Parser\QueryParser;
use Ggs\JsonApiEngine\Parser\Write\WriteDocumentParser;
use Ggs\JsonApiEngine\Parser\Write\WriteOperation;
use Ggs\JsonApiEngine\Plan\Select\JoinNode;
use Ggs\JsonApiEngine\Plan\Select\ResourceNode;
use Ggs\JsonApiEngine\Plan\Select\SelectPlanPrinter;
use Ggs\JsonApiEngine\Plan\Select\EntityScanNodeDraft;
use Ggs\JsonApiEngine\Plan\Select\EntityScanNode;
use Ggs\JsonApiEngine\Plan\Select\ForeignDataScanNode;
use Ggs\JsonApiEngine\Plan\Select\SelectPlanner;
use Ggs\JsonApiEngine\Plan\Write\WritePlanner;
use Ggs\JsonApiEngine\Resource;
use Ggs\JsonApiEngine\Resource\DeletableInterface;
use Ggs\JsonApiEngine\Resource\InsertableInterface;
use Ggs\JsonApiEngine\Resource\ManyToOne;
use Ggs\JsonApiEngine\Resource\OneToMany;
use Ggs\JsonApiEngine\Resource\OneToOne;
use Ggs\JsonApiEngine\Resource\UpdatableInterface;
use LogicException;
use PDO;
use Psr\Container\ContainerInterface;
use sad_spirit\pg_builder\SqlBuilderWalker;
use UnexpectedValueException;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/db/connection.php';

// =====================================================================================================================
// Контракт источника
// =====================================================================================================================

/*
 * Чужой источник — наследник `Model\DataSource`, но не `DatabaseEntitySource`: стоит в `Resource::getModel()` на месте сущности,
 * а данные читает и пишет своим кодом. Колонки (`COLUMNS`) он описывает так же, как сущность, — любой источник
 * данных отдаёт табличное представление; `TABLE` у него нет. Описание статическое, а операции — методы объекта:
 * движок получает экземпляр из контейнера зависимостей (PSR-11) и вызывает их у него, поэтому у источника могут быть
 * зависимости (клиент сервиса, подключение).
 *
 * Что источник умеет, говорят интерфейсы операций ниже: `SelectForeignDataInterface`, `InsertForeignDataInterface`, `UpdateForeignDataInterface`,
 * `DeleteForeignDataInterface`. Источник реализует только те, которые поддерживает (источник только для чтения — один
 * `SelectForeignDataInterface`).
 *
 * По `COLUMNS` работают `ATTRIBUTES` ресурса, сериализация и сборка строки записи. Методы операций работают в
 * колонках модели, не в атрибутах: перевод «атрибут → колонка» делает движок. `$arguments` — аргументы маршрута как
 * есть (`/certificates/3/notes` → `['certificate_id' => 3]`); что с ними делать, решает источник.
 *
 * Возможности ресурса (`InsertableInterface`, `UpdatableInterface`, `DeletableInterface`) и источника объявляются отдельно и должны совпадать:
 * ресурс `InsertableInterface` над источником без `InsertForeignDataInterface` — ошибка описания (`LogicException` при сборке дерева).
 * Запрос клиента к ресурсу без возможности парсер отклоняет как раньше (403).
 */

/**
 * Источник в памяти только для чтения: строки в свойстве объекта. Общая реализация `select()` для источников
 * примера; настоящий источник ходил бы в сервис или файл. Запись добавляет `WritableMemoryForeignDataSource`.
 *
 * Аргументы маршрута, совпавшие по имени с колонкой, — условие равенства. Без сортировки клиента строки идут в
 * ориентации `getReadOrientation()` наследника (null — как лежат). Каждый вызов пишется в `error_log()` (stderr),
 * как SQL движка.
 */
abstract class MemoryForeignDataSource extends ForeignDataSource implements SelectForeignDataInterface
{
    /** @var list<array<string, mixed>> */
    protected array $rows;

    public function __construct()
    {
        $this->rows = $this->seed();
    }

    /**
     * Начальные строки источника.
     *
     * @return list<array<string, mixed>>
     */
    abstract protected function seed(): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function getRows(): array
    {
        return $this->rows;
    }

    #[\Override]
    public function select(array $columns, array $conditions, array $order, array $arguments): iterable
    {
        $this->log(method: 'select', arguments: [
            'columns' => $columns,
            'conditions' => array_map(
                static fn(array $condition): array => [...$condition, 'operator' => $condition['operator']->value],
                $conditions,
            ),
            'order' => $order,
            'arguments' => $arguments,
        ]);
        if ($order === []) {
            $order = array_map(
                static fn(string $column): array => ['column' => $column, 'descending' => false],
                static::getReadOrientation() ?? [],
            );
        }
        $rows = array_values(array_filter(
            $this->rows,
            static fn(array $row): bool => self::matches(row: $row, conditions: $conditions, arguments: $arguments),
        ));
        usort(
            $rows,
            static fn(array $left_row, array $right_row): int => self::compare(
                left_row: $left_row,
                right_row: $right_row,
                order: $order,
            ),
        );

        return array_map(
            static fn(array $row): array => array_intersect_key($row, array_flip($columns)),
            $rows,
        );
    }

    /**
     * @param array<string, mixed>                                          $row
     * @param list<array{column: string, operator: Operator, value: mixed}> $conditions
     * @param array<string, mixed>                                          $arguments
     */
    private static function matches(array $row, array $conditions, array $arguments): bool
    {
        foreach (array_intersect_key($arguments, static::COLUMNS) as $column => $value) {
            if ($row[$column] != $value) {
                return false;
            }
        }
        foreach ($conditions as $condition) {
            $actual = $row[$condition['column']];
            $expected = $condition['value'];
            if ($condition['operator'] === Operator::In) {
                if (!in_array($actual, array_map(self::getScalar(...), (array) $expected), false)) {
                    return false;
                }
                continue;
            }
            $order = $actual <=> self::getScalar(value: $expected);
            $passed = match ($condition['operator']) {
                Operator::Eq => $order === 0,
                Operator::Ne => $order !== 0,
                Operator::Lt => $order < 0,
                Operator::Gt => $order > 0,
                Operator::Lte => $order <= 0,
                Operator::Gte => $order >= 0,
            };
            if (!$passed) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed>                          $left_row
     * @param array<string, mixed>                          $right_row
     * @param list<array{column: string, descending: bool}> $order
     */
    private static function compare(array $left_row, array $right_row, array $order): int
    {
        foreach ($order as $entry) {
            $result = $left_row[$entry['column']] <=> $right_row[$entry['column']];
            if ($result !== 0) {
                return $entry['descending'] ? -$result : $result;
            }
        }

        return 0;
    }

    /**
     * Значение условия в том виде, в каком оно лежит в строках: дата — текстом.
     */
    private static function getScalar(mixed $value): mixed
    {
        return $value instanceof DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    protected function log(string $method, array $arguments): void
    {
        error_log(shortClassName(class: static::class) . "::{$method}(" . compactJson($arguments) . ')');
    }
}

/**
 * Источник в памяти с записью: к чтению `MemoryForeignDataSource` добавляет `insert()`, `update()` и `delete()`.
 *
 * Аргументы маршрута, совпавшие по имени с колонкой, — значение колонки при вставке и условие равенства при изменении
 * и удалении.
 */
abstract class WritableMemoryForeignDataSource extends MemoryForeignDataSource implements InsertForeignDataInterface, UpdateForeignDataInterface, DeleteForeignDataInterface
{
    #[\Override]
    public function insert(array $row, array $arguments): array
    {
        $this->log(method: 'insert', arguments: ['row' => $row, 'arguments' => $arguments]);
        $primary = static::getPrimaryKeyColumnName();
        $row = [
            ...array_fill_keys(array_keys(static::COLUMNS), null),
            ...$row,
            ...array_intersect_key($arguments, static::COLUMNS),
        ];
        $row[$primary] ??= max([0, ...array_column($this->rows, $primary)]) + 1;
        $this->rows[] = $row;

        return $row;
    }

    #[\Override]
    public function update(array $key, array $row, array $arguments): array
    {
        $this->log(method: 'update', arguments: ['key' => $key, 'row' => $row, 'arguments' => $arguments]);
        $index = $this->find(key: $key, arguments: $arguments);
        if ($index === null) {
            return [];
        }
        $this->rows[$index] = [...$this->rows[$index], ...$row];

        return [...$row, ...$key];
    }

    #[\Override]
    public function delete(array $key, array $arguments): array
    {
        $this->log(method: 'delete', arguments: ['key' => $key, 'arguments' => $arguments]);
        $index = $this->find(key: $key, arguments: $arguments);
        if ($index === null) {
            return [];
        }
        unset($this->rows[$index]);
        $this->rows = array_values($this->rows);

        return $key;
    }

    /**
     * Номер строки с такими ключами (и аргументами маршрута) или null.
     *
     * @param array<string, mixed> $key
     * @param array<string, mixed> $arguments
     */
    private function find(array $key, array $arguments): ?int
    {
        $expected = [...array_intersect_key($arguments, static::COLUMNS), ...$key];
        foreach ($this->rows as $index => $row) {
            if (array_intersect_key($row, $expected) == $expected) {
                return $index;
            }
        }

        return null;
    }
}

// =====================================================================================================================
// Источники и ресурсы примера
// =====================================================================================================================

/**
 * Заметки к сертификатам, 1:N от `certificates`; читаются и пишутся. Заметка 5 ссылается на сертификат, которого в
 * базе нет.
 */
final class NotesSource extends WritableMemoryForeignDataSource
{
    public const string ID = 'id';
    public const string CERTIFICATE_ID = 'certificate_id';
    public const string TEXT = 'text';
    public const string PINNED = 'pinned';
    public const string CREATED_AT = 'created_at';

    public const array COLUMNS = [
        self::ID => ['type' => self::INT, 'constraint' => self::PRIMARY],
        self::CERTIFICATE_ID => ['type' => self::INT],
        self::TEXT => ['type' => self::STRING],
        self::PINNED => ['type' => self::BOOL],
        self::CREATED_AT => ['type' => self::DATETIME, 'nullable' => true],
    ];

    #[\Override]
    public static function getReadOrientation(): array
    {
        return [self::CERTIFICATE_ID];
    }

    #[\Override]
    protected function seed(): array
    {
        return [
            self::getNote(id: 1, certificate_id: 1, text: 'Продлить', pinned: true, created_at: '2026-03-01 10:00:00'),
            self::getNote(id: 2, certificate_id: 1, text: 'Повтор', pinned: false, created_at: '2026-03-05 12:30:00'),
            self::getNote(id: 3, certificate_id: 3, text: 'Домены', pinned: false, created_at: '2026-04-11 09:15:00'),
            self::getNote(id: 4, certificate_id: 4, text: 'VIP', pinned: true, created_at: '2026-05-20 18:45:00'),
            self::getNote(id: 5, certificate_id: 999, text: 'Сирота', pinned: true, created_at: '2026-06-02 08:00:00'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function getNote(int $id, int $certificate_id, string $text, bool $pinned, string $created_at): array
    {
        return [
            self::ID => $id,
            self::CERTIFICATE_ID => $certificate_id,
            self::TEXT => $text,
            self::PINNED => $pinned,
            self::CREATED_AT => $created_at,
        ];
    }
}

/**
 * Оценка сертификата, обратная 1:1 от `certificates`: первичный ключ — `certificate_id`. Только для чтения.
 */
final class RatingsSource extends MemoryForeignDataSource
{
    public const string CERTIFICATE_ID = 'certificate_id';
    public const string SCORE = 'score';
    public const string CHECKED_AT = 'checked_at';

    public const array COLUMNS = [
        self::CERTIFICATE_ID => ['type' => self::INT, 'constraint' => self::PRIMARY],
        self::SCORE => ['type' => self::INT],
        self::CHECKED_AT => ['type' => self::DATETIME],
    ];

    #[\Override]
    public static function getReadOrientation(): array
    {
        return [self::CERTIFICATE_ID];
    }

    #[\Override]
    protected function seed(): array
    {
        return [
            ['certificate_id' => 1, 'score' => 72, 'checked_at' => '2026-02-01 00:00:00'],
            ['certificate_id' => 4, 'score' => 95, 'checked_at' => '2026-02-03 00:00:00'],
            ['certificate_id' => 999, 'score' => 10, 'checked_at' => '2026-02-04 00:00:00'],
        ];
    }
}

/**
 * Поставщики из внешней системы, только для чтения; `certificates.provider_id` хранит их id. У поставщика 5
 * сертификатов нет.
 */
final class VendorsSource extends MemoryForeignDataSource
{
    public const string ID = 'id';
    public const string NAME = 'name';
    public const string COUNTRY = 'country';

    public const array COLUMNS = [
        self::ID => ['type' => self::INT, 'constraint' => self::PRIMARY],
        self::NAME => ['type' => self::STRING],
        self::COUNTRY => ['type' => self::STRING],
    ];

    #[\Override]
    public static function getReadOrientation(): array
    {
        return [self::ID];
    }

    #[\Override]
    protected function seed(): array
    {
        return [
            ['id' => 1, 'name' => 'GiftCard Pro', 'country' => 'LV'],
            ['id' => 2, 'name' => 'Подарочный Дом', 'country' => 'LV'],
            ['id' => 3, 'name' => 'CertExpress', 'country' => 'US'],
            ['id' => 4, 'name' => 'Бонус Плюс', 'country' => 'EE'],
            ['id' => 5, 'name' => 'Empty Vendor', 'country' => 'LV'],
        ];
    }
}

/**
 * `note`: ресурс над источником `NotesSource`. Создаётся, изменяется и удаляется — все операции уходят в источник.
 */
final class NoteResource extends Resource implements InsertableInterface, UpdatableInterface, DeletableInterface
{
    public const string TYPE = 'note';
    public const string ID = 'id';
    public const string TEXT = 'text';
    public const string PINNED = 'pinned';
    public const string CREATED_AT = 'created_at';

    public const array ATTRIBUTES = [
        self::ID => ['column' => NotesSource::ID, 'type' => self::INT, 'constraint' => self::PRIMARY],
        self::TEXT => ['column' => NotesSource::TEXT, 'type' => self::STRING],
        self::PINNED => ['column' => NotesSource::PINNED, 'type' => self::BOOL, 'filter' => [Operator::Eq]],
        self::CREATED_AT => [
            'column' => NotesSource::CREATED_AT,
            'type' => self::DATETIME,
            'nullable' => true,
            'filter' => [Operator::Gte],
            'sort' => true,
        ],
    ];

    #[\Override]
    public static function getRelationships(): array
    {
        return [];
    }

    public static function getModel(): string
    {
        return NotesSource::class;
    }
}

/**
 * `rating`: ресурс над источником `RatingsSource`, id — id сертификата.
 */
final class RatingResource extends Resource
{
    public const string TYPE = 'rating';
    public const string ID = 'id';
    public const string SCORE = 'score';
    public const string CHECKED_AT = 'checked_at';

    public const array ATTRIBUTES = [
        self::ID => ['column' => RatingsSource::CERTIFICATE_ID, 'type' => self::INT, 'constraint' => self::PRIMARY],
        self::SCORE => ['column' => RatingsSource::SCORE, 'type' => self::INT, 'filter' => [Operator::Gte]],
        self::CHECKED_AT => ['column' => RatingsSource::CHECKED_AT, 'type' => self::DATETIME],
    ];

    #[\Override]
    public static function getRelationships(): array
    {
        return [];
    }

    public static function getModel(): string
    {
        return RatingsSource::class;
    }
}

/**
 * `vendor`: ресурс над источником `VendorsSource`; его дети `certificates` лежат в базе.
 */
final class VendorResource extends Resource
{
    public const string TYPE = 'vendor';
    public const string ID = 'id';
    public const string NAME = 'name';
    public const string COUNTRY = 'country';
    public const string CERTIFICATES = 'certificates';

    public const array ATTRIBUTES = [
        self::ID => ['column' => VendorsSource::ID, 'type' => self::INT, 'constraint' => self::PRIMARY],
        self::NAME => ['column' => VendorsSource::NAME, 'type' => self::STRING],
        self::COUNTRY => ['column' => VendorsSource::COUNTRY, 'type' => self::STRING, 'filter' => [Operator::Eq]],
    ];

    #[\Override]
    public static function getRelationships(): array
    {
        return [
            self::CERTIFICATES => new OneToMany(
                resource: CertificateCardResource::class,
                relation: [VendorsSource::ID, CertificatesEntity::PROVIDER_ID],
            ),
        ];
    }

    public static function getModel(): string
    {
        return VendorsSource::class;
    }
}

/**
 * `certificate` прототипа: таблица `certificates` со связями на источники — `vendor` (N:1, внешний ключ), `rating`
 * (обратная 1:1), `notes` (1:N) — и на таблицу `domains` (1:N).
 */
final class CertificateCardResource extends Resource implements InsertableInterface
{
    public const string TYPE = 'certificate';
    public const string ID = 'id';
    public const string CODE = 'code';
    public const string NOMINAL = 'nominal';
    public const string STATUS = 'status';
    public const string VENDOR = 'vendor';
    public const string RATING = 'rating';
    public const string NOTES = 'notes';
    public const string DOMAINS = 'domains';

    public const array ATTRIBUTES = [
        self::ID => ['column' => CertificatesEntity::ID, 'type' => self::INT, 'constraint' => self::PRIMARY],
        self::CODE => [
            'column' => CertificatesEntity::CODE,
            'type' => self::STRING,
            'filter' => [Operator::Eq],
        ],
        self::NOMINAL => ['column' => CertificatesEntity::NOMINAL, 'type' => self::STRING],
        self::STATUS => ['column' => CertificatesEntity::STATUS, 'type' => self::STRING, 'filter' => [Operator::Eq]],
    ];

    #[\Override]
    public static function getRelationships(): array
    {
        return [
            self::VENDOR => new ManyToOne(
                resource: VendorResource::class,
                relation: [CertificatesEntity::PROVIDER_ID, VendorsSource::ID],
            ),
            self::RATING => new OneToOne(
                resource: RatingResource::class,
                relation: [CertificatesEntity::ID, RatingsSource::CERTIFICATE_ID],
            ),
            self::NOTES => new OneToMany(
                resource: NoteResource::class,
                relation: [CertificatesEntity::ID, NotesSource::CERTIFICATE_ID],
            ),
            self::DOMAINS => new OneToMany(
                resource: DomainResource::class,
                relation: [CertificatesEntity::ID, DomainsEntity::CERTIFICATE_ID],
            ),
        ];
    }

    public static function getModel(): string
    {
        return CertificatesEntity::class;
    }
}

// =====================================================================================================================
// Печать и сценарии
// =====================================================================================================================

function shortClassName(string $class): string
{
    return substr((string) strrchr('\\' . $class, '\\'), 1);
}

function compactJson(mixed $value): string
{
    return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function json(mixed $value): string
{
    return (string) json_encode(
        $value,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    );
}

/**
 * Дерево описания (`explain()`) без пространств имён и в компактном виде.
 *
 * @param array<string, mixed> $explain
 */
function executionTree(array $explain, string $indent = ''): string
{
    $label = shortClassName(class: (string) $explain['node']);
    $inputs = [];
    foreach ($explain as $name => $value) {
        if ($name === 'node') {
            continue;
        }
        if (is_array($value) && isset($value['node'])) {
            $inputs[$name] = $value;
            continue;
        }
        $label .= " {$name}=" . (is_string($value) ? shortClassName(class: $value) : compactJson($value));
    }
    $output = "{$indent}{$label}\n";
    foreach ($inputs as $name => $input) {
        $output .= "{$indent}  {$name}:\n" . executionTree(explain: $input, indent: "{$indent}    ");
    }

    return $output;
}

/**
 * SQL каждого запроса плана сверху вниз; у источника SQL нет — он помечен строкой.
 */
function queries(ResourceNode|EntityScanNodeDraft|EntityScanNode|ForeignDataScanNode|JoinNode $node): string
{
    if ($node instanceof JoinNode) {
        return queries(node: $node->outer) . queries(node: $node->inner);
    }
    if ($node instanceof EntityScanNode) {
        $sql = (string) preg_replace('/\s+/', ' ', $node->select->dispatch(new SqlBuilderWalker()));

        return "  sql: {$sql}\n" . ($node->parameters === [] ? '' : '       ' . compactJson($node->parameters) . "\n");
    }
    if ($node instanceof ForeignDataScanNode) {
        return '  source: ' . shortClassName(class: $node->getDataSource()) . "\n";
    }

    return '';
}

/**
 * Контейнер с новыми объектами источников примера — по набору на сценарий — и объектами ресурсов: сборщики берут
 * из него и те, и другие по имени класса.
 */
function container(): ContainerInterface
{
    return new ArrayContainer(
        services: [
            new NotesSource(),
            new RatingsSource(),
            new VendorsSource(),
            ...ExampleResources::getAll(),
            new NoteResource(),
            new RatingResource(),
            new VendorResource(),
            new CertificateCardResource(),
        ],
    );
}

/**
 * Сценарий выборки: URL → дерево запроса → план → документ; вызовы источников — в stderr.
 *
 * @param class-string<Resource> $resource корневой ресурс
 * @param array<string, mixed>   $arguments аргументы маршрута
 */
function select(PDO $pdo, string $title, string $resource, string $url, array $arguments = []): void
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    echo "\n######## {$title}\nGET {$url}", $arguments === [] ? '' : '   arguments: ' . compactJson($arguments), "\n\n";

    $tree = new QueryParser()->parse(resource: $resource, query: $query);
    $plan = new SelectPlanner()->plan(tree: $tree)['sql'];
    echo "#### plan\n", SelectPlanPrinter::getTree(node: $plan), "\n#### queries\n", queries(node: $plan), "\n";

    $builder = new ReaderBuilder(
        pdo: $pdo,
        container: container(),
    );
    $document = json($builder->getDocument(plan: $plan, arguments: $arguments));
    echo "#### document\n{$document}\n";
}

/**
 * Сценарий записи: дерево операций → план → дерево исполнителей → состояние источника; вызовы источников — в stderr.
 *
 * @param class-string<Resource>     $resource корневой ресурс
 * @param list<WriteOperation>       $roots    корни дерева операций (от `WriteDocumentParser`)
 * @param array<string, mixed>       $arguments аргументы маршрута
 * @param class-string<MemoryForeignDataSource> $source источник, состояние которого печатается
 */
function write(PDO $pdo, string $title, string $resource, array $roots, array $arguments, string $source): void
{
    echo "\n######## {$title}", $arguments === [] ? '' : '   arguments: ' . compactJson($arguments), "\n\n";
    $plan = new WritePlanner()->plan(resource: $resource, roots: $roots);
    $container = container();
    /** @var MemoryForeignDataSource $state */
    $state = $container->get(id: $source);
    $builder = new WriterBuilder(
        pdo: $pdo,
        lock: false,
        container: $container,
    );
    $writer = $builder->getRoot(plan: $plan, data: $roots, arguments: $arguments);
    echo "#### execution tree\n", executionTree(explain: $writer->explain()), "\n";

    $before = $state->getRows();
    $pdo->beginTransaction();
    try {
        echo "#### keys\n";
        foreach ($writer->execute() as $keys) {
            echo '  ', compactJson($keys), "\n";
        }
    } catch (WriteNotFoundException $exception) {
        echo "#### response\n", json(['errors' => [$exception->toError()]]), "\n";
    } finally {
        // База откатывается; источник в транзакции не участвует — в каждом сценарии источники создаются заново
        $pdo->rollBack();
    }
    echo "\n#### ", shortClassName(class: $source), ": rows before → after\n";
    foreach (stateChanges(before: $before, after: $state->getRows(), primary: $source::getPrimaryKeyColumnName()) as $line) {
        echo "  {$line}\n";
    }
}

/**
 * Отличия двух состояний источника построчно.
 *
 * @param list<array<string, mixed>> $before
 * @param list<array<string, mixed>> $after
 * @return list<string>
 */
function stateChanges(array $before, array $after, string $primary): array
{
    $before = array_column($before, null, $primary);
    $after = array_column($after, null, $primary);
    $lines = [];
    foreach ($before + $after as $id => $ignored_row) {
        $old = $before[$id] ?? null;
        $new = $after[$id] ?? null;
        if ($old === $new) {
            continue;
        }
        $lines[] = ($old === null ? '(нет)' : compactJson($old))
            . ' → ' . ($new === null ? '(нет)' : compactJson($new));
    }

    return $lines === [] ? ['без изменений'] : $lines;
}

// Файл подключён другим примером (`require` из `user-profile.php`): нужны только классы и функции прототипа, сценарии
// ниже не запускаются
if (realpath((string) $_SERVER['SCRIPT_FILENAME']) !== __FILE__) {
    return;
}

// ---------------------------------------------------------------------------------------------------------------------
// Описания прототипа проходят те же проверки, что и обычные: источник — сущность, ресурс над ним — обычный ресурс

foreach ([NotesSource::class, RatingsSource::class, VendorsSource::class] as $source_class) {
    $source_class::validate();
}
// CertificateCardResource не проверяется: validate() ресурса с OneToOne сейчас падает (известная ошибка
// OneToOne::$filter в src/Resource.php, с прототипом не связана)
foreach ([NoteResource::class, RatingResource::class, VendorResource::class] as $resource_class) {
    $resource_class::validate();
}

$pdo = \getPDO();

// ---------------------------------------------------------------------------------------------------------------------
// Выборка

// 1. Источник — корень: условие и сортировка клиента уходят в select() в колонках
select(
    pdo: $pdo,
    title: '1. source as the root',
    resource: NoteResource::class,
    url: '/notes?filter[pinned]=true&sort=-created_at',
);

// 2. Вложенный маршрут: id сертификата — аргумент маршрута, его применяет сам источник
select(
    pdo: $pdo,
    title: '2. source as the root, route argument',
    resource: NoteResource::class,
    url: '/certificates/3/notes',
    arguments: ['certificate_id' => 3],
);

// 3. Таблица — корень, источники вокруг: vendor (N:1, справочник целиком), rating (1:1 — вынесен из строки,
//    HashOneJoinNode), notes (1:N, слияние: оба потока по ключу), domains (1:N, таблица, CTE родителя как раньше).
//    Источники читают всё, что прошло условия клиента; лишнее (заметка и оценка сертификата 999) отбрасывает
//    соединение
select(
    pdo: $pdo,
    title: '3. table as the root, sources around it',
    resource: CertificateCardResource::class,
    url: '/certificates?include=vendor,rating,notes,domains&filter[code]=CERT-2026-00001'
        . '&fields[certificate]=code,vendor&fields[domain]=domain_name&fields[note]=text&fields[vendor]=name',
);

// 4. Источник — родитель таблицы: у запроса сертификатов нет CTE родителя, только условие клиента (status);
//    сертификаты поставщиков, не прошедших фильтр country, отбрасывает соединение
select(
    pdo: $pdo,
    title: '4. source as the parent of a table',
    resource: VendorResource::class,
    url: '/vendors?include=certificates&filter[country]=LV&filter[certificates.status]=ACTIVE'
        . '&fields[certificate]=code,status',
);

// ---------------------------------------------------------------------------------------------------------------------
// Запись

// 5. POST /certificates/3/notes: вставка в источник, id сертификата — аргумент маршрута
write(
    pdo: $pdo,
    title: '5. POST /certificates/3/notes',
    resource: NoteResource::class,
    roots: new WriteDocumentParser()->insert(
        document: ['data' => ['type' => 'note', 'attributes' => ['text' => 'Новая заметка', 'pinned' => false]]],
        resource: NoteResource::class,
    ),
    arguments: ['certificate_id' => 3],
    source: NotesSource::class,
);

// 6. PATCH /notes/2
write(
    pdo: $pdo,
    title: '6. PATCH /notes/2',
    resource: NoteResource::class,
    roots: [
        new WriteDocumentParser()->update(
            document: ['data' => ['type' => 'note', 'id' => '2', 'attributes' => ['pinned' => true]]],
            resource: NoteResource::class,
            id: '2',
        ),
    ],
    arguments: [],
    source: NotesSource::class,
);

// 7. PATCH /certificates/3/notes/2: заметка 2 принадлежит сертификату 1 — источник её не находит, движок отвечает 404
write(
    pdo: $pdo,
    title: '7. PATCH /certificates/3/notes/2 (foreign note)',
    resource: NoteResource::class,
    roots: [
        new WriteDocumentParser()->update(
            document: ['data' => ['type' => 'note', 'id' => '2', 'attributes' => ['pinned' => true]]],
            resource: NoteResource::class,
            id: '2',
        ),
    ],
    arguments: ['certificate_id' => 3],
    source: NotesSource::class,
);

// 8. DELETE /notes/4
write(
    pdo: $pdo,
    title: '8. DELETE /notes/4',
    resource: NoteResource::class,
    roots: [new WriteDocumentParser()->delete(resource: NoteResource::class, id: '4')],
    arguments: [],
    source: NotesSource::class,
);

// 9. POST /certificates с заметкой в included: сертификат пишется в таблицу (INSERT в stderr), заметка — в источник,
//    ключ нового сертификата приходит ей колонкой связи. База откатывается, источник — нет
write(
    pdo: $pdo,
    title: '9. POST /certificates with a note in included (table parent, source child)',
    resource: CertificateCardResource::class,
    roots: new WriteDocumentParser()->insert(
        document: [
            'data' => [
                'type' => 'certificate',
                'attributes' => ['code' => 'CERT-SOURCE-1', 'nominal' => '100.00'],
                'relationships' => [
                    'vendor' => ['data' => ['type' => 'vendor', 'id' => '1']],
                    'notes' => ['data' => [['type' => 'note', 'lid' => 'n1']]],
                ],
            ],
            'included' => [
                [
                    'type' => 'note',
                    'lid' => 'n1',
                    'attributes' => ['text' => 'Создана вместе с сертификатом', 'pinned' => true],
                ],
            ],
        ],
        resource: CertificateCardResource::class,
    ),
    arguments: [],
    source: NotesSource::class,
);

// 10. POST /ratings: источник оценок только для чтения (один SelectForeignDataInterface), ресурс rating не InsertableInterface — парсер
//     отклоняет документ до плана и до источника
echo "\n######## 10. POST /ratings (read-only source)\n\n";
try {
    new WriteDocumentParser()->insert(
        document: ['data' => ['type' => 'rating', 'attributes' => ['score' => 50]]],
        resource: RatingResource::class,
    );
} catch (InvalidDocumentException $exception) {
    echo "#### response\n", json(['errors' => [$exception->toError()]]), "\n";
}
