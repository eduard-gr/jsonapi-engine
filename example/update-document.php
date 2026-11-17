<?php

/**
 * Прототип UPDATE: дерево исполнителей (push), собранное руками, для `PATCH /certificates/{id}` над
 * `CertificateWithDetailResource` (`LeftJoin(certificates, certificate_details)`). Парсера документа PATCH
 * и планировщика ещё нет: ресурс из документа подаётся в дерево как есть. Весь прототип — в этом файле, `src/`
 * не меняется; из `src/` взяты контракт `TableWriter`, `InsertTableWriter` (вставка отсутствующей необязательной
 * части модели) и `ResourceNotFoundException` (404). Прототип заменён записью в `src/` (`Parser\Write`,
 * `Plan\Write`, `Executor\Write`, см. example/patch-document.php) и оставлен как история.
 *
 *     php example/update-document.php            # дерево (explain), по каждому сценарию ключи и строка после UPDATE;
 *                                                # SQL каждого запроса — в stderr
 *     php example/update-document.php 2>sql.log  # SQL отдельно
 *
 * Дерево — как у вставки, два уровня:
 *  - уровень ресурсов: `UpdateRootWriter` → `UpdateResourceWriter` (ресурс → строка «колонка модели → значение»
 *    и ключ: id ресурса — колонка первичного ключа главной таблицы);
 *  - уровень таблиц (контракт `TableWriter`): `UpdateTableJoinWriter(relation, main, joined)` — сначала сторона
 *    с главной таблицей (её ключ известен из id ресурса), потом присоединённая по ключу, который вернула первая;
 *    `UpdateTableWriter` — `UPDATE … SET <присланные колонки> WHERE <ключ> = :… AND (<колонка> IS DISTINCT FROM …
 *    OR …) RETURNING …`: неизменённые значения не перезаписываются; если ни одна колонка таблицы не прислана или
 *    все значения уже такие же — `SELECT` тех же колонок (ключи для присоединённых таблиц и проверка, что строка
 *    есть);
 *    `UpdateOptionalTableWriter` — правая сторона `LeftJoin`: без её колонок в строке пропускается, а если строки
 *    нет — вставляется (`InsertTableWriter`), как отсутствующая часть модели.
 *
 * Порядок сторон отличается от вставки: там первым идёт родитель по ключу связи (тот, чей первичный ключ нужен
 * другому), здесь — сторона, ключ которой уже известен (главная таблица ресурса), независимо от направления
 * внешнего ключа. Для `certificates → certificate_details` это совпадает; для модели, где главная таблица ссылается
 * на присоединённую (`vouchers.vendor_id → vendors.id`), первой обновится главная, а `vendor_id` для второй придёт
 * из её `RETURNING`.
 *
 * Сценарии (каждый — в своей транзакции, которая откатывается: база не меняется):
 *  1. обе таблицы: `nominal` (certificates) и `buyer_email` (certificate_details);
 *  2. только связь по внешнему ключу `provider`: `certificate_details` пропускается;
 *  3. сертификат без строки деталей (вставлен в той же транзакции): certificates — только `SELECT` (проверка, что он
 *     есть), certificate_details — `UPDATE` не нашёл строку, деталь вставляется;
 *  4. те же значения, что уже записаны: `UPDATE … AND (… IS DISTINCT FROM …)` строку не трогает, `SELECT` находит
 *     её — изменений нет, но и не 404;
 *  5. несуществующий id — `ResourceNotFoundException` (404).
 *
 * Не сделано (вопросы для следующих шагов): парсер документа PATCH (обязательный `id`, только известные атрибуты,
 * `mode` READ), планировщик и `Updatable` на ресурсе; связи to-one/to-many в PATCH (замена набора детей); `InnerJoin`
 * без строки на присоединённой стороне — сейчас
 * молча ничего не обновляет; ответ PATCH через SELECT.
 */

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Example\UpdatePrototype;

