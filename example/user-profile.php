<?php

declare(strict_types=1);

/**
 * Пример: сценарий Trackpoint `UserProfilePreparedStatement` на этой библиотеке. Там один «профиль пользователя»
 * собирался из четырёх `QueryInterface`, соединённых в PHP по `pbup_id`: таблица профиля и три компонента из общей
 * таблицы `view_pb_user_component`, которые различаются константой `pbcom_id` (карта — 70, трекинг — 1200, обзор
 * пакетов — 1021), а атрибуты лежат в JSON-колонке `pbucom_data`.
 *
 * Здесь то же самое:
 *  - профиль — обычный ресурс над таблицей (`UserProfilesEntity`, SQL строит движок);
 *  - каждый компонент — ресурс над классом-источником (`DataSource` из прототипа `source-document.php`): источник сам
 *    знает свою константу (`UserMapSource::COMPONENT_ID = 70`), сам читает и пишет общую таблицу `user_components` и
 *    сам разбирает JSON на колонки. Движок константы не видит — как и библиотека Trackpoint;
 *  - связь профиля с компонентом — 1:1 по владению (`OneToOne`), id компонента — id профиля.
 *
 * Соответствие Trackpoint → пример: `pbup_id` → `user_profiles.id` / `user_components.user_profile_id`,
 * `pbcom_id` → `component_id`, `pbucom_data` → `data`; плоский кортеж из четырёх интерфейсов → `data` + `included`;
 * условие `pbup_id = …`, которое получал каждый интерфейс, → аргумент маршрута (его получает каждый источник).
 *
 * Отличия от Trackpoint:
 *  - PATCH не перечитывает ресурс (ответ `204`): интерфейс без изменённых атрибутов там выполнял SELECT, здесь профиль
 *    без атрибутов только проверяется на существование, а не названные в документе компоненты не трогаются вовсе;
 *  - изменение компонента дополняет JSON присланными ключами (там JSON заменялся целиком);
 *  - источники работают через то же подключение, что и движок, поэтому участвуют в его транзакции;
 *  - удаление профиля убирает компоненты каскадом базы (`ON DELETE CASCADE`), источники при этом не вызываются.
 *
 * Чего библиотека не повторяет — см. сценарий 9 и ответ к примеру: режимов «только запись» (`pbup_password`) и
 * «только при вставке» (`pbup_login`) у атрибута нет, поэтому пароля в ресурсе нет, а логин изменяем.
 *
 * База — SQLite в памяти, создаётся здесь же. Всё в одном файле, `src/` не менялся.
 *
 * Запуск: php example/user-profile.php 2>sql.log   (SQL движка и SQL источников — в stderr)
 */

namespace Ggs\JsonApiEngine\Example\UserProfile;

use Ggs\JsonApiEngine\Example\Container\ArrayContainer;
use Ggs\JsonApiEngine\Executor\Write\WriteNotFoundException;
use Ggs\JsonApiEngine\Executor\Select\ReaderBuilder;
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
use Ggs\JsonApiEngine\Plan\Select\SelectPlanPrinter;
use Ggs\JsonApiEngine\Plan\Select\SelectPlanner;
use Ggs\JsonApiEngine\Plan\Write\WritePlanner;
use Ggs\JsonApiEngine\Resource;
use Ggs\JsonApiEngine\Resource\DeletableInterface;
use Ggs\JsonApiEngine\Resource\InsertableInterface;
use Ggs\JsonApiEngine\Resource\OneToOne;
use Ggs\JsonApiEngine\Resource\UpdatableInterface;
use LogicException;
use PDO;
use Psr\Container\ContainerInterface;

use function Ggs\JsonApiEngine\Example\SourceDocument\compactJson;
use function Ggs\JsonApiEngine\Example\SourceDocument\executionTree;
use function Ggs\JsonApiEngine\Example\SourceDocument\json;
use function Ggs\JsonApiEngine\Example\SourceDocument\queries;
use function Ggs\JsonApiEngine\Example\SourceDocument\shortClassName;
use function Ggs\JsonApiEngine\Example\SourceDocument\stateChanges;

