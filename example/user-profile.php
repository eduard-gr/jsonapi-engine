<?php

declare(strict_types=1);

/**
 * Пример: сценарий Trackpoint `UserProfilePreparedStatement` на этой библиотеке. Там один «профиль пользователя»
 * собирался из четырёх `QueryInterface`, соединённых в PHP по `pbup_id`: таблица профиля и три компонента из общей
 * таблицы `view_pb_user_component`, которые различаются константой `pbcom_id` (карта — 70, трекинг — 1200, обзор
 * пакетов — 1021), а атрибуты лежат в JSON-колонке `pbucom_data`.
 *
 * Здесь то же самое:
 *  - профиль — обычный ресурс над таблицей (`UserProfilesEntity`);
 *  - каждый компонент — ресурс над своей сущностью общей таблицы `user_components`. Вид компонента — постоянная
 *    колонка сущности (`component_id` с `'mode' => self::READ | self::FIXED` и `'value' => 70`): константу объявляет
 *    описание, а в каждый запрос её подставляет движок — условием при чтении, изменении и удалении, значением при
 *    вставке. Писать SQL руками для этого не нужно;
 *  - атрибуты компонента лежат в JSON-колонке `data`: их раскладывает и собирает сам ресурс методами `serialize()` и
 *    `deserialize()` (`SerializeInterface`, `DeserializeInterface`);
 *  - связь профиля с компонентом — 1:1 по владению (`OneToOne`), id компонента — id профиля. Профиль и все его
 *    компоненты читаются одним SQL-запросом: три `LEFT JOIN` одной таблицы, каждый со своим видом.
 *
 * Соответствие Trackpoint → пример: `pbup_id` → `user_profiles.id` / `user_components.user_profile_id`,
 * `pbcom_id` → `component_id`, `pbucom_data` → `data`; плоский кортеж из четырёх интерфейсов → `data` + `included`;
 * условие `pbup_id = …`, которое получал каждый интерфейс, → условие профиля (`filter[id]`, добавляет контроллер),
 * компоненты привязаны к нему соединением.
 *
 * Отличия от Trackpoint:
 *  - PATCH не перечитывает ресурс (ответ `204`): интерфейс без изменённых атрибутов там выполнял SELECT, здесь профиль
 *    без атрибутов только проверяется на существование, а не названные в документе компоненты не трогаются вовсе;
 *  - удаление профиля убирает компоненты каскадом базы (`ON DELETE CASCADE`).
 * Изменение компонента заменяет JSON целиком — как в Trackpoint: ключи, которых нет в документе, пропадают.
 *
 * Раньше компоненты были чужими источниками (`ForeignDataSource`), которые сами писали SQL со своей константой;
 * пример чужих источников — `example/source-document.php`.
 *
 * Чего библиотека не повторяет — см. сценарий 10: режимов «только запись» (`pbup_password`) и «только при вставке»
 * (`pbup_login`) у атрибута нет, поэтому пароля в ресурсе нет, а логин изменяем.
 *
 * База — SQLite в памяти, создаётся здесь же. Всё в одном файле.
 *
 * Запуск: php example/user-profile.php 2>sql.log   (SQL записи — в stderr)
 */

namespace Ggs\JsonApiEngine\Example\UserProfile;

use Ggs\JsonApiEngine\Document\ResourceRecord;
use Ggs\JsonApiEngine\Example\Container\ArrayContainer;
use Ggs\JsonApiEngine\Executor\Write\WriteNotFoundException;
use Ggs\JsonApiEngine\Executor\Select\ReaderBuilder;
use Ggs\JsonApiEngine\Executor\Write\WriterBuilder;
use Ggs\JsonApiEngine\Model\DatabaseEntitySource;
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
use Ggs\JsonApiEngine\Resource\DeserializeInterface;
use Ggs\JsonApiEngine\Resource\InsertableInterface;
use Ggs\JsonApiEngine\Resource\OneToOne;
use Ggs\JsonApiEngine\Resource\SerializeInterface;
use Ggs\JsonApiEngine\Resource\UpdatableInterface;
use LogicException;
use PDO;
use Psr\Container\ContainerInterface;

use function Ggs\JsonApiEngine\Example\SourceDocument\compactJson;
use function Ggs\JsonApiEngine\Example\SourceDocument\executionTree;
use function Ggs\JsonApiEngine\Example\SourceDocument\json;
use function Ggs\JsonApiEngine\Example\SourceDocument\queries;
use function Ggs\JsonApiEngine\Example\SourceDocument\stateChanges;

// Функции печати из примера чужих источников; его сценарии при подключении не запускаются
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
// Сущности компонентов
// =====================================================================================================================