use Ggs\JsonApiEngine\Example\Entity\CertificateDetailsEntity;
use Ggs\JsonApiEngine\Example\Entity\CertificatesEntity;
use Ggs\JsonApiEngine\Example\Resource\CertificateWithDetailResource;
use Ggs\JsonApiEngine\Executor\Insert\InsertTableWriter;
use Ggs\JsonApiEngine\Executor\TableWriter;
use Ggs\JsonApiEngine\Model\Entity;
use Ggs\JsonApiEngine\Parser\ResourceNotFoundException;
use Ggs\JsonApiEngine\Resource;
use LogicException;
use PDO;
use sad_spirit\pg_builder\nodes\ColumnReference;
use sad_spirit\pg_builder\enums\LogicalOperator;
use sad_spirit\pg_builder\nodes\expressions\IsDistinctFromExpression;
use sad_spirit\pg_builder\nodes\expressions\LogicalExpression;
use sad_spirit\pg_builder\nodes\expressions\NamedParameter;
use sad_spirit\pg_builder\nodes\expressions\OperatorExpression;
use sad_spirit\pg_builder\nodes\lists\SetClauseList;
use sad_spirit\pg_builder\nodes\lists\TargetList;
use sad_spirit\pg_builder\nodes\QualifiedName;
use sad_spirit\pg_builder\nodes\range\RelationReference;
use sad_spirit\pg_builder\nodes\range\UpdateOrDeleteTarget;
use sad_spirit\pg_builder\nodes\SetTargetElement;
use sad_spirit\pg_builder\nodes\SingleSetClause;
use sad_spirit\pg_builder\nodes\TargetElement;
use sad_spirit\pg_builder\Select;
use sad_spirit\pg_builder\SqlBuilderWalker;
use sad_spirit\pg_builder\Update;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/db/connection.php';

/**
 * Контракт исполнителей уровня ресурсов этого прототипа: ресурс документа массивом + колонки связи → ключи. Таким был
 * `Executor\PushExecutor` из `src/`, пока запись не перешла на дерево операций (`push(WriteOperation, link)`).
 *
 * @temporary Только для этого прототипа: он заменён записью в `src/` (см. example/patch-document.php).
 */
interface PushExecutor
{
    /**
     * @param array<string, mixed> $link
     * @return array<string, mixed>
     */
    public function push(array $resource, array $link = []): array;

    /**
     * @return array<string, mixed>
     */
    public function explain(): array;
}

/**
 * Корень дерева обновления: отправляет каждый ресурс документа в дерево и собирает их ключи. Сам не PushExecutor:
 * сверху ему ничего не приходит, его запускают execute(). Как InsertRootWriter; у PATCH ресурс один, список — на
 * будущее (тот же контракт, что у вставки).
 *
 * Поток: list<ресурс> → root->push(ресурс) по очереди → list<ключи ресурса>.
 *
 * Транзакцию не открывает: несколько таблиц обновляются атомарно, только если вызывающий обернул execute()
 * в транзакцию.
 */
final readonly class UpdateRootWriter
{
    /**
     * @param list<array> $data ресурсы `data` документа PATCH (`type`, `id`, `attributes`, `relationships`)
     */
    public function __construct(
        private PushExecutor $root,
        private array $data,
    ) {}

    /**
     * @return list<array<string, mixed>>
     * @throws ResourceNotFoundException если ресурса с таким id нет
     */
    public function execute(): array
    {
        return array_map(fn(array $resource): array => $this->root->push($resource), $this->data);
    }

    /**
     * @return array<string, mixed>
     */
    public function explain(): array
    {
        return [
            'node' => static::class,
            'data' => count($this->data),
            'root' => $this->root->explain(),
        ];
    }
}

/**
 * Граница уровней дерева обновления: ресурс JSON:API → строка «колонка модели → значение» и ключ → поддерево таблиц.
 * Пара к InsertResourceWriter: строка собирается так же (атрибуты по `ATTRIBUTES[...]['column']`, составной — через
 * `deserialize<Атрибут>()`, связь по внешнему ключу `{type, id}` → колонка, `null` → NULL), но в строку идут только
 * присланные атрибуты, а id ресурса уходит вниз колонкой связи `[первичный ключ => id]`: по нему главная таблица
 * находит свою строку.
 *
 * Поток: ресурс + входящий `$link` → `input->push(строка, link + [primaryColumn => id])` → ключи; пустые ключи
 * (главная таблица строку не нашла) — ResourceNotFoundException (404).
 *
 * Остальные связи (to-one/to-many по первичному ключу владельца) не обрабатывает: замена набора связанных ресурсов
 * в PATCH — следующий шаг. Вход не проверен (парсера PATCH нет): другой `type`, нет `id`, неизвестный атрибут —
 * LogicException.
 */