// Классы и функции прототипа источников; его сценарии при подключении не запускаются
require __DIR__ . '/source-document.php';

// =====================================================================================================================
// Таблица профиля
// =====================================================================================================================

/**
 * Таблица `user_profiles` (Trackpoint: `pb_user_profile`). `password` в ресурс не входит: режима «только запись» у
 * атрибута нет.
 */
final class UserProfilesEntity extends DatabaseEntitySource
{
    public const string TABLE = 'user_profiles';
    public const string ID = 'id';
    public const string LOGIN = 'login';
    public const string PASSWORD = 'password';
    public const string FIRST_NAME = 'first_name';
    public const string LAST_NAME = 'last_name';
    public const string TIME_ZONE = 'time_zone';
    public const string ACTIVE = 'active';

    public const array COLUMNS = [
        self::ID => ['type' => self::INT, 'constraint' => self::PRIMARY],
        self::LOGIN => ['type' => self::STRING, 'unique' => true],
        self::PASSWORD => ['type' => self::STRING],
        self::FIRST_NAME => ['type' => self::STRING, 'nullable' => true],
        self::LAST_NAME => ['type' => self::STRING, 'nullable' => true],
        self::TIME_ZONE => ['type' => self::STRING],
        self::ACTIVE => ['type' => self::BOOL],
    ];
}

// =====================================================================================================================
// Источники компонентов
// =====================================================================================================================

/**
 * Компонент пользователя: одна строка общей таблицы `user_components` на пару (пользователь, вид компонента).
 * Пара к `UserComponentRepository` + `User…QueryInterface` из Trackpoint.
 *
 * Наследник объявляет вид компонента (`COMPONENT_ID`) и свои колонки (`COLUMNS`): первичный ключ `user_profile_id` и
 * ключи JSON из `data`. Константу знает только источник: он подставляет её в каждый свой запрос (условие при чтении,
 * изменении и удалении, значение при вставке); движок и ресурс работают с колонками источника, как с таблицей
 * «компонент этого вида».
 *
 * Поток: `select()` — строки `user_components` своего вида → «user_profile_id + ключи JSON» (нет ключа — значение из
 * `DEFAULTS` или null), всегда по возрастанию `user_profile_id` (`getReadOrientation()`); аргумент маршрута
 * `user_profile_id` — условие равенства. `insert()` — колонки документа → JSON, `user_profile_id` — колонка связи от
 * родителя или аргумент маршрута. `update()` — присланные колонки дополняют JSON; пустая строка — только проверка, что
 * компонент есть. `delete()` — удаление строки.
 *
 * Условий клиента по ключам JSON источник не умеет (ресурсы примера их и не объявляют): непустые условия или порядок
 * не по ключу — `LogicException`. Каждый запрос пишется в `error_log()` (stderr), как SQL движка.
 */