/**
 * Настройки карты пользователя, компонент 70 (Trackpoint: `UserMapQueryInterface`).
 *
 * Компонент — одна строка общей таблицы `user_components` на пару (пользователь, вид компонента); пара к
 * `UserComponentRepository` + `User…QueryInterface` из Trackpoint. Вид компонента сущность объявляет постоянной
 * колонкой: `component_id` с `'mode' => self::READ | self::FIXED` и своим `value`. Такая сущность — не вся таблица,
 * а её строки этого вида, и константу в запросы подставляет движок: условием при чтении (подзапрос на месте
 * таблицы), изменении и удалении, значением при вставке. `user_profile_id` уникален только среди строк своего
 * вида — поэтому он первичный ключ сущности. У трёх видов — три сущности над одной таблицей.
 */
final class UserMapEntity extends DatabaseEntitySource
{
    public const string TABLE = 'user_components';
    public const string USER_PROFILE_ID = 'user_profile_id';
    public const string COMPONENT_ID = 'component_id';
    public const string DATA = 'data';

    public const array COLUMNS = [
        self::USER_PROFILE_ID => ['type' => self::INT, 'constraint' => self::PRIMARY],
        self::COMPONENT_ID => ['type' => self::INT, 'mode' => self::READ | self::FIXED, 'value' => 70],
        self::DATA => ['type' => self::STRING],
    ];
}

/**
 * Настройки трекинга, компонент 1200 (Trackpoint: `UserTrackingQueryInterface`).
 */
final class UserTrackingEntity extends DatabaseEntitySource
{
    public const string TABLE = 'user_components';
    public const string USER_PROFILE_ID = 'user_profile_id';
    public const string COMPONENT_ID = 'component_id';
    public const string DATA = 'data';

    public const array COLUMNS = [
        self::USER_PROFILE_ID => ['type' => self::INT, 'constraint' => self::PRIMARY],
        self::COMPONENT_ID => ['type' => self::INT, 'mode' => self::READ | self::FIXED, 'value' => 1200],
        self::DATA => ['type' => self::STRING],
    ];
}

/**
 * Обзор пакетов, компонент 1021 (Trackpoint: `UserPackageOverviewQueryInterface`).
 */
final class UserPackageOverviewEntity extends DatabaseEntitySource
{
    public const string TABLE = 'user_components';
    public const string USER_PROFILE_ID = 'user_profile_id';
    public const string COMPONENT_ID = 'component_id';
    public const string DATA = 'data';

    public const array COLUMNS = [
        self::USER_PROFILE_ID => ['type' => self::INT, 'constraint' => self::PRIMARY],
        self::COMPONENT_ID => ['type' => self::INT, 'mode' => self::READ | self::FIXED, 'value' => 1021],
        self::DATA => ['type' => self::STRING],
    ];
}

// =====================================================================================================================
// Ресурсы
// =====================================================================================================================

/**
 * Ресурс компонента: id — id профиля, атрибуты — ключи JSON из колонки `data`. Наследник объявляет `TYPE`,
 * `ATTRIBUTES` (каждый атрибут — составной над колонкой `data`) и сущность своего вида.
 *
 * По описанию такой атрибут не собрать и не записать, поэтому ресурс делает это сам:
 *  - `serialize()` (`SerializeInterface`) раскладывает JSON по запрошенным атрибутам; ключа нет — значение из
 *    `DEFAULTS` наследника или null;
 *  - `deserialize()` (`DeserializeInterface`) собирает присланные атрибуты в JSON. При изменении колонка `data`
 *    заменяется целиком (как в Trackpoint): ключи, которых нет в документе, из JSON пропадают и читаются
 *    значением по умолчанию. Атрибутов в документе нет — колонка не трогается.
 * Фильтровать и сортировать по ключам JSON нельзя: это не колонки.
 */
abstract class UserComponentResource extends Resource implements
    InsertableInterface,
    UpdatableInterface,
    DeletableInterface,
    SerializeInterface,
    DeserializeInterface
{
    /** @var array<string, mixed> значения атрибутов, которых нет в JSON; наследник переопределяет */
    protected const array DEFAULTS = [];

    /**
     * Сущность компонента — одна из трёх над таблицей `user_components`; у всех одни и те же имена колонок.
     *
     * @return class-string<UserMapEntity|UserTrackingEntity|UserPackageOverviewEntity>
     */
    abstract public static function getModel(): string;

    #[\Override]
    public function serialize(array $tuple, array $fields): ResourceRecord
    {
        $entity = static::getModel();
        $decoded = json_decode((string) $tuple[$entity::DATA], true);
        $data = is_array($decoded) ? $decoded : [];
        $attributes = [];
        foreach ($fields as $name) {
            $attributes[$name] = $data[$name] ?? static::DEFAULTS[$name] ?? null;
        }

        return new ResourceRecord(
            id: (int) $tuple[$entity::USER_PROFILE_ID],
            type: static::TYPE,
            attributes: $attributes,
            relationships: [],
        );
    }

    #[\Override]
    public function deserialize(array $attributes, array $references, bool $is_insert, ?int $id, array $link): array
    {
        // Изменение без атрибутов — компонент только ищется; у нового компонента JSON есть всегда (колонка NOT NULL)
        if ($attributes === [] && !$is_insert) {
            return [];
        }

        return [static::getModel()::DATA => compactJson($attributes === [] ? new \stdClass() : $attributes)];
    }
}