final readonly class UpdateResourceWriter implements PushExecutor
{
    /**
     * @param class-string<Resource> $resource
     */
    public function __construct(
        private string $resource,
        private TableWriter $input,
    ) {}

    #[\Override]
    public function push(array $resource, array $link = []): array
    {
        $class = $this->resource;
        if (($resource['type'] ?? null) !== $class::type()) {
            throw new LogicException("Expected {$class::type()}, got " . (string) ($resource['type'] ?? ''));
        }
        $id = (string) ($resource['id'] ?? throw new LogicException("{$class}: PATCH needs id"));
        /** @var array<string, mixed> $attributes */
        $attributes = $resource['attributes'] ?? [];
        /** @var array<string, array{data: mixed}> $relationships */
        $relationships = $resource['relationships'] ?? [];

        $keys = $this->input->push(
            [...$this->attributes(attributes: $attributes), ...$this->foreignKeys(relationships: $relationships)],
            [...$link, $class::primaryColumn() => (int) $id],
        );
        if ($keys === []) {
            throw new ResourceNotFoundException(
                type: $class::type(),
                parameter: 'id',
                value: $id,
            );
        }

        return $keys;
    }

    /**
     * @param array<string, mixed> $attributes имя в API → значение
     * @return array<string, mixed> колонка модели → значение
     */
    private function attributes(array $attributes): array
    {
        $class = $this->resource;
        $row = [];
        foreach ($attributes as $name => $value) {
            /** @var array<string, mixed> $attribute */
            $attribute = $class::ATTRIBUTES[$name] ?? throw new LogicException("{$class}: unknown attribute {$name}");
            if (isset($attribute['columns'])) {
                $method = 'deserialize' . str_replace('_', '', ucwords((string) $name, '_'));
                if (!method_exists($class, $method)) {
                    throw new LogicException("{$class}: composite attribute {$name} needs {$method}()");
                }
                /** @var array<string, mixed> $columns */
                $columns = $class::$method($value);
                $row = [...$row, ...$columns];
                continue;
            }
            $row[(string) ($attribute['column'] ?? $name)] = $value;
        }

        return $row;
    }

    /**
     * @param array<string, array{data: mixed}> $relationships
     * @return array<string, int|null> колонка модели → значение
     */
    private function foreignKeys(array $relationships): array
    {
        $class = $this->resource;
        $row = [];
        foreach ($relationships as $name => $relationship) {
            $foreign = $class::foreignRelationship((string) $name);
            if ($foreign === null) {
                throw new LogicException("{$class}: relationship {$name} is not a foreign key, PATCH of it is not supported yet");
            }
            $data = $relationship['data'];
            $row[$foreign->relation[0]] = $data === null
                ? null
                : (int) ($data['id'] ?? throw new LogicException("{$class}: {$name} needs an existing id"));
        }

        return $row;
    }

    #[\Override]
    public function explain(): array
    {
        return [
            'node' => static::class,
            'resource' => $this->resource,
            'input' => $this->input->explain(),
        ];
    }
}

/**
 * Соединение двух частей модели при обновлении: сначала сторона с главной таблицей (`main`, её ключ известен),
 * потом присоединённая (`joined`) — по ключу, который вернула первая. Строится по `LeftJoin`/`InnerJoin` модели.
 *
 * Поток: строка + `$link` → `main->push()` → ключи; пусто — строки нет, дальше не идёт (наверху это 404) →
 * `joined->push(строка, link + [relation[1] => keys[relation[0]]])` → объединённые ключи обеих сторон.
 *
 * `relation` ориентирован по порядку обновления: [колонка стороны main, колонка стороны joined] — в отличие
 * от InsertTableJoinWriter, где первым идёт родитель по внешнему ключу. Колонка relation[0] должна быть в ключах
 * main: либо её ключ (WHERE), либо в её `returning`.
 *
 * Ограничение: пустой ответ joined (строки нет, необязательная часть пропущена) не проверяется — у `InnerJoin` это
 * значит рассогласование данных, и сейчас оно молча проходит.
 */
final readonly class UpdateTableJoinWriter implements TableWriter
{
    /**
     * @param array{0: string, 1: string} $relation [колонка стороны main, колонка стороны joined]
     */
    public function __construct(
        private array $relation,
        private TableWriter $main,
        private TableWriter $joined,
    ) {}

    #[\Override]
    public function push(array $row, array $link = []): array
    {
        $main_keys = $this->main->push($row, $link);
        if ($main_keys === []) {
            return [];
        }

        $joined_link = [
            ...$link,
            $this->relation[1] => $main_keys[$this->relation[0]]
                ?? throw new LogicException("No key {$this->relation[0]} after update of the main side"),
        ];

        return [...$main_keys, ...$this->joined->push($row, $joined_link)];
    }

    #[\Override]
    public function columns(): array
    {
        return [...$this->main->columns(), ...$this->joined->columns()];
    }

    #[\Override]
    public function explain(): array
    {
        return [
            'node' => static::class,
            'relation' => $this->relation,
            'main' => $this->main->explain(),
            'joined' => $this->joined->explain(),
        ];
    }
}