abstract class UserComponentSource extends ForeignDataSource implements
    SelectForeignDataInterface,
    InsertForeignDataInterface,
    UpdateForeignDataInterface,
    DeleteForeignDataInterface
{
    /** вид компонента (`pbcom_id`); наследник переопределяет */
    public const int COMPONENT_ID = 0;

    /** @var array<string, mixed> значения ключей JSON, которых в `data` нет; наследник переопределяет */
    protected const array DEFAULTS = [];

    public function __construct(
        private readonly PDO $pdo,
    ) {}

    #[\Override]
    public static function getReadOrientation(): array
    {
        return [static::getPrimaryKeyColumnName()];
    }

    #[\Override]
    public function select(array $columns, array $conditions, array $order, array $arguments): iterable
    {
        $primary = static::getPrimaryKeyColumnName();
        if ($conditions !== []) {
            throw new LogicException(static::class . ': conditions on component data are not supported');
        }
        foreach ($order as $entry) {
            if ($entry['column'] !== $primary || $entry['descending']) {
                throw new LogicException(static::class . ": only ascending order by {$primary} is supported");
            }
        }
        $sql = 'select user_profile_id, data from user_components where component_id = :component_id';
        $parameters = ['component_id' => static::COMPONENT_ID];
        if (isset($arguments[$primary])) {
            $sql .= ' and user_profile_id = :user_profile_id';
            $parameters['user_profile_id'] = (int) $arguments[$primary];
        }
        foreach ($this->run(sql: "{$sql} order by user_profile_id", parameters: $parameters) as $tuple) {
            yield [$primary => (int) $tuple['user_profile_id'], ...$this->decode(data: (string) $tuple['data'])];
        }
    }

    #[\Override]
    public function insert(array $row, array $arguments): array
    {
        $primary = static::getPrimaryKeyColumnName();
        $user_profile_id = $row[$primary] ?? $arguments[$primary]
            ?? throw new LogicException(static::class . ": {$primary} is required");
        unset($row[$primary]);
        $this->run(
            sql: 'insert into user_components (user_profile_id, component_id, data)'
                . ' values (:user_profile_id, :component_id, :data)',
            parameters: [
                'user_profile_id' => (int) $user_profile_id,
                'component_id' => static::COMPONENT_ID,
                'data' => compactJson($row),
            ],
        );

        return [$primary => (int) $user_profile_id];
    }

    #[\Override]
    public function update(array $key, array $row, array $arguments): array
    {
        $primary = static::getPrimaryKeyColumnName();
        $user_profile_id = (int) $key[$primary];
        if (isset($arguments[$primary]) && (int) $arguments[$primary] !== $user_profile_id) {
            return [];
        }
        $identity = ['user_profile_id' => $user_profile_id, 'component_id' => static::COMPONENT_ID];
        $current = $this->run(
            sql: 'select data from user_components'
                . ' where component_id = :component_id and user_profile_id = :user_profile_id',
            parameters: $identity,
        );
        if ($current === []) {
            return [];
        }
        if ($row !== []) {
            $this->run(
                sql: 'update user_components set data = :data'
                    . ' where component_id = :component_id and user_profile_id = :user_profile_id',
                parameters: [
                    ...$identity,
                    'data' => compactJson([...self::getJson(data: (string) $current[0]['data']), ...$row]),
                ],
            );
        }

        return [$primary => $user_profile_id];
    }

    #[\Override]
    public function delete(array $key, array $arguments): array
    {
        $primary = static::getPrimaryKeyColumnName();
        $deleted = $this->run(
            sql: 'delete from user_components'
                . ' where component_id = :component_id and user_profile_id = :user_profile_id'
                . ' returning user_profile_id',
            parameters: ['user_profile_id' => (int) $key[$primary], 'component_id' => static::COMPONENT_ID],
        );

        return $deleted === [] ? [] : [$primary => (int) $deleted[0]['user_profile_id']];
    }

    /**
     * JSON компонента → колонки источника: только объявленные, без значения — из `DEFAULTS`, иначе null.
     *
     * @return array<string, mixed>
     */
    private function decode(string $data): array
    {
        $columns = array_diff_key(static::COLUMNS, [static::getPrimaryKeyColumnName() => true]);

        return [
            ...array_fill_keys(array_keys($columns), null),
            ...static::DEFAULTS,
            ...array_intersect_key(self::getJson(data: $data), $columns),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function getJson(string $data): array
    {
        $decoded = json_decode($data, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, int|string> $parameters
     * @return list<array<string, mixed>>
     */
    private function run(string $sql, array $parameters): array
    {
        error_log(shortClassName(class: static::class) . ": {$sql}   " . compactJson($parameters));
        $statement = $this->pdo->prepare($sql);
        foreach ($parameters as $name => $value) {
            $statement->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}

/**
 * Настройки карты пользователя, компонент 70 (Trackpoint: `UserMapQueryInterface`).
 */
final class UserMapSource extends UserComponentSource
{
    public const int COMPONENT_ID = 70;
    public const string USER_PROFILE_ID = 'user_profile_id';
    public const string MAP = 'map';

    public const array COLUMNS = [
        self::USER_PROFILE_ID => ['type' => self::INT, 'constraint' => self::PRIMARY],
        self::MAP => ['type' => self::STRING, 'nullable' => true],
    ];
}

/**
 * Настройки трекинга, компонент 1200 (Trackpoint: `UserTrackingQueryInterface`). Флаг, которого нет в JSON, — true.
 */
final class UserTrackingSource extends UserComponentSource
{
    public const int COMPONENT_ID = 1200;
    public const string USER_PROFILE_ID = 'user_profile_id';
    public const string MARKER_TAIL = 'marker_tail';
    public const string CLUSTERED_MARKER = 'clustered_marker';
    public const string FIT_LAYER = 'fit_layer';

    public const array COLUMNS = [
        self::USER_PROFILE_ID => ['type' => self::INT, 'constraint' => self::PRIMARY],
        self::MARKER_TAIL => ['type' => self::BOOL],
        self::CLUSTERED_MARKER => ['type' => self::BOOL],
        self::FIT_LAYER => ['type' => self::BOOL],
    ];

    protected const array DEFAULTS = [
        self::MARKER_TAIL => true,
        self::CLUSTERED_MARKER => true,
        self::FIT_LAYER => true,
    ];
}

/**
 * Обзор пакетов, компонент 1021 (Trackpoint: `UserPackageOverviewQueryInterface`).
 */
final class UserPackageOverviewSource extends UserComponentSource
{
    public const int COMPONENT_ID = 1021;
    public const string USER_PROFILE_ID = 'user_profile_id';
    public const string MODE = 'mode';

    public const array COLUMNS = [
        self::USER_PROFILE_ID => ['type' => self::INT, 'constraint' => self::PRIMARY],
        self::MODE => ['type' => self::STRING, 'nullable' => true],
    ];
}

// =====================================================================================================================
// Ресурсы
// =====================================================================================================================

/**
 * `user-map`: ресурс над источником `UserMapSource`, id — id профиля.
 */
final class UserMapResource extends Resource implements InsertableInterface, UpdatableInterface, DeletableInterface
{
    public const string TYPE = 'user-map';
    public const string ID = 'id';
    public const string MAP = 'map';

    public const array ATTRIBUTES = [
        self::ID => ['column' => UserMapSource::USER_PROFILE_ID, 'type' => self::INT, 'constraint' => self::PRIMARY],
        self::MAP => ['column' => UserMapSource::MAP, 'type' => self::STRING, 'nullable' => true],
    ];

    #[\Override]
    public static function getRelationships(): array
    {
        return [];
    }

    public static function getModel(): string
    {
        return UserMapSource::class;
    }
}

/**
 * `user-tracking`: ресурс над источником `UserTrackingSource`, id — id профиля.
 */
final class UserTrackingResource extends Resource implements InsertableInterface, UpdatableInterface, DeletableInterface
{
    public const string TYPE = 'user-tracking';
    public const string ID = 'id';
    public const string MARKER_TAIL = 'marker_tail';
    public const string CLUSTERED_MARKER = 'clustered_marker';
    public const string FIT_LAYER = 'fit_layer';

    public const array ATTRIBUTES = [
        self::ID => [
            'column' => UserTrackingSource::USER_PROFILE_ID,
            'type' => self::INT,
            'constraint' => self::PRIMARY,
        ],
        self::MARKER_TAIL => ['column' => UserTrackingSource::MARKER_TAIL, 'type' => self::BOOL],
        self::CLUSTERED_MARKER => ['column' => UserTrackingSource::CLUSTERED_MARKER, 'type' => self::BOOL],
        self::FIT_LAYER => ['column' => UserTrackingSource::FIT_LAYER, 'type' => self::BOOL],
    ];

    #[\Override]
    public static function getRelationships(): array
    {
        return [];
    }

    public static function getModel(): string
    {
        return UserTrackingSource::class;
    }
}

/**
 * `user-package-overview`: ресурс над источником `UserPackageOverviewSource`, id — id профиля.
 */
final class UserPackageOverviewResource extends Resource implements InsertableInterface, UpdatableInterface, DeletableInterface
{
    public const string TYPE = 'user-package-overview';
    public const string ID = 'id';
    public const string MODE = 'mode';

    public const array ATTRIBUTES = [
        self::ID => [
            'column' => UserPackageOverviewSource::USER_PROFILE_ID,
            'type' => self::INT,
            'constraint' => self::PRIMARY,
        ],
        self::MODE => ['column' => UserPackageOverviewSource::MODE, 'type' => self::STRING, 'nullable' => true],
    ];

    #[\Override]
    public static function getRelationships(): array
    {
        return [];
    }

    public static function getModel(): string
    {
        return UserPackageOverviewSource::class;
    }
}

/**
 * `user-profile`: таблица `user_profiles` и три компонента по владению (1:1, каждый — свой источник). Порядок
 * объявления связей — порядок записи компонентов.
 */
final class UserProfileResource extends Resource implements InsertableInterface, UpdatableInterface, DeletableInterface
{
    public const string TYPE = 'user-profile';
    public const string ID = 'id';
    public const string LOGIN = 'login';
    public const string FIRST_NAME = 'first_name';
    public const string LAST_NAME = 'last_name';
    public const string TIME_ZONE = 'time_zone';
    public const string ACTIVE = 'active';
    public const string MAP = 'map';
    public const string TRACKING = 'tracking';
    public const string PACKAGE_OVERVIEW = 'package_overview';

    public const array ATTRIBUTES = [
        self::ID => [
            'column' => UserProfilesEntity::ID,
            'type' => self::INT,
            'constraint' => self::PRIMARY,
            'filter' => [Operator::Eq],
        ],
        self::LOGIN => ['column' => UserProfilesEntity::LOGIN, 'type' => self::STRING],
        self::FIRST_NAME => ['column' => UserProfilesEntity::FIRST_NAME, 'type' => self::STRING, 'nullable' => true],
        self::LAST_NAME => ['column' => UserProfilesEntity::LAST_NAME, 'type' => self::STRING, 'nullable' => true],
        self::TIME_ZONE => ['column' => UserProfilesEntity::TIME_ZONE, 'type' => self::STRING],
        self::ACTIVE => ['column' => UserProfilesEntity::ACTIVE, 'type' => self::BOOL, 'filter' => [Operator::Eq]],
    ];

    #[\Override]
    public static function getRelationships(): array
    {
        return [
            self::MAP => new OneToOne(
                resource: UserMapResource::class,
                relation: [UserProfilesEntity::ID, UserMapSource::USER_PROFILE_ID],
            ),
            self::TRACKING => new OneToOne(
                resource: UserTrackingResource::class,
                relation: [UserProfilesEntity::ID, UserTrackingSource::USER_PROFILE_ID],
            ),
            self::PACKAGE_OVERVIEW => new OneToOne(
                resource: UserPackageOverviewResource::class,
                relation: [UserProfilesEntity::ID, UserPackageOverviewSource::USER_PROFILE_ID],
            ),
        ];
    }

    public static function getModel(): string
    {
        return UserProfilesEntity::class;
    }
}

// =====================================================================================================================
// База и сценарии
// =====================================================================================================================

/**
 * SQLite в памяти: профили и общая таблица компонентов. Профиль 1 — все три компонента; 2 — карта и трекинг (в JSON
 * трекинга один флаг из трёх); 3 — без компонентов; 4 — неактивный, с картой.
 */
function database(): PDO
{
    $pdo = new PDO('sqlite::memory:', options: [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec(<<<SQL
        create table user_profiles (
            id integer primary key,
            login text not null unique,
            password text not null default '',
            first_name text,
            last_name text,
            time_zone text not null default 'UTC',
            active integer not null default 1
        );
        create table user_components (
            user_profile_id integer not null references user_profiles (id) on delete cascade,
            component_id integer not null,
            data text not null,
            primary key (user_profile_id, component_id)
        );
        insert into user_profiles (id, login, first_name, last_name, time_zone, active) values
            (1, 'alice', 'Alice', 'Ozola', 'Europe/Riga', 1),
            (2, 'bob', 'Bob', 'Kalns', 'Europe/Tallinn', 1),
            (3, 'carol', 'Carol', null, 'UTC', 1),
            (4, 'dave', 'Dave', 'Liepa', 'UTC', 0);
        insert into user_components (user_profile_id, component_id, data) values
            (1, 70, '{"map":"osm"}'),
            (1, 1200, '{"marker_tail":false,"clustered_marker":true,"fit_layer":true}'),
            (1, 1021, '{"mode":"table"}'),
            (2, 70, '{"map":"satellite"}'),
            (2, 1200, '{"fit_layer":false}'),
            (4, 70, '{"map":"osm"}');
        SQL);

    return $pdo;
}

/**
 * Контейнер зависимостей (PSR-11) с объектами источников компонентов (зависимость каждого — подключение) и
 * объектами ресурсов: сборщики берут из него и те, и другие по имени класса. В приложении их создаёт и связывает
 * его собственный контейнер.
 */
function container(PDO $pdo): ContainerInterface
{
    return new ArrayContainer(
        services: [
            new UserMapSource(
                pdo: $pdo,
            ),
            new UserTrackingSource(
                pdo: $pdo,
            ),
            new UserPackageOverviewSource(
                pdo: $pdo,
            ),
            new UserProfileResource(),
            new UserMapResource(),
            new UserTrackingResource(),
            new UserPackageOverviewResource(),
        ],
    );
}

/**
 * Сценарий выборки: URL → дерево запроса → план → документ; запросы источников — в stderr.
 *
 * @param class-string<Resource> $resource корневой ресурс
 * @param array<string, mixed>   $query    параметры запроса, которые контроллер добавил из маршрута (`filter[id]`)
 * @param array<string, mixed>   $arguments аргументы маршрута для источников
 */
function select(PDO $pdo, string $title, string $resource, string $url, array $query = [], array $arguments = []): void
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $client_query);
    echo "\n######## {$title}\nGET {$url}", $arguments === [] ? '' : '   arguments: ' . compactJson($arguments), "\n\n";

    $tree = new QueryParser()->parse(resource: $resource, query: array_replace_recursive($client_query, $query));
    $plan = new SelectPlanner()->plan(tree: $tree)['sql'];
    echo "#### plan\n", SelectPlanPrinter::getTree(node: $plan), "\n#### queries\n", queries(node: $plan), "\n";

    $builder = new ReaderBuilder(
        pdo: $pdo,
        container: container(pdo: $pdo),
    );
    $document = json($builder->getDocument(plan: $plan, arguments: $arguments));
    echo "#### document\n{$document}\n";
}

/**
 * Состояние обеих таблиц: профили по id, компоненты по «пользователь:вид».
 *
 * @return array{profiles: list<array<string, mixed>>, components: list<array<string, mixed>>}
 */
function state(PDO $pdo): array
{
    return [
        'profiles' => $pdo->query('select * from user_profiles order by id')->fetchAll(),
        'components' => $pdo->query(
            "select user_profile_id || ':' || component_id as key, data from user_components"
            . ' order by user_profile_id, component_id',
        )->fetchAll(),
    ];
}

/**
 * Сценарий записи: дерево операций → план → дерево исполнителей → состояние таблиц; запросы источников — в stderr.
 * Всё в одной транзакции — источники пишут через то же подключение; в конце откат.
 *
 * @param list<WriteOperation> $roots корни дерева операций (от `WriteDocumentParser`)
 * @param array<string, mixed> $arguments аргументы маршрута для источников
 */
function write(PDO $pdo, string $title, array $roots, array $arguments = []): void
{
    echo "\n######## {$title}\n\n";
    $before = state(pdo: $pdo);
    $pdo->beginTransaction();
    try {
        $plan = new WritePlanner()->plan(resource: UserProfileResource::class, roots: $roots);
        $builder = new WriterBuilder(
            pdo: $pdo,
            lock: false,
            container: container(pdo: $pdo),
        );
        $writer = $builder->getRoot(plan: $plan, data: $roots, arguments: $arguments);
        echo "#### execution tree\n", executionTree(explain: $writer->explain()), "\n#### keys\n";
        foreach ($writer->execute() as $keys) {
            echo '  ', compactJson($keys), "\n";
        }
    } catch (WriteNotFoundException $exception) {
        echo "#### response\n", json(['errors' => [$exception->toError()]]), "\n";
    } catch (LogicException $exception) {
        echo "#### not supported\n  LogicException: {$exception->getMessage()}\n";
    }
    $after = state(pdo: $pdo);
    $pdo->rollBack();

    echo "\n#### user_profiles: rows before → after\n";
    foreach (stateChanges(before: $before['profiles'], after: $after['profiles'], primary: 'id') as $line) {
        echo "  {$line}\n";
    }
    echo "#### user_components: rows before → after\n";
    foreach (stateChanges(before: $before['components'], after: $after['components'], primary: 'key') as $line) {
        echo "  {$line}\n";
    }
}

// ---------------------------------------------------------------------------------------------------------------------
// Описания проходят обычные проверки. UserProfileResource не проверяется: validate() ресурса с OneToOne сейчас падает
// (известная ошибка OneToOne::$filter в src/Resource.php)

$descriptions = [
    UserProfilesEntity::class,
    UserMapSource::class,
    UserTrackingSource::class,
    UserPackageOverviewSource::class,
    UserMapResource::class,
    UserTrackingResource::class,
    UserPackageOverviewResource::class,
];
foreach ($descriptions as $description) {
    $description::validate();
}

$pdo = database();

// ---------------------------------------------------------------------------------------------------------------------
// Выборка (Trackpoint: DQL::SELECT)

// 1. Один профиль со всеми компонентами. id из маршрута: профилю — условием (filter[id], добавляет контроллер),
//    источникам — аргументом маршрута, каждый подставляет его рядом со своей константой. У профиля 2 в JSON трекинга
//    один флаг — остальные из DEFAULTS; обзора пакетов нет — `package_overview: null`
select(
    pdo: $pdo,
    title: '1. one profile with its components',
    resource: UserProfileResource::class,
    url: '/user-profiles/2?include=map,tracking,package_overview',
    query: ['filter' => ['id' => '2']],
    arguments: ['user_profile_id' => 2],
);

// 2. Список: аргумента маршрута нет, ключей родителей источник не получает — читает все компоненты своего вида;
//    лишнее (карта неактивного профиля 4) отбрасывает свободное соединение
select(
    pdo: $pdo,
    title: '2. list of profiles, components read whole',
    resource: UserProfileResource::class,
    url: '/user-profiles?include=map,tracking&filter[active]=true&fields[user-profile]=login',
);

// 3. Компонент — корень: один источник, без таблицы профиля
select(
    pdo: $pdo,
    title: '3. component as the root',
    resource: UserMapResource::class,
    url: '/user-profiles/1/map',
    arguments: ['user_profile_id' => 1],
);

// ---------------------------------------------------------------------------------------------------------------------
// Запись

// 4. POST (Trackpoint: DQL::INSERT): профиль — INSERT движка (stderr), его новый id приходит каждому источнику
//    колонкой связи; источник добавляет свою константу
write(
    pdo: $pdo,
    title: '4. POST /user-profiles with three components',
    roots: new WriteDocumentParser()->insert(
        document: [
            'data' => [
                'type' => 'user-profile',
                'attributes' => ['login' => 'erin', 'first_name' => 'Erin', 'time_zone' => 'Europe/Riga'],
                'relationships' => [
                    'map' => ['data' => ['type' => 'user-map', 'lid' => 'map']],
                    'tracking' => ['data' => ['type' => 'user-tracking', 'lid' => 'tracking']],
                    'package_overview' => ['data' => ['type' => 'user-package-overview', 'lid' => 'overview']],
                ],
            ],
            'included' => [
                ['type' => 'user-map', 'lid' => 'map', 'attributes' => ['map' => 'osm']],
                ['type' => 'user-tracking', 'lid' => 'tracking', 'attributes' => ['clustered_marker' => false]],
                ['type' => 'user-package-overview', 'lid' => 'overview', 'attributes' => ['mode' => 'cards']],
            ],
        ],
        resource: UserProfileResource::class,
    ),
);

// 5. PATCH (Trackpoint: DQL::UPDATE с данными для двух интерфейсов): профиль — UPDATE движка, карта — update()
//    источника; трекинг в документе не назван и не трогается
write(
    pdo: $pdo,
    title: '5. PATCH /user-profiles/2: profile attributes and the map',
    roots: [
        new WriteDocumentParser()->update(
            document: [
                'data' => [
                    'type' => 'user-profile',
                    'id' => '2',
                    'attributes' => ['first_name' => 'Robert', 'time_zone' => 'Europe/Riga'],
                    'relationships' => ['map' => ['data' => ['type' => 'user-map', 'id' => '2']]],
                ],
                'included' => [['type' => 'user-map', 'id' => '2', 'attributes' => ['map' => 'osm']]],
            ],
            resource: UserProfileResource::class,
            id: '2',
        ),
    ],
    arguments: ['user_profile_id' => 2],
);

// 6. PATCH только компонента (Trackpoint: интерфейс без данных выполнял SELECT вместо UPDATE): профиль без атрибутов
//    только проверяется на существование; JSON трекинга дополняется присланным флагом
write(
    pdo: $pdo,
    title: '6. PATCH /user-profiles/2: one tracking flag only',
    roots: [
        new WriteDocumentParser()->update(
            document: [
                'data' => [
                    'type' => 'user-profile',
                    'id' => '2',
                    'relationships' => ['tracking' => ['data' => ['type' => 'user-tracking', 'id' => '2']]],
                ],
                'included' => [['type' => 'user-tracking', 'id' => '2', 'attributes' => ['marker_tail' => false]]],
            ],
            resource: UserProfileResource::class,
            id: '2',
        ),
    ],
    arguments: ['user_profile_id' => 2],
);

// 7. PATCH компонента, которого нет: у профиля 3 нет карты — источник возвращает пусто, движок отвечает 404
write(
    pdo: $pdo,
    title: '7. PATCH /user-profiles/3: the map does not exist',
    roots: [
        new WriteDocumentParser()->update(
            document: [
                'data' => [
                    'type' => 'user-profile',
                    'id' => '3',
                    'relationships' => ['map' => ['data' => ['type' => 'user-map', 'id' => '3']]],
                ],
                'included' => [['type' => 'user-map', 'id' => '3', 'attributes' => ['map' => 'osm']]],
            ],
            resource: UserProfileResource::class,
            id: '3',
        ),
    ],
    arguments: ['user_profile_id' => 3],
);

// 8. DELETE (Trackpoint: DQL::DELETE по каждому интерфейсу): удаляется строка профиля, компоненты — каскадом базы,
//    источники не вызываются
write(
    pdo: $pdo,
    title: '8. DELETE /user-profiles/1',
    roots: [new WriteDocumentParser()->delete(resource: UserProfileResource::class, id: '1')],
    arguments: ['user_profile_id' => 1],
);

// 9. Чего нет: замена (`{lid}`) и удаление (`null`) компонента в PATCH. Обе — «полная замена связи», её исполнитель
//    (`EntityReplaceWriter`) пишет DELETE сам и про источник не знает; прототип источников её отклоняет
write(
    pdo: $pdo,
    title: '9. PATCH /user-profiles/2: remove the tracking component (not supported)',
    roots: [
        new WriteDocumentParser()->update(
            document: [
                'data' => [
                    'type' => 'user-profile',
                    'id' => '2',
                    'relationships' => ['tracking' => ['data' => null]],
                ],
            ],
            resource: UserProfileResource::class,
            id: '2',
        ),
    ],
    arguments: ['user_profile_id' => 2],
);

// 10. Атрибут только для чтения: `id` в атрибутах — парсер отклоняет документ до плана. Режима «только запись» (пароль)
//     и «только при вставке» (логин в Trackpoint) у атрибута нет
echo "\n######## 10. PATCH /user-profiles/2: read-only attribute\n\n";
try {
    new WriteDocumentParser()->update(
        document: ['data' => ['type' => 'user-profile', 'id' => '2', 'attributes' => ['id' => 5]]],
        resource: UserProfileResource::class,
        id: '2',
    );
} catch (InvalidDocumentException $exception) {
    echo "#### response\n", json(['errors' => [$exception->toError()]]), "\n";
}