/**
 * `user-map`: настройки карты, id — id профиля. Он же — условие для корня `/user-profiles/{id}/map` (`filter[id]`).
 */
final class UserMapResource extends UserComponentResource
{
    public const string TYPE = 'user-map';
    public const string ID = 'id';
    public const string MAP = 'map';

    public const array ATTRIBUTES = [
        self::ID => [
            'column' => UserMapEntity::USER_PROFILE_ID,
            'type' => self::INT,
            'constraint' => self::PRIMARY,
            'filter' => [Operator::Eq],
        ],
        self::MAP => ['columns' => [UserMapEntity::DATA], 'type' => self::STRING, 'nullable' => true],
    ];

    #[\Override]
    public static function getModel(): string
    {
        return UserMapEntity::class;
    }
}

/**
 * `user-tracking`: настройки трекинга, id — id профиля. Флаг, которого нет в JSON, — true.
 */
final class UserTrackingResource extends UserComponentResource
{
    public const string TYPE = 'user-tracking';
    public const string ID = 'id';
    public const string MARKER_TAIL = 'marker_tail';
    public const string CLUSTERED_MARKER = 'clustered_marker';
    public const string FIT_LAYER = 'fit_layer';

    public const array ATTRIBUTES = [
        self::ID => ['column' => UserTrackingEntity::USER_PROFILE_ID, 'type' => self::INT, 'constraint' => self::PRIMARY],
        self::MARKER_TAIL => ['columns' => [UserTrackingEntity::DATA], 'type' => self::BOOL],
        self::CLUSTERED_MARKER => ['columns' => [UserTrackingEntity::DATA], 'type' => self::BOOL],
        self::FIT_LAYER => ['columns' => [UserTrackingEntity::DATA], 'type' => self::BOOL],
    ];

    protected const array DEFAULTS = [
        self::MARKER_TAIL => true,
        self::CLUSTERED_MARKER => true,
        self::FIT_LAYER => true,
    ];

    #[\Override]
    public static function getModel(): string
    {
        return UserTrackingEntity::class;
    }
}

/**
 * `user-package-overview`: обзор пакетов, id — id профиля.
 */
final class UserPackageOverviewResource extends UserComponentResource
{
    public const string TYPE = 'user-package-overview';
    public const string ID = 'id';
    public const string MODE = 'mode';

    public const array ATTRIBUTES = [
        self::ID => [
            'column' => UserPackageOverviewEntity::USER_PROFILE_ID,
            'type' => self::INT,
            'constraint' => self::PRIMARY,
        ],
        self::MODE => ['columns' => [UserPackageOverviewEntity::DATA], 'type' => self::STRING, 'nullable' => true],
    ];

    #[\Override]
    public static function getModel(): string
    {
        return UserPackageOverviewEntity::class;
    }
}

/**
 * `user-profile`: таблица `user_profiles` и три компонента по владению (1:1, каждый — своя сущность общей таблицы).
 * Порядок объявления связей — порядок записи компонентов.
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
                relation: [UserProfilesEntity::ID, UserMapEntity::USER_PROFILE_ID],
            ),
            self::TRACKING => new OneToOne(
                resource: UserTrackingResource::class,
                relation: [UserProfilesEntity::ID, UserTrackingEntity::USER_PROFILE_ID],
            ),
            self::PACKAGE_OVERVIEW => new OneToOne(
                resource: UserPackageOverviewResource::class,
                relation: [UserProfilesEntity::ID, UserPackageOverviewEntity::USER_PROFILE_ID],
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
 * Контейнер зависимостей (PSR-11) с объектами ресурсов: сборщики берут их по имени класса. В приложении их создаёт
 * и связывает его собственный контейнер.
 */
function container(): ContainerInterface
{
    return new ArrayContainer(
        services: [
            new UserProfileResource(),
            new UserMapResource(),
            new UserTrackingResource(),
            new UserPackageOverviewResource(),
        ],
    );
}

/**
 * Сценарий выборки: URL → дерево запроса → план → документ.
 *
 * @param class-string<Resource> $resource корневой ресурс
 * @param array<string, mixed>   $query    параметры запроса, которые контроллер добавил из маршрута (`filter[id]`)
 */