/**
 * Одна таблица модели при обновлении: находит строку по ключу из колонок связи и меняет присланные колонки.
 *
 * Поток: строка + `$link` → значение ключа `link[key]` → присланы колонки этой таблицы —
 * `UPDATE <table> SET <колонки> WHERE <key> = :key AND (<колонка> IS DISTINCT FROM :<колонка> OR …)
 * RETURNING <key>, <returning>`; строки не вернулось или колонки не присланы —
 * `SELECT <key>, <returning> FROM <table> WHERE <key> = :key` (строку нужно найти всё равно: её ключи нужны
 * присоединённым таблицам, а отсутствие главной строки — это 404) → ключи: присланные колонки и возвращённые;
 * пусто — строки нет.
 *
 * Неизменённые значения пропускаются на стороне базы: строка, в которой все присланные значения уже такие же,
 * под условие IS DISTINCT FROM не попадает и не перезаписывается (триггеры и версии строки не срабатывают зря).
 * Сравнивает база, со своими типами: прочитать строку заранее и сравнить в PHP не нужно, а IS DISTINCT FROM
 * считает NULL обычным значением (NULL → NULL — не изменение, NULL → 'x' — изменение). Частичное совпадение
 * не дробится: если изменилась хоть одна колонка, SET пишет все присланные. Цена пропуска — второй запрос:
 * UPDATE без строки не отличает «нечего менять» от «строки нет», это решает SELECT.
 *
 * Пишутся только присланные колонки, остальные не трогаются. Колонка ключа и колонки связи в SET не попадают.
 * Ключ — одна колонка, значение int. SQL через pg_builder, выполненный запрос с параметрами — в `error_log()`.
 */
final readonly class UpdateTableWriter implements TableWriter
{
    /**
     * @param class-string<Entity> $entity
     * @param list<string>         $columns   колонки модели ресурса, которые лежат в этой таблице
     * @param string               $key       колонка, по которой ищется строка: первичный ключ главной таблицы или
     *                                        колонка связи присоединённой
     * @param list<string>         $returning колонки, которые нужны присоединённым таблицам (кроме $key)
     */
    public function __construct(
        private PDO $pdo,
        private string $entity,
        private array $columns,
        private string $key,
        private array $returning = [],
    ) {}

    #[\Override]
    public function push(array $row, array $link = []): array
    {
        $key_value = $link[$this->key] ?? throw new LogicException("{$this->entity}: no value for key {$this->key}");
        /** @var array<string, mixed> $values */
        $values = array_diff_key(array_intersect_key($row, array_flip($this->columns)), [$this->key => true]);
        $returned = array_values(array_unique([$this->key, ...$this->returning]));

        $found = false;
        if ($values !== []) {
            $found = $this->run(
                statement: $this->update(set: array_keys($values), returned: $returned),
                parameters: [...$values, 'key' => $key_value],
            );
        }
        // Колонки не присланы или все значения уже такие же — строку только ищем: нужны её ключи и сам факт, что она есть
        if ($found === false) {
            $found = $this->run(
                statement: $this->select(returned: $returned),
                parameters: ['key' => $key_value],
            );
        }

        return $found === false ? [] : [...$values, ...$found];
    }

    /**
     * Выполняет запрос и читает первую строку результата; пишет запрос, параметры и результат в `error_log()`.
     *
     * @param array<string, mixed> $parameters имя параметра без двоеточия → значение
     * @return array<string, mixed>|false строка или false, если строк нет
     */
    private function run(Update|Select $statement, array $parameters): array|false
    {
        $sql = (string) preg_replace(pattern: '/\s+/', replacement: ' ', subject: $statement->dispatch(new SqlBuilderWalker()));

        $prepared = $this->pdo->prepare($sql);
        foreach ($parameters as $name => $value) {
            $prepared->bindValue(':' . $name, $value, match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            });
        }
        $prepared->execute();
        /** @var array<string, mixed>|false $found */
        $found = $prepared->fetch(PDO::FETCH_ASSOC);
        $prepared->closeCursor();

        error_log("{$sql}\n    " . (string) json_encode($parameters) . ' → ' . ($found === false ? 'no row' : json_encode($found)));

        return $found;
    }

    /**
     * @param list<string> $set      колонки SET
     * @param list<string> $returned колонки RETURNING
     */
    private function update(array $set, array $returned): Update
    {
        $update = new Update(
            new UpdateOrDeleteTarget(new QualifiedName($this->entity::TABLE)),
            new SetClauseList(array_map(
                static fn(string $column): SingleSetClause => new SingleSetClause(new SetTargetElement($column), new NamedParameter($column)),
                $set,
            )),
        );
        $update->where->and(new OperatorExpression('=', new ColumnReference($this->key), new NamedParameter('key')));
        // Только если хоть одно присланное значение отличается от записанного; NULL сравнивается как значение
        $update->where->and(new LogicalExpression(
            array_map(
                static fn(string $column): IsDistinctFromExpression => new IsDistinctFromExpression(
                    new ColumnReference($column),
                    new NamedParameter($column),
                ),
                $set,
            ),
            LogicalOperator::OR,
        ));
        $update->returning->replace(self::targets(columns: $returned));

        return $update;
    }

    /**
     * @param list<string> $returned колонки списка выборки
     */
    private function select(array $returned): Select
    {
        $select = new Select(new TargetList(self::targets(columns: $returned)));
        $select->from[] = new RelationReference(new QualifiedName($this->entity::TABLE));
        $select->where->and(new OperatorExpression('=', new ColumnReference($this->key), new NamedParameter('key')));

        return $select;
    }

    /**
     * @param list<string> $columns
     * @return list<TargetElement>
     */
    private static function targets(array $columns): array
    {
        return array_map(static fn(string $column): TargetElement => new TargetElement(new ColumnReference($column)), $columns);
    }

    #[\Override]
    public function columns(): array
    {
        return $this->columns;
    }

    #[\Override]
    public function explain(): array
    {
        return [
            'node' => static::class,
            'table' => $this->entity::TABLE,
            'key' => $this->key,
            'columns' => $this->columns,
            'returning' => $this->returning,
        ];
    }
}

/**
 * Необязательная часть модели при обновлении (правая сторона `LeftJoin`): без её колонок в строке пропускается;
 * с колонками — обновляется, а если строки нет (в SELECT эта часть была `null`) — вставляется с колонкой связи.
 * Пара к InsertOptionalTableWriter.
 *
 * Поток: строка + `$link` → нет колонок этой части → `[]`; есть → `update->push()` → ключи; пусто →
 * `insert->push(строка, link)` → ключи вставленной строки.
 *
 * Колонки берутся у `update` (`TableWriter::columns()`); `insert` должен писать ту же таблицу с теми же колонками.
 */
final readonly class UpdateOptionalTableWriter implements TableWriter
{
    public function __construct(
        private UpdateTableWriter $update,
        private InsertTableWriter $insert,
    ) {}

    #[\Override]
    public function push(array $row, array $link = []): array
    {
        if (array_intersect_key($row, array_flip($this->update->columns())) === []) {
            return [];
        }

        $keys = $this->update->push($row, $link);

        return $keys !== [] ? $keys : $this->insert->push($row, $link);
    }

    #[\Override]
    public function columns(): array
    {
        return $this->update->columns();
    }

    #[\Override]
    public function explain(): array
    {
        return [
            'node' => static::class,
            'update' => $this->update->explain(),
            'insert' => $this->insert->explain(),
        ];
    }
}

// ---------------------------------------------------------------------------------------------------------------
// Дерево для CertificateWithDetailResource, собранное руками (планировщика UPDATE нет)

$pdo = getPDO();

$certificate_columns = [
    CertificatesEntity::ID,
    CertificatesEntity::PROVIDER_ID,
    CertificatesEntity::CODE,
    CertificatesEntity::NOMINAL,
    CertificatesEntity::CURRENCY,
    CertificatesEntity::STATUS,
    CertificatesEntity::CREATED_AT,
];
$detail_columns = [
    CertificateDetailsEntity::BUYER_EMAIL,
    CertificateDetailsEntity::RECIPIENT_NAME,
    CertificateDetailsEntity::RECIPIENT_EMAIL,
    CertificateDetailsEntity::GREETING_TEXT,
    CertificateDetailsEntity::DESIGN_TEMPLATE_ID,
];