function select(PDO $pdo, string $title, string $resource, string $url, array $query = []): void
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $client_query);
    echo "\n######## {$title}\nGET {$url}\n\n";

    $tree = new QueryParser()->parse(resource: $resource, query: array_replace_recursive($client_query, $query));
    $plan = new SelectPlanner()->plan(tree: $tree)['sql'];
    echo "#### plan\n", SelectPlanPrinter::getTree(node: $plan), "\n#### queries\n", queries(node: $plan), "\n";

    $builder = new ReaderBuilder(
        pdo: $pdo,
        container: container(),
    );
    $document = json($builder->getDocument(plan: $plan));
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
 * Сценарий записи: дерево операций → план → дерево исполнителей → состояние таблиц; SQL — в stderr.
 * Всё в одной транзакции, в конце откат.
 *
 * @param list<WriteOperation> $roots корни дерева операций (от `WriteDocumentParser`)
 */
function write(PDO $pdo, string $title, array $roots): void
{
    echo "\n######## {$title}\n\n";
    $before = state(pdo: $pdo);
    $pdo->beginTransaction();
    try {
        $plan = new WritePlanner()->plan(resource: UserProfileResource::class, roots: $roots);
        $builder = new WriterBuilder(
            pdo: $pdo,
            lock: false,
            container: container(),
        );
        $writer = $builder->getRoot(plan: $plan, data: $roots);
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
// Описания проходят обычные проверки

$descriptions = [
    UserProfilesEntity::class,
    UserMapEntity::class,
    UserTrackingEntity::class,
    UserPackageOverviewEntity::class,
    UserProfileResource::class,
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

// 1. Один профиль со всеми компонентами — один SQL-запрос: три LEFT JOIN таблицы user_components, каждый со своим
//    видом (подзапрос с component_id на месте таблицы). id из маршрута — условие профиля (filter[id], добавляет
//    контроллер); компоненты привязаны соединением. У профиля 2 в JSON трекинга один флаг — остальные из DEFAULTS;
//    обзора пакетов нет — `package_overview: null`
select(
    pdo: $pdo,
    title: '1. one profile with its components',
    resource: UserProfileResource::class,
    url: '/user-profiles/2?include=map,tracking,package_overview',
    query: ['filter' => ['id' => '2']],
);

// 2. Список: тот же один запрос. Карта неактивного профиля 4 в ответ не попадает — её профиль не прошёл фильтр
select(
    pdo: $pdo,
    title: '2. list of profiles with their components',
    resource: UserProfileResource::class,
    url: '/user-profiles?include=map,tracking&filter[active]=true&fields[user-profile]=login',
);

// 3. Компонент — корень: таблица профиля в запрос не входит, id из маршрута — условие компонента (filter[id])
select(
    pdo: $pdo,
    title: '3. component as the root',
    resource: UserMapResource::class,
    url: '/user-profiles/1/map',
    query: ['filter' => ['id' => '1']],
);

// ---------------------------------------------------------------------------------------------------------------------
// Запись

// 4. POST (Trackpoint: DQL::INSERT): профиль и компоненты — INSERT движка (stderr). Новый id профиля приходит каждому
//    компоненту колонкой связи; вид компонента (component_id) в каждый INSERT добавляет движок по описанию сущности
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

// 5. PATCH (Trackpoint: DQL::UPDATE с данными для двух интерфейсов): профиль и карта — по UPDATE движка, у карты
//    с условием component_id = 70; трекинг в документе не назван и не трогается
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
);

// 6. PATCH только компонента (Trackpoint: интерфейс без данных выполнял SELECT вместо UPDATE): профиль без атрибутов
//    только проверяется на существование; JSON трекинга заменяется целиком — флаг fit_layer, которого нет в
//    документе, из JSON пропадает и дальше читается значением по умолчанию
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
);

// 7. PATCH компонента, которого нет: у профиля 3 нет карты — UPDATE и SELECT с component_id = 70 ничего не находят,
//    движок отвечает 404
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
);

// 8. DELETE (Trackpoint: DQL::DELETE по каждому интерфейсу): удаляется строка профиля, компоненты — каскадом базы
write(
    pdo: $pdo,
    title: '8. DELETE /user-profiles/1',
    roots: [new WriteDocumentParser()->delete(resource: UserProfileResource::class, id: '1')],
);

// 9. Удаление компонента в PATCH (`null`) — «полная замена связи»: `EntityReplaceWriter` удаляет строку с условием
//    component_id = 1200, карта того же профиля в той же таблице остаётся. С чужими источниками это было невозможно
write(
    pdo: $pdo,
    title: '9. PATCH /user-profiles/2: remove the tracking component',
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