$tree = new UpdateResourceWriter(
    resource: CertificateWithDetailResource::class,
    input: new UpdateTableJoinWriter(
        relation: [CertificatesEntity::ID, CertificateDetailsEntity::CERTIFICATE_ID],
        main: new UpdateTableWriter(
            pdo: $pdo,
            entity: CertificatesEntity::class,
            columns: $certificate_columns,
            key: CertificatesEntity::ID,
        ),
        joined: new UpdateOptionalTableWriter(
            update: new UpdateTableWriter(
                pdo: $pdo,
                entity: CertificateDetailsEntity::class,
                columns: $detail_columns,
                key: CertificateDetailsEntity::CERTIFICATE_ID,
            ),
            insert: new InsertTableWriter(
                pdo: $pdo,
                entity: CertificateDetailsEntity::class,
                columns: $detail_columns,
            ),
        ),
    ),
);

echo json_encode(new UpdateRootWriter(root: $tree, data: [])->explain(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n\n";

/**
 * Строка сертификата с деталями после обновления — чтобы увидеть, что изменилось.
 *
 * @return array<string, mixed>|false
 */
$certificate = static function (PDO $pdo, int $id): array|false {
    $statement = $pdo->prepare(
        'select c.id, c.provider_id, c.code, c.nominal, d.certificate_id as detail, d.buyer_email, d.recipient_name
           from certificates c left join certificate_details d on d.certificate_id = c.id where c.id = :id',
    );
    $statement->execute(['id' => $id]);

    return $statement->fetch(PDO::FETCH_ASSOC);
};

/**
 * Сценарий в своей транзакции: $prepare готовит данные в той же транзакции и возвращает id сертификата,
 * $document строит документ PATCH по нему.
 *
 * @param callable(PDO): int     $prepare
 * @param callable(int): array   $document
 */
$scenario = static function (string $title, callable $prepare, callable $document) use ($pdo, $tree, $certificate): void {
    echo "######## {$title}\n";

    $pdo->beginTransaction();
    try {
        $id = $prepare($pdo);
        $keys = new UpdateRootWriter(
            root: $tree,
            data: [$document($id)],
        )->execute();
        echo 'keys:  ', json_encode($keys), "\n";
        echo 'after: ', json_encode($certificate($pdo, $id)), "\n\n";
    } catch (ResourceNotFoundException $exception) {
        echo json_encode($exception->toError()), "\n\n";
    } finally {
        $pdo->rollBack();
    }
};

$scenario(
    title: 'PATCH /certificates/1: nominal (certificates) and buyer_email (certificate_details)',
    prepare: static fn(PDO $pdo): int => 1,
    document: static fn(int $id): array => [
        'type' => 'certificate',
        'id' => (string) $id,
        'attributes' => ['nominal' => '2500.00', 'buyer_email' => 'new-buyer@example.com'],
    ],
);

$scenario(
    title: 'PATCH /certificates/1: only the foreign-key relationship provider, certificate_details is skipped',
    prepare: static fn(PDO $pdo): int => 1,
    document: static fn(int $id): array => [
        'type' => 'certificate',
        'id' => (string) $id,
        'relationships' => ['provider' => ['data' => ['type' => 'provider', 'id' => '2']]],
    ],
);

$scenario(
    title: 'PATCH of a certificate without details: SELECT certificates, UPDATE finds no detail row, INSERT it',
    prepare: static function (PDO $pdo): int {
        $pdo->exec("insert into certificates (provider_id, code, nominal) values (1, 'NO-DETAIL', '1.00')");

        return (int) $pdo->lastInsertId();
    },
    document: static fn(int $id): array => [
        'type' => 'certificate',
        'id' => (string) $id,
        'attributes' => ['buyer_email' => 'first-buyer@example.com', 'recipient_name' => 'Boris'],
    ],
);

$scenario(
    title: 'PATCH /certificates/1 with the values already stored: nothing is rewritten, no 404',
    prepare: static fn(PDO $pdo): int => 1,
    document: static fn(int $id): array => [
        'type' => 'certificate',
        'id' => (string) $id,
        'attributes' => ['nominal' => '1000', 'buyer_email' => 'buyer1@example.com'],
    ],
);

$scenario(
    title: 'PATCH /certificates/999999: no such certificate',
    prepare: static fn(PDO $pdo): int => 999999,
    document: static fn(int $id): array => [
        'type' => 'certificate',
        'id' => (string) $id,
        'attributes' => ['nominal' => '1.00'],
    ],
);
