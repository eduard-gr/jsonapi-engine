# JSON:API Engine

PHP-библиотека, реализующая JSON API.

Библиотека принимает запрос в формате [JSON:API](https://jsonapi.org/) и описание ресурсов, строит по ним план выполнения и собирает ответ в формате JSON:API.

## Поддерживаемые операции

| Операция | Статус         |
|----------|----------------|
| `select` | В разработке   |
| `insert` | Запланировано  |
| `update` | Запланировано  |

В текущей версии разрабатывается только `select`.

## Состояние

Путь запроса собран целиком: URL → дерево запроса → план с SQL → дерево итераторов → документ JSON:API (см. [Как это работает](#как-это-работает)).

| Часть | Где | Состояние |
|---|---|---|
| Описание данных: сущности и модели, ресурсы | `src/Model/`, `src/Resource*` | готово; схемы endpoint'а пока нет — всё разрешает ресурс |
| 1. Парсер: запрос → дерево запроса | `src/Parser/` | готово |
| 2. Планировщик: дерево запроса → узлы плана, шаги | `src/Plan/`, `src/Plan/Select/` | готово |
| 3. SQL плана (pg_builder) | `Plan\SharedScanNode::withSelect()`, `Plan\Select\BuildSqlPass` | список колонок, FROM/ON, WHERE из фильтров, ORDER BY; нет цепочки предков, оператора `in`, параметров маршрута |
| 4. Сборка дерева итераторов по плану | `src/Executor/ExecutorBuilder.php` | готово |
| 5. Выполнение (итераторы Volcano), сериализация | `src/Executor/` | готово |
| 6. Документ JSON:API | `src/Document/` | готово |
| Точка входа библиотеки, транзакция, пагинация | — | нет; весь путь собирает контроллер примера `example/Controller/CertificatesController` |

Проверено на SQLite и PDO (`example/db`): документ по плану для запроса примера совпадает с эталоном `test/Fixture/document.json`. Целевая СУБД — PostgreSQL; против неё пока проверяются только сущности (`test/Integration`).

## Зависимости

| Зависимость | Назначение |
|-------------|------------|
| PHP 8.5+ | clone with в узлах плана |
| `ext-ds` ^2.0 | `Ds\Map`, `Ds\Set` в реестре ресурсов, документе и хеш-соединении. |
| `ext-pdo` | Узел `SqlScanIterator` выполняет SQL через PDO (пример — SQLite). |
| `ext-pgsql`, [`sad_spirit/pg_builder`](https://github.com/sad-spirit/pg-builder), [`sad_spirit/pg_wrapper`](https://github.com/sad-spirit/pg-wrapper) `^3.3` | Целевой стек для PostgreSQL: pg_builder строит SQL плана, pg_wrapper нужен `Model\EntityDatabaseValidator`. |

Psalm знает только ext-ds 1.x (в 2.0 нет интерфейса `Ds\Sequence`), поэтому классы 2.0 описаны заглушкой `stubs/ds.phpstub`, подключённой в `psalm.xml`.

## База данных примера

Все примеры ниже — на тестовой базе `example/db` (SQLite, `schema.sql`, данные создаёт `seed.php` детерминированно):

```sql
CREATE TABLE providers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(128) NOT NULL UNIQUE,
    website VARCHAR(255),
    created_at TIMESTAMP NOT NULL
);

CREATE TABLE certificates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    provider_id INT NOT NULL REFERENCES providers (id),
    code VARCHAR(64) NOT NULL UNIQUE,
    nominal DECIMAL(10, 2) NOT NULL,
    currency VARCHAR(3) NOT NULL DEFAULT 'RUB',
    status VARCHAR(32) NOT NULL DEFAULT 'ACTIVE',
    created_at TIMESTAMP NOT NULL
);

-- 1:1 с сертификатом, есть всегда
CREATE TABLE certificate_details (
    certificate_id INT PRIMARY KEY REFERENCES certificates (id),
    buyer_email VARCHAR(255) NOT NULL,
    recipient_name VARCHAR(128),
    recipient_email VARCHAR(255),
    greeting_text TEXT,
    design_template_id INT
);

-- 1:1 с деталями, может отсутствовать
CREATE TABLE certificate_delivery_meta (
    certificate_id INT PRIMARY KEY REFERENCES certificate_details (certificate_id),
    delivery_channel VARCHAR(32) NOT NULL,
    is_sent BOOLEAN NOT NULL DEFAULT FALSE,
    sent_at TIMESTAMP,
    external_provider_id VARCHAR(128),
    delivery_error_log TEXT
);

-- 1:N от сертификата
CREATE TABLE domains (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    certificate_id INT NOT NULL REFERENCES certificates (id),
    domain_name VARCHAR(255) NOT NULL UNIQUE,
    is_primary BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP NOT NULL
);

-- 1:1 с доменом
CREATE TABLE domain_configs (
    domain_id INT PRIMARY KEY REFERENCES domains (id),
    auto_renew BOOLEAN NOT NULL DEFAULT TRUE,
    dns_provider VARCHAR(64),
    ssl_redirect_enabled BOOLEAN DEFAULT TRUE,
    custom_nginx_config TEXT
);

-- 1:N от домена, может не быть ни одной
CREATE TABLE domain_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    domain_id INT NOT NULL REFERENCES domains (id),
    log_level VARCHAR(16) NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL
);
```

Над ней описаны ресурсы (`example/Resource/`); endpoint `GET /certificates` отдаёт корневой ресурс `CertificateResource`:

```
certificate ──provider──► provider         (к одному, внешний ключ certificates.provider_id)
            ──detail────► detail           (к одному, certificate_details)
            ──delivery──► delivery         (к одному, certificate_delivery_meta, может не быть)
            ──domains───► domain ──logs──► log   (ко многим, ко многим)
```

У `domain_configs` есть сущность, но нет ресурса.

## Как это работает

По запросу клиента и корневому ресурсу endpoint'а библиотека строит план. План — неизменяемое дерево узлов с SQL-запросами. По плану на каждое выполнение собирается дерево итераторов: SQL-запросы и операторы передают друг другу строки и ресурсы, а документ JSON:API получается обходом результата.

Связи «к одному» читаются тем же SQL-запросом, что и их родитель (`LEFT JOIN`): из одной строки строится несколько ресурсов. Связь «ко многим» через `JOIN` дала бы декартово произведение, поэтому она читается отдельным SQL-запросом, а дети привязываются к родителям в PHP по ключу связи. Ключи родителей из запроса в запрос не передаются: дочерний запрос повторяет условия родителя сам (см. [Цепочка предков](#цепочка-предков), пока не реализована).

```
GET /certificates?include=…&fields[…]=…&filter[…]=…&sort=…   + корневой ресурс (CertificateResource)
        │
        ▼
1. Парсинг и валидация   QueryParser::parse()            ──►  дерево запроса (массив)
        │                                                      ошибки клиента: 400 / 404
        ▼
2. Планирование          QueryPlanner: fromTree()         ──►  узлы плана (src/Plan)
                         шаги lift → order → close → merge
        │
        ▼
3. SQL                   шаг sql: BuildSqlPass             ──►  у каждого SharedScanNode: Select, parameters, columns
                         → SharedScanNode::withSelect()
        │
        ▼
4. Сборка исполнителя    ExecutorBuilder                   ──►  дерево итераторов (src/Executor) + ResourceRegistry
        │
        ▼
5. Выполнение            перебор корня (Volcano)           ──►  поток ResourceRecord, связи записаны
        │
        ▼
6. Документ              json_encode(Document)             ──►  {"data": […], "included": […]}
```

Весь путь собран в контроллере примера `example/Controller/CertificatesController`: `get($query)` возвращает `Document`, `plan($query)` — план после шага 3. `php example/certificates.php [URL]` печатает план и SQL, с `--document` — документ. Для запроса по умолчанию `include=provider,detail,delivery,domains.logs` документ совпадает с эталоном `test/Fixture/document.json` (`PlanTreeTest`).

Ошибки разделены по месту: ошибки клиента возникают только в парсере (`InvalidQueryException` — 400, `ResourceNotFoundException` — 404). Всё, что идёт после него, получает проверенное дерево, и несоответствие там — `LogicException`: ошибка кода, а не запроса.

### Модель Volcano

Исполнитель устроен по модели итераторов Volcano (Graefe), как исполнители реляционных СУБД: запрос — дерево операторов, каждый оператор — итератор, который по требованию отдаёт следующий элемент своего потока и сам берёт элементы у своих входов.

| Volcano | Здесь |
|---|---|
| `open()` | первый вызов `getIterator()`: создаётся генератор; `SqlScanIterator` выполняет запрос только при первом шаге генератора |
| `next()` | очередной шаг генератора (`foreach`, `current()`/`next()`) |
| `close()` | генератор закончился; повторно не открывается |
| оператор с 0 / 1 / 2 входами | лист `SqlScanIterator`; унарные `SerializeIterator`, `SharedDataIterator`, `SortIterator`; бинарные — соединения (`outer`, `inner`), `PreloadIterator` (`source`, `preload`), `SharedScanIterator` (`data`, `source`) |

Свойства модели, на которые опирается библиотека:

- **Pull, а не push.** Работу запускает потребитель: корень перебирает `json_encode()` документа, корень тянет свои входы, и так до листьев. Данные поднимаются снизу вверх по одному элементу; пока документ не начал перебор, ни один SQL-запрос не выполнен.
- **Конвейер и блокирующие операторы.** Большинство узлов потоковые: элемент проходит наверх, не дожидаясь остальных (`SerializeIterator`, `SharedRowJoinIterator`, `MergeNestJoinIterator`). Блокирующие узлы читают один вход целиком до первого элемента наверх: `HashNestJoinIterator` — `outer` в хеш-таблицу, `HashJoinIterator` — `inner`, `SortIterator` — весь вход. Память поэтому определяется блокирующими узлами и реестром, а не числом строк в потоке.
- **Фиксированное число входов.** Число входов узла видно по классу, и узел плана и его итератор называются по одной основе (`MergeNestJoinNode` → `MergeNestJoinIterator`): план — дерево операторов, дерево итераторов — его экземпляр на одно выполнение.

Чем модель отличается от классической:

- **Два вида элементов.** Снизу по потоку идут строки SQL (`array`), выше `SerializeIterator` — ресурсы (`ResourceRecord`). Какой поток у узла, написано в его описании («Поток: вход → выход»).
- **Одна строка — несколько ресурсов.** Классический оператор читает свой вход один; здесь строку одного SQL читают несколько `SerializeIterator` через `SharedDataIterator`, а `SharedScanIterator` сдвигает её один раз за такт. Тройка работает в ногу (lockstep): каждая ветка за такт отдаёт ровно один элемент, отсутствие ресурса — `null` (см. [Одна строка — несколько ресурсов](#одна-строка--несколько-ресурсов)).
- **Соединение не склеивает кортежи.** Выход соединения — элементы `outer`, которым дописана связь; связанные ресурсы в поток не копируются, а попадают в ответ через реестр и обход документа.
- **Побочный эффект — реестр.** `SerializeIterator` регистрирует каждый ресурс в общем `ResourceRegistry` (один объект на `type:id`); `PreloadIterator` дочитывает свой вход только ради этого эффекта.
- **Одноразовость.** Итераторы хранят состояние и выполняются один раз; повторный перебор молча пуст. План переиспользуется, дерево итераторов на каждое выполнение строит `ExecutorBuilder`.

### Модель оптимизатора

Планировщик (`Plan\Select\QueryPlanner`) — оптимизатор на правилах (rule-based), без модели стоимости и статистики: решения принимаются по форме дерева и описанию ресурсов, поэтому один запрос всегда даёт один и тот же план. План — дерево неизменяемых узлов, и оптимизация — последовательность переписываний дерева в новое дерево.

- **Неизменяемые узлы.** Свойства узлов `readonly`; «изменить» узел — получить копию через `with…()` (clone with). Тот же вход — тот же объект, поэтому нетронутые поддеревья переиспользуются, а результат каждого шага остаётся целым: `plan()` возвращает дерево после каждого шага (`php example/query-plan.php --steps`).
- **Шаг — `Pass`:** функция «дерево → дерево», `$step(plan: $plan)`. Проход видит всё дерево и может нести контекст при обходе (`SharedScanPass` знает, внутри строки он или нет; `BuildSqlPass` передаёт ключи соединений сверху вниз).
- **`Rule` — частный случай `Pass`:** локальное правило `apply(node)` смотрит только на узел и его прямые входы и возвращает замену или `null`. Обход написан один раз в `Rule`: снизу вверх (входы уже преобразованы, как `transformUp` в Spark Catalyst), полными кругами, пока за круг не случится ни одной замены (фиксированная точка). Замены считаются, деревья не сравниваются.
- **Порядок шагов фиксирован** (`getSteps()`), каждый рассчитан на результат предыдущих. Правило, которое меняет верх строки, должно идти до `close`: после него вход `SharedScanNode` — только `SharedRowJoinNode`/`ResourceNode`, иначе `LogicException`.

Шаги идут от логического плана к физическому:

| Шаг | Вид | Что решает |
|---|---|---|
| `nodes` — `fromTree()` | перевод | дерево запроса → узлы; вид соединения — по виду связи, include — левой цепочкой в порядке объявления |
| `lift` — `LiftBranchFromOuterRule` | логическое переписывание | ветки владельца строки поднимаются над строкой: все 1-1 собираются в одну строку |
| `order` — `PreloadBelowNestJoinRule` | логическое переписывание | порядок выполнения: справочник (`PreloadNode`) загружается до nest join |
| `close` — `SharedScanPass` | границы запросов | где кончается один SQL-запрос (`SharedScanNode`) и какой алгоритм 1:N возможен внутри строки (`HashJoinNode`) |
| `merge` — `HashToMergeRule` | выбор алгоритма | Hash → Merge, где обе стороны можно упорядочить по ключу; порядок у владельца один («интересный порядок», жадно снизу вверх) |
| `sql` — `BuildSqlPass` | генерация | `Select` (pg_builder), параметры и карта колонок у каждого владельца |

Чего оптимизатор не делает: не оценивает стоимость, не перебирает альтернативные планы, не переставляет соединения сверх `lift`/`order`. Неверное дерево на входе — ошибка кода (`LogicException`): клиентские ошибки отсеял парсер.

### 1. Парсинг и валидация

`QueryParser::parse(string $resource, array $query): array` разбирает параметры запроса по корневому ресурсу endpoint'а (`CertificateResource::class`) и строит дерево запроса.

```http
GET /certificates?include=provider,detail,delivery,domains.logs&fields[certificate]=code,status HTTP/1.1
Accept: application/vnd.api+json
```

Что клиенту можно, описывает только ресурс: всё, что в нём объявлено, доступно. Подключить можно любую связь из `relationships()` (на любую глубину), выбрать — любые публичные атрибуты, фильтровать и сортировать — по атрибутам, где ресурс объявил `filter`/`sort`. Схемы endpoint'а, которая сужала бы это, пока нет: её добавим, когда всё остальное будет готово.

#### Дерево запроса

Корень дерева — ресурс endpoint'а, дочерние узлы — подключённые через `include` ресурсы. `php example/query-tree.php` без аргумента печатает дерево для `GET /certificates?include=provider,detail,delivery,domains.logs` (без `fields`, поэтому у каждого ресурса все публичные атрибуты); `php example/query-tree.php '<URL>'` — дерево любого запроса. То же дерево записано константой `TREE` в `example/query-plan.php`, вход планировщика без парсера:

```php
[
    'resource' => CertificateResource::class,
    'fields' => ['code', 'nominal', 'currency', 'status', 'created_at', 'provider'],
    'filters' => [],
    'sort' => [],
    'includes' => [
        'provider' => [
            'type' => QueryParser::MANY_TO_ONE,
            'relation' => ['provider_id', 'id'],
            'resource' => [
                'resource' => ProviderResource::class,
                'fields' => ['name', 'website', 'created_at'],
                'filters' => [],
                'sort' => [],
                'includes' => [],
            ],
        ],
        'detail' => [
            'type' => QueryParser::ONE_TO_ONE,
            'relation' => ['id', 'certificate_id'],
            'resource' => [
                'resource' => DetailResource::class,
                'fields' => ['buyer_email', 'recipient_name', 'recipient_email', 'greeting_text', 'design_template_id'],
                'filters' => [],
                'sort' => [],
                'includes' => [],
            ],
        ],
        'delivery' => [
            'type' => QueryParser::ONE_TO_ONE,
            'relation' => ['id', 'certificate_id'],
            'resource' => [
                'resource' => DeliveryResource::class,
                'fields' => ['delivery_channel', 'is_sent', 'external_provider_id'],
                'filters' => [],
                'sort' => [],
                'includes' => [],
            ],
        ],
        'domains' => [
            'type' => QueryParser::ONE_TO_MANY,
            'relation' => ['id', 'certificate_id'],
            'resource' => [
                'resource' => DomainResource::class,
                'fields' => ['domain_name', 'is_primary', 'created_at'],
                'filters' => [],
                'sort' => [],
                'includes' => [
                    'logs' => [
                        'type' => QueryParser::ONE_TO_MANY,
                        'relation' => ['id', 'domain_id'],
                        'resource' => [
                            'resource' => LogResource::class,
                            'fields' => ['log_level', 'message', 'created_at'],
                            'filters' => [],
                            'sort' => [],
                            'includes' => [],
                        ],
                    ],
                ],
            ],
        ],
    ],
]
```

С `fields[certificate]=code,status` из запроса выше у корня было бы `'fields' => ['code', 'status']`; остальное дерево то же.

| Поле узла | Описание |
|---|---|
| `resource` | Класс ресурса: у корня — ресурс endpoint'а, у остальных — из связи родителя. |
| `fields` | Выбранные атрибуты и связи по внешнему ключу (имена в API). Без `fields[<type>]` — все публичные атрибуты ресурса (`publicAttributes()`), в том числе связи по внешнему ключу (`provider`). |
| `filters` | `[{attribute, operator, value}]`, объединяются через AND. Значение уже приведено по `type` атрибута; у `in` — список. |
| `sort` | `[{attribute, descending, position}]` — поля сортировки этого ресурса; `position` — место поля в параметре `sort`, общий порядок полей разных ресурсов. |
| `includes` | Ключ — имя связи; значение — вид связи (`ONE_TO_ONE`/`MANY_TO_ONE`/`ONE_TO_MANY`, по классу связи `OneToOne`/`ManyToOne`/`OneToMany`), её колонки `relation` (`[колонка родителя, колонка ресурса]`) и узел ресурса. |

В дереве только атрибуты, без колонок: атрибут в колонки модели переводит планировщик. Путь узла и его тип в дереве не хранятся: тип есть у ресурса, путь вычисляется при обходе. Структура дерева заморожена: планировщик берёт из него данные как есть.

Правила:

- **Параметры запроса:** только `include`, `fields`, `filter`, `sort`. Любой другой (`page`, …) — 400: JSON:API требует отвечать ошибкой на неподдерживаемые параметры.
- **`include`:** путь `domains.logs` подключает и `domains`, и `logs`. Имя — любая связь из `relationships()` ресурса родителя. Нет такой связи — 400.
- **`fields[<type>]`:** `<type>` — JSON:API `type` ресурса (`fields[certificate]`, `fields[domain]`). Имя не из публичных атрибутов ресурса или ресурс, не подключённый через `include`, — 400.
- **Циклы:** класс ресурса не может повториться на пути от корня до узла (`include=domains.certificate.domains`) — 400 с путём, где найден цикл. В разных ветках один класс допустим.
- **Параметры маршрута** (`/certificates/1` → `['id' => '1']`) парсер не разбирает: их передают планировщику отдельно (пока не реализовано).

#### `filter`

Фильтр объявляет ресурс: `filter` в описании атрибута — разрешённые операторы, case'ы enum `Operator`. Клиенту доступны ровно эти фильтры и операторы. В примере объявлены `certificate.status` (`eq`, `ne`), `certificate.created_at` (`gte`, `lt`) и `delivery.is_sent` (`eq`):

```php
// в CertificateResource::ATTRIBUTES
self::STATUS => [
    'column' => CertificatesEntity::STATUS,
    'type' => self::STRING,
    'filter' => [Operator::Eq, Operator::Ne],
],
```

| Оператор | `Operator` | SQL |
|----------|------------|-----|
| `eq` | `Eq` | `=` |
| `ne` | `Ne` | `<>` (TODO: строки с `NULL` в колонке не находятся) |
| `lt` | `Lt` | `<` |
| `gt` | `Gt` | `>` |
| `lte` | `Lte` | `<=` |
| `gte` | `Gte` | `>=` |
| `in` | `In` | значение из списка (TODO: парсер принимает, в SQL плана пока `LogicException`) |

Синтаксис — `filter[<путь>.<атрибут>][<оператор>]=<значение>`:

```
filter[status]=ACTIVE                         → eq по умолчанию
filter[status][in]=ACTIVE,USED                → in, значения через запятую
filter[status][in][]=ACTIVE&filter[status][in][]=USED
filter[created_at][gte]=2026-01-01
filter[delivery.is_sent]=true                 → фильтр подключённого ресурса
```

- Фильтр должен быть разрешён ресурсом (`Unknown filter`), оператор — из `filter` атрибута, значение — соответствовать `type` (`INT` — целое, `BOOL` — `true`/`false`/`1`/`0`, `DATE` — `YYYY-MM-DD`, `DATETIME` — ISO 8601). Иначе 400.
- Строка — валидный UTF-8 без нулевых байтов: драйвер обрезал бы значение на `\0`.
- Пустой список `in` — 400. Несколько условий одного или разных атрибутов объединяются через AND.

#### `sort`

Параметр JSON:API `sort`: поля через запятую, `-` — по убыванию, поле подключённого ресурса — с путём:

```
sort=-created_at,provider.name,domains.domain_name
```

Сортировать можно по атрибутам с `'sort' => true` в ресурсе. Поле попадает в `sort` своего ресурса в дереве; `position` хранит общий порядок. Поле без разрешения, ресурс не из `include`, пустое поле или повтор — 400. `NULL` сортируется по правилам PostgreSQL: последним по возрастанию, первым по убыванию. В SQL сортировка идёт только у владельца строки (см. [ORDER BY](#order-by)).

### 2. Планирование

План (`src/Plan`, перенесено из прототипа `../php-tests/src/test24.php`) отделён от выполнения (`src/Executor`): план — неизменяемые узлы без состояния, которые строит и переписывает планировщик; итераторы — состояние одного выполнения. План можно выполнять сколько угодно раз, дерево итераторов — один. `Executor` зависит от `Plan`, но не наоборот.

Как устроены шаги и почему план без модели стоимости — [Модель оптимизатора](#модель-оптимизатора).

#### Узлы плана

Число входов узла задаёт его класс (модель Volcano). Узел плана и итератор, который его выполняет, называются по одной основе (`HashNestJoinNode` → `HashNestJoinIterator`).

| Узел | Входы | Что значит | Откуда берётся |
|---|---|---|---|
| `ResourceNode(resource, fields, filters, sort)` | 0 | Таблица ресурса: модель данных ресурса в FROM запроса, которым владеет ближайший `SharedScanNode` над ним. Своего запроса нет. | ресурс дерева запроса |
| `SharedScanNode(input, order, select, columns, parameters)` | 1 | Владелец одного SQL-запроса, даже из одного ресурса. Вход — верх строки: `SharedRowJoinNode` (ресурс со своими 1-1) или `ResourceNode`. Самый левый `ResourceNode` — владелец строки (`rowOwner()`). Хранит порядок потока, `Select`, его параметры и карту колонок строки. | шаг `close` |
| `SharedRowJoinNode` | 2 | 1-1 в той же строке: inner присоединяется `LEFT JOIN`. | `ONE_TO_ONE` |
| `PreloadNode` | 2 | Многие к одному: inner — отдельный запрос, загружается в реестр после outer. | `MANY_TO_ONE` |
| `HashNestJoinNode` | 2 | 1:N над строкой: хеш по outer, inner — отдельный запрос. | `ONE_TO_MANY` |
| `MergeNestJoinNode` | 2 | 1:N слиянием: обе стороны упорядочены по ключу связи. | шаг `merge` |
| `HashJoinNode` | 2 | 1:N у 1-1 ресурса внутри строки: outer — поток строки, его нельзя читать наперёд, поэтому хеш по inner. | шаг `close` |

Соединение — `JoinNode(name, relation, outer, inner)`: `name` — имя связи в документе, `relation` — `[колонка outer, колонка inner]` (колонки моделей ресурсов). Изменить узел нельзя, только получить копию через `with…()` (clone with, PHP 8.5); тот же вход — тот же объект, поэтому нетронутые поддеревья переиспользуются.

#### Шаги

`QueryPlanner::plan($tree)` возвращает дерево после каждого шага (`nodes`, `lift`, `order`, `close`, `merge`, `sql`); `QueryPlanPrinter::tree()` печатает любое из них. Шаг — `Pass` (дерево → дерево); `Rule` — локальное правило, которое применяется снизу вверх до фиксированной точки.

**`nodes` — `fromTree()`.** Ресурс дерева запроса становится `ResourceNode`, каждый include — соединением по виду связи. Include навешиваются левой цепочкой в порядке объявления: каждый следующий — новый корень, всё построенное до него — его outer.

```
HashNestJoinNode domains
  outer: SharedRowJoinNode delivery
    outer: SharedRowJoinNode detail
      outer: PreloadNode provider
        outer: ResourceNode CertificateResource
        inner: ResourceNode ProviderResource
      inner: ResourceNode DetailResource
    inner: ResourceNode DeliveryResource
  inner: HashNestJoinNode logs
    outer: ResourceNode DomainResource
    inner: ResourceNode LogResource
```

**`lift` — `LiftBranchFromOuterRule`.** Ветка в outer общей строки поднимается над ней: `SharedRowJoin(Branch(A, C), B) → Branch(SharedRowJoin(A, B), C)`. Так все 1-1 оказываются под ветками, даже если ветка объявлена раньше (`provider` до `detail`). Ключ ветки по-прежнему берётся у записей A. Ветки 1-1 ресурса (из inner) не поднимаются: их outer — другой ресурс, они остаются внутри строки.

**`order` — `PreloadBelowNestJoinRule`.** `PreloadNode` опускается под nest join, ближе к строке: `Preload(NestJoin(A, B), C) → NestJoin(Preload(A, C), B)`. Меняется только порядок выполнения: справочник загружается до nest join.

**`close` — `SharedScanPass`.** Над каждым запросом ставится его владелец `SharedScanNode`: над верхом каждой строки и над каждым `ResourceNode` вне строки. `HashNestJoinNode` внутри строки становится `HashJoinNode`.

**`merge` — `HashToMergeRule`.** `HashNestJoinNode` → `MergeNestJoinNode`, если обе стороны можно упорядочить по ключу связи: outer — по колонке родителя, inner — по колонке ребёнка. Порядок задаёт только владелец запроса (`order`); у него одна ориентация, и занятый другим Merge или клиентским `sort` владелец отказывает. Правило идёт снизу вверх, поэтому глубокие соединения занимают порядок первыми: `logs` получает Merge (домены по `id`), а `domains` над ним остаётся Hash. TODO: колонки порядка не квалифицированы ресурсом.

План после `merge` для `include=provider,detail,delivery,domains.logs`:

```
HashNestJoinNode domains
  outer: PreloadNode provider
    outer: SharedScanNode row CertificateResource
      input: SharedRowJoinNode delivery
        outer: SharedRowJoinNode detail
          outer: ResourceNode CertificateResource
          inner: ResourceNode DetailResource
        inner: ResourceNode DeliveryResource
    inner: SharedScanNode row ProviderResource
      input: ResourceNode ProviderResource
  inner: MergeNestJoinNode logs
    outer: SharedScanNode row DomainResource (order by id)
      input: ResourceNode DomainResource
    inner: SharedScanNode row LogResource (order by domain_id)
      input: ResourceNode LogResource
```

Четыре владельца — четыре SQL-запроса: сертификаты с деталями и доставкой одной строкой, провайдеры, домены, логи.

### 3. SQL

Последний шаг `sql` — `BuildSqlPass`: SQL появляется в плане только здесь, когда дерево уже окончательное. Проход идёт сверху вниз и передаёт ключи соединений к владельцам, которые сами их не видят: соединение отдаёт в outer пришедшие сверху ключи и свой `relation[0]`, в inner — только `relation[1]`. По outer ключи доходят до владельца строки, к которой присоединяется ветка (`provider_id` и `id` — до строки сертификатов). Каждый владелец собирает свой запрос сам: `SharedScanNode::withSelect($keys)` обходит свою строку слева направо, ресурсы получают алиасы `t0`, `t1`… (таблицы внутри модели ресурса — `t0_1`, `t0_2`…, их задаёт `ModelTree`). SQL строится узлами [pg_builder](https://github.com/sad-spirit/pg-builder), без склейки строк.

#### Список колонок и FROM

- У каждого ресурса строки: первичный ключ (он даёт `id`, а `NULL` в нём — ресурса в строке нет), колонки атрибутов `fields` (у составного — все, у связи по внешнему ключу — колонка ключа) и ключи соединений.
- Колонка выводится как `<алиас ресурса>_<колонка модели>` (`t1_buyer_email`): имена не совпадают, даже если у двух таблиц есть `certificate_id`.
- FROM — `ModelTree::from()` каждого ресурса, только с нужными таблицами (`LEFT JOIN` модели без нужных колонок отбрасывается). Нужны колонки списка, колонки фильтров и (у владельца) колонки `ORDER BY`; последние две в список не выводятся.
- 1-1 ресурсы присоединяются `LEFT JOIN`, в `ON` — только условие связи `relation`.

#### WHERE

Фильтры ресурсов строки, все через `AND`; в `ON` они не идут:

- фильтр владельца строки (`t0`) — условие как есть: `t0.status = :t0_status_0`;
- фильтры 1-1 ресурса — одной группой `(t1.pk IS NULL OR (…))`: строка без этого ресурса остаётся, а строка, где ресурс есть, но не прошёл фильтр, выпадает;
- значение — именованный параметр `:<алиас>_<колонка>_<номер>`; значения лежат в `SharedScanNode::parameters` уже скалярами (`date` — `Y-m-d`, `datetime` — `Y-m-d H:i:s.uP`) и привязываются при выполнении.

#### ORDER BY

Порядок строк — порядок ресурса-владельца (`t0`); сортировки 1-1 ресурсов на него не влияют:

1. `order` — ключ, который занял `HashToMergeRule` (`MergeNestJoinIterator` проверяет порядок);
2. клиентский `sort` владельца по `position`, `DESC` у `descending`;
3. первичный ключ владельца, если его ещё нет: порядок однозначен от запуска к запуску.

#### Результат

У каждого владельца после шага: `select` (pg_builder `Select`), `parameters` и `columns` — по ресурсу строки слева направо: класс, алиас, карта «колонка модели → колонка строки», `fields` и ключи соединений. По `columns` итераторы собирают ресурсы из строки (см. [Сериализация](#сериализация)). `QueryPlanPrinter::queries()` печатает SQL и параметры:

```sql
-- сертификаты с деталями и доставкой (одна строка — три ресурса)
select t0.id as t0_id, t0.code as t0_code, t0.nominal as t0_nominal, t0.currency as t0_currency,
       t0.status as t0_status, t0.created_at as t0_created_at, t0.provider_id as t0_provider_id,
       t1.certificate_id as t1_certificate_id, t1.buyer_email as t1_buyer_email, …,
       t2.certificate_id as t2_certificate_id, t2.delivery_channel as t2_delivery_channel, …
from certificates as t0
left join certificate_details as t1 on t0.id = t1.certificate_id
left join certificate_delivery_meta as t2 on t0.id = t2.certificate_id
order by t0.id

-- провайдеры
select t0.id as t0_id, t0."name" as t0_name, t0.website as t0_website, t0.created_at as t0_created_at
from providers as t0 order by t0.id

-- домены: по id — для слияния с логами; certificate_id — ключ связи domains
select t0.id as t0_id, t0.domain_name as t0_domain_name, t0.is_primary as t0_is_primary,
       t0.created_at as t0_created_at, t0.certificate_id as t0_certificate_id
from domains as t0 order by t0.id

-- логи: по domain_id (order), затем по id
select t0.id as t0_id, t0.log_level as t0_log_level, t0.message as t0_message,
       t0.created_at as t0_created_at, t0.domain_id as t0_domain_id
from domain_logs as t0 order by t0.domain_id, t0.id
```

С фильтрами (`include=delivery&fields[certificate]=code,status&fields[delivery]=is_sent&filter[status]=ACTIVE&filter[delivery.is_sent]=true`):

```sql
select t0.id as t0_id, t0.code as t0_code, t0.status as t0_status,
       t1.certificate_id as t1_certificate_id, t1.is_sent as t1_is_sent
from certificates as t0
left join certificate_delivery_meta as t1 on t0.id = t1.certificate_id
where t0.status = :t0_status_0 and ( t1.certificate_id is null or t1.is_sent = :t1_is_sent_1 )
order by t0.id
-- parameters: {"t0_status_0": "ACTIVE", "t1_is_sent_1": true}
```

#### Цепочка предков

Не реализована: сейчас дочерние запросы не повторяют условия родителя и читают таблицы целиком, а лишние дети отбрасываются при соединении. Решение принято (CTE + `IN`).

Дочерний запрос не получает ключи родителей списком `IN (1, 2, …)`: он повторяет условия всех предков, и база возвращает только детей тех родителей, что попадут в ответ. Условия должны повторяться точно: слабее — лишние строки, сильнее — потерянные дети.

Каждый шаг через соединение не 1-1 (`HashNestJoinNode`, `MergeNestJoinNode`, `HashJoinNode`) — CTE и `IN (подзапрос)`:

- запрос outer становится CTE `p{n}` запроса inner. Outer — поддерево: его запрос — `SharedScanNode`, до которого спускаемся по outer;
- тело CTE — FROM строки outer (целиком), её WHERE и её собственные предки; в списке колонок только ключ `relation[0]` (из ресурса, которому он принадлежит, — владельца строки или 1-1 ресурса), без `ORDER BY`;
- inner получает `WHERE relation[1] IN (select ключ from p{n})` — полусоединение: без ограничения на число родителей, строки не размножаются, уникальность ключа не важна;
- список `WITH` плоский, имена `p1`, `p2`… по глубине от inner; внутри каждого CTE алиасы снова с `t0` (своя область видимости).

```sql
with p2 as (select t0.id from certificates t0 where t0.status = 'ACTIVE'),
p1 as (select t0.id from domains t0 where t0.certificate_id in (select id from p2) and t0.is_primary)
select t0.id as t0_id, … from logs t0 where t0.domain_id in (select id from p1) order by t0.domain_id, t0.id
```

`PreloadNode` (`ManyToOne`) CTE не получает: ресурс по такой связи считается небольшим словарём и читается целиком; в `included` всё равно попадают только те, на кого ссылаются. Отбрасывать ли из тела CTE `LEFT JOIN` 1-1 ресурсов — не решено. Когда цепочка появится, ребёнок без родителя станет ошибкой плана (`LogicException`, код закомментирован в `HashNestJoinIterator`/`MergeNestJoinIterator`).

### 4. Сборка исполнителя

`ExecutorBuilder(PDO)` собирает по плану после шага `sql` дерево итераторов: `build($plan, $registry)` — само дерево, `document($plan)` — `Document` над ним с новым `ResourceRegistry`. Сборщик состояния выполнения не хранит; на каждое выполнение — новый вызов.

| Узел плана | Итераторы |
|---|---|
| `SharedScanNode` с одним ресурсом | `SerializeIterator(SqlScanIterator(pdo, sql, parameters), resource, registry, columns[0])` |
| `SharedScanNode` со строкой | `SqlScanIterator` → `SharedDataIterator`; поток строки по её узлам; сверху `SharedScanIterator(data, поток строки)` |
| `ResourceNode` внутри строки | `SerializeIterator(SharedDataIterator, resource, registry, следующая запись columns)` — записи идут в порядке обхода строки (outer раньше inner), как их записал `withSelect()` |
| `SharedRowJoinNode` | `SharedRowJoinIterator(outer, inner, name)` |
| `HashJoinNode` (внутри строки) | `HashJoinIterator(поток строки, запрос inner, relation[0], relation[1], name)` |
| `PreloadNode` | `PreloadIterator(source: outer, preload: inner)` |
| `HashNestJoinNode` | `HashNestJoinIterator(outer, inner, relation[0], relation[1], name)` |
| `MergeNestJoinNode` | `MergeNestJoinIterator(outer, inner, relation[0], relation[1], name)` |

Ошибки плана — `LogicException` при сборке, до выполнения: план без шага `sql`, `ResourceNode`/`SharedRowJoinNode`/`HashJoinNode` вне строки, `HashNestJoinNode`/`MergeNestJoinNode`/`SharedScanNode` внутри строки, записи `columns` не того ресурса.

Дерево итераторов для плана выше (топология та же, что у дерева, собранного руками в `example/document.php`):

```
HashNestJoinIterator domains (id ← certificate_id)
├─ outer: PreloadIterator
│   ├─ source: SharedScanIterator
│   │   └─ SharedRowJoinIterator delivery
│   │       ├─ SharedRowJoinIterator detail
│   │       │   ├─ SerializeIterator certificate (t0) ─┐
│   │       │   └─ SerializeIterator detail (t1) ──────┼─► SharedDataIterator ─► SqlScanIterator сертификатов
│   │       └─ SerializeIterator delivery (t2) ────────┘
│   └─ preload: SerializeIterator provider ─► SqlScanIterator провайдеров
└─ inner: MergeNestJoinIterator logs (id ← domain_id)
    ├─ SerializeIterator domain ─► SqlScanIterator доменов
    └─ SerializeIterator log ────► SqlScanIterator логов
```

#### Пример: `example/query-plan.php`

`example/query-plan.php` проходит стадии 2–6 без парсера: дерево запроса `TREE` (см. [Дерево запроса](#дерево-запроса)) → план → дерево итераторов → документ.

```php
$steps = (new QueryPlanner())->plan(tree: TREE);       // дерево после каждого шага
$plan = $steps['sql'];                                 // план с SQL

$builder = new ExecutorBuilder(pdo: getPDO());
$flow = $builder->build(plan: $plan, registry: new ResourceRegistry());   // дерево итераторов на одно выполнение
echo json_encode($flow->explain(), JSON_PRETTY_PRINT);                   // топология, ничего не выполняя

echo json_encode($builder->document(plan: $plan));     // новое дерево итераторов, выполняется внутри json_encode()
```

`php example/query-plan.php --explain` печатает дерево итераторов для этого плана (ниже классы без пространства имён; SQL сертификатов печатается у каждого потребителя `SharedDataIterator` и здесь сокращён после первого раза):

```json
{
    "node": "HashNestJoinIterator",
    "name": "domains",
    "outer_key": "id",
    "inner_key": "certificate_id",
    "outer": {
        "node": "PreloadIterator",
        "source": {
            "node": "SharedScanIterator",
            "data": {
                "node": "SharedDataIterator",
                "source": {
                    "node": "SqlScanIterator",
                    "sql": "select t0.id as t0_id, t0.code as t0_code, t0.nominal as t0_nominal, t0.currency as t0_currency, t0.status as t0_status, t0.created_at as t0_created_at, t0.provider_id as t0_provider_id, t1.certificate_id as t1_certificate_id, t1.buyer_email as t1_buyer_email, t1.recipient_name as t1_recipient_name, t1.recipient_email as t1_recipient_email, t1.greeting_text as t1_greeting_text, t1.design_template_id as t1_design_template_id, t2.certificate_id as t2_certificate_id, t2.delivery_channel as t2_delivery_channel, t2.is_sent as t2_is_sent, t2.external_provider_id as t2_external_provider_id from certificates as t0 left join certificate_details as t1 on t0.id = t1.certificate_id left join certificate_delivery_meta as t2 on t0.id = t2.certificate_id order by t0.id"
                }
            },
            "source": {
                "node": "SharedRowJoinIterator",
                "name": "delivery",
                "outer": {
                    "node": "SharedRowJoinIterator",
                    "name": "detail",
                    "outer": {
                        "node": "SerializeIterator",
                        "resource": "CertificateResource",
                        "alias": "t0",
                        "fields": ["code", "nominal", "currency", "status", "created_at", "provider"],
                        "source": {
                            "node": "SharedDataIterator",
                            "source": {
                                "node": "SqlScanIterator",
                                "sql": "… (тот же SQL сертификатов)"
                            }
                        }
                    },
                    "inner": {
                        "node": "SerializeIterator",
                        "resource": "DetailResource",
                        "alias": "t1",
                        "fields": ["buyer_email", "recipient_name", "recipient_email", "greeting_text", "design_template_id"],
                        "source": {
                            "node": "SharedDataIterator",
                            "source": {
                                "node": "SqlScanIterator",
                                "sql": "… (тот же SQL сертификатов)"
                            }
                        }
                    }
                },
                "inner": {
                    "node": "SerializeIterator",
                    "resource": "DeliveryResource",
                    "alias": "t2",
                    "fields": ["delivery_channel", "is_sent", "external_provider_id"],
                    "source": {
                        "node": "SharedDataIterator",
                        "source": {
                            "node": "SqlScanIterator",
                            "sql": "… (тот же SQL сертификатов)"
                        }
                    }
                }
            }
        },
        "preload": {
            "node": "SerializeIterator",
            "resource": "ProviderResource",
            "alias": "t0",
            "fields": ["name", "website", "created_at"],
            "source": {
                "node": "SqlScanIterator",
                "sql": "select t0.id as t0_id, t0.\"name\" as t0_name, t0.website as t0_website, t0.created_at as t0_created_at from providers as t0 order by t0.id"
            }
        }
    },
    "inner": {
        "node": "MergeNestJoinIterator",
        "name": "logs",
        "outer_key": "id",
        "inner_key": "domain_id",
        "outer": {
            "node": "SerializeIterator",
            "resource": "DomainResource",
            "alias": "t0",
            "fields": ["domain_name", "is_primary", "created_at"],
            "source": {
                "node": "SqlScanIterator",
                "sql": "select t0.id as t0_id, t0.domain_name as t0_domain_name, t0.is_primary as t0_is_primary, t0.created_at as t0_created_at, t0.certificate_id as t0_certificate_id from domains as t0 order by t0.id"
            }
        },
        "inner": {
            "node": "SerializeIterator",
            "resource": "LogResource",
            "alias": "t0",
            "fields": ["log_level", "message", "created_at"],
            "source": {
                "node": "SqlScanIterator",
                "sql": "select t0.id as t0_id, t0.log_level as t0_log_level, t0.message as t0_message, t0.created_at as t0_created_at, t0.domain_id as t0_domain_id from domain_logs as t0 order by t0.domain_id, t0.id"
            }
        }
    }
}
```

Что видно в описании:

- `SharedDataIterator` с запросом сертификатов встречается четыре раза — у `SharedScanIterator` (`data`, он сдвигает строку) и у трёх `SerializeIterator` (`t0`, `t1`, `t2`, они читают окно на ту же строку). Это один объект и один SQL-запрос: `explain()` описывает узел у каждого потребителя.
- `provider` — отдельный запрос под `PreloadIterator`: он только наполняет реестр, в поток корня не попадает.
- `logs` соединяются слиянием: домены упорядочены по `id`, логи — по `domain_id`; `domains` над ними — хеш-соединение по `id` сертификата.
- Параметров у запросов нет: в `TREE` нет фильтров. С фильтром у `SqlScanIterator` появляется ключ `parameters`.

### 5. Выполнение

Исполнитель — дерево узлов-итераторов по [модели Volcano](#модель-volcano). Каждый узел — `Executor\ExecutorIterator`: `IteratorAggregate`, чей `getIterator()` отдаёт генератор, и `explain()`. Узел-родитель перебирает поток своих входов, поэтому выполнение — это перебор корня, а данные поднимаются от листьев (`SqlScanIterator`) к корню. Запросы выполняются лениво, при первом обращении к генератору; всё дерево выполняется внутри `json_encode()` документа.

Узлы хранят состояние и **одноразовые**: дерево собирается на одно выполнение и повторно не выполняется. Второй перебор молча отдаёт пустой поток.

#### Узлы

| Узел | Поток | Что делает |
|---|---|---|
| `SqlScanIterator(PDO, sql, parameters)` | — → строки | Выполняет запрос при первом обращении к генератору, отдаёт строки по одной. Именованные параметры привязывает `bindValue()` с типом по типу PHP (не `execute($массив)`: он передал бы `false` как `''`). |
| `SerializeIterator(source, resource, registry, columns)` | строки → `?ResourceRecord` | Строит ресурс из строки по описанию ресурса и записи `SharedScanNode::columns` (без неё — ручным `resource::serialize($tuple)`); `null` — ресурса в строке нет (пустой `LEFT JOIN`). Регистрирует ресурс в `ResourceRegistry`: для повторного `type:id` дальше идёт уже зарегистрированный объект. |
| `SharedDataIterator(source)` | строки → строки | Раздаёт одну строку нескольким `SerializeIterator`: каждый получает своё «окно» на текущую строку и не может её сдвинуть. |
| `SharedScanIterator(data, source)` | ресурсы → ресурсы | Корень поддерева одного SQL: отдаёт ресурс наверх и сдвигает общий источник на следующую строку. |
| `SharedRowJoinIterator(outer, inner, name)` | ресурсы × ресурсы → ресурсы | Склеивает n-й элемент `outer` с n-м элементом `inner` (как `zip`) и пишет связь «к одному»: `outer.relationships[name] = inner` или `null`. |
| `HashNestJoinIterator(outer, inner, outer_key, inner_key, name)` | ресурсы × ресурсы → ресурсы | Связь «ко многим»: читает `outer` целиком в хеш-таблицу, затем `inner` построчно дописывает в связь своего родителя. Блокирующий. |
| `HashJoinIterator(outer, inner, outer_key, inner_key, name)` | ресурсы строки × ресурсы → ресурсы строки | Связь «ко многим» у 1-1 ресурса внутри общей строки: хеширует `inner` целиком, `outer` идёт потоком; на каждый элемент `outer` — ровно один элемент, `null` проходит как есть. |
| `MergeNestJoinIterator(outer, inner, outer_key, inner_key, name)` | ресурсы × ресурсы → ресурсы | Связь «ко многим» слиянием двух потоков, отсортированных по ключу; порядок проверяет на лету. Не блокирует, хеш-таблицы не строит. |
| `SortIterator(source, key)` | ресурсы → ресурсы | Пересортировывает выход соединения по целочисленному ключу (для `MergeNestJoinIterator`). Планировщик его пока не ставит. |
| `PreloadIterator(source, preload)` | ресурсы → ресурсы | Пропускает `source`, затем дочитывает `preload`: его ресурсы попадают в реестр (справочник для связи по внешнему ключу). |

#### Одна строка — несколько ресурсов

Запрос сертификатов отдаёт сертификат, детали и доставку одной строкой. `SharedDataIterator` раздаёт её трём `SerializeIterator`, `SharedRowJoinIterator` склеивает их результаты по позиции, `SharedScanIterator` сдвигает строку, когда наверх ушёл сертификат. Тройка работает только вместе и держится на правиле: каждая ветка за такт читает ровно одну строку. Поэтому отсутствие ресурса — это `yield null`, а не пропуск: доставки нет → `SerializeIterator delivery` отдаёт `null` → у сертификата `"delivery": {"data": null}`. По тому же правилу `HashJoinIterator` внутри строки отдаёт элемент на каждый элемент своего outer.

#### Связи между запросами

Ключи соединения задаются именами колонок модели: значение берётся через `ResourceRecord::getKey()`; `'id'` — id ресурса, остальные — служебные ключи (`keys`), которые `SerializeIterator` кладёт в ресурс по записи `columns` и которые в JSON не выводятся. Ключи — только целые числа.

- `HashNestJoinIterator domains`: таблица сертификатов по `id`, домены ищут родителя по `certificate_id`. Каждому сертификату сразу пишется пустая связь `domains: []`, дальше в неё дописываются домены в порядке `inner`.
- `MergeNestJoinIterator logs`: домены и логи отсортированы по id домена, логи присоединяются за один совместный проход.
- Соединения только пишут связи и ничего не копируют: связанные ресурсы попадают в ответ через документ (см. [Документ](#6-документ)).
- Ребёнок без родителя сейчас пропускается. Когда дочерние SQL начнут повторять условия родителя, это станет ошибкой плана.

#### Связь по внешнему ключу

`provider_id` лежит в строке сертификата, поэтому идентификатор провайдера известен без соединения: `provider` — публичный атрибут сертификата (`ManyToOne` по внешнему ключу), и `SerializeIterator` пишет связь из своей строки — `{"id": "3", "type": "provider"}` или `{"data": null}` при `NULL`.

Без `include=provider` связь в ответе есть, а провайдера в `included` нет. С `include=provider` провайдеров достаточно загрузить в реестр — это делает `PreloadIterator`; соединять ничего не нужно.

#### EXPLAIN

`explain()` описывает узел и его поддерево массивом, ничего не выполняя: класс узла, параметры и входы под именами параметров конструктора; у `SerializeIterator` — класс ресурса (по плану — ещё алиас и `fields`), у `SqlScanIterator` — SQL и параметры.

```php
$flow = (new ExecutorBuilder($pdo))->build($plan, new ResourceRegistry());
echo json_encode($flow->explain(), JSON_PRETTY_PRINT);
```

Полный вывод для запроса примера — в [Пример: `example/query-plan.php`](#пример-examplequery-planphp).

### 6. Документ

Ресурс в потоке — `Document\ResourceRecord`: `id`, `type`, `attributes`, `relationships` (ссылки `{id, type}`) и служебные `keys`. Объект изменяемый: соединения дописывают ему связи после создания.

`Document\ResourceRegistry` хранит один объект на `type:id` за выполнение. Его получают все `SerializeIterator` дерева и документ.

`Document\Document(flow, registry)` собирается внутри `json_encode()`:

- `data` — все ресурсы потока корня по порядку;
- `included` — обход в ширину по связям от `data`: каждый identifier ищется в реестре, найденный ресурс добавляется и обходится дальше (сертификат → домен → лог). Каждый ресурс попадает в документ один раз.

Ресурс, до которого нельзя дойти по связям (ребёнок без родителя), в документ не попадает. Ресурс, который не загружался (провайдер без `include`), остаётся ссылкой в `relationships`.

Фрагмент ответа (`php example/certificates.php --document`; тот же документ даёт дерево, собранное руками, `php example/document.php`):

```json
{
    "data": [
        {
            "id": "1",
            "type": "certificate",
            "attributes": {
                "code": "CERT-2026-00001",
                "nominal": "1000.00",
                "currency": "USD",
                "status": "EXPIRED",
                "created_at": "2026-03-29 21:01:47"
            },
            "relationships": {
                "provider": {"data": {"id": "3", "type": "provider"}},
                "detail": {"data": {"id": "1", "type": "detail"}},
                "delivery": {"data": {"id": "1", "type": "delivery"}},
                "domains": {"data": [{"id": "1", "type": "domain"}, {"id": "2", "type": "domain"}]}
            }
        }
    ],
    "included": [
        {
            "id": "3",
            "type": "provider",
            "attributes": {"name": "CertExpress", "website": null, "created_at": "2025-10-20 18:45:00"}
        },
        {
            "id": "1",
            "type": "detail",
            "attributes": {
                "buyer_email": "buyer1@example.com",
                "recipient_name": "Анна Каренина",
                "recipient_email": "recipient1@example.org",
                "greeting_text": "С днём рождения!",
                "design_template_id": 9
            }
        },
        {
            "id": "1",
            "type": "domain",
            "attributes": {"domain_name": "green1.net", "is_primary": true, "created_at": "2026-03-30 16:17:26"},
            "relationships": {"logs": {"data": []}}
        }
    ]
}
```

Пустая связь «к одному» — `"data": null`, «ко многим» — `"data": []`: так клиент отличает пустую связь от незагруженной.

## Описание данных

Описание данных делится на две части (схема endpoint'а, третья часть, пока убрана):

- **Сущность** (`Model\Entity`, слой `src/Model`) описывает одну таблицу: колонки, их типы, первичный и уникальные ключи, внешние ключи. О JSON:API она не знает: ресурсы собирают из сущностей свою модель данных, а будущие `insert` и `update` возьмут из них порядок записи таблиц и типы.
- **Класс ресурса** описывает JSON:API ресурс: `type`, атрибуты, первичный ключ, связи с другими ресурсами, модель данных (из каких сущностей ресурс читается). Он не зависит от endpoint'а: endpoint называет корневой ресурс, а всё, что объявлено в ресурсах, доступно клиенту.

### Сущность

Слой `src/Model` (`Ggs\JsonApiEngine\Model`) — описание таблиц и моделей данных поверх них: `Entity`, `Metadata`, `NamedConstants`, `Join`/`LeftJoin`/`InnerJoin`, `ModelTree`, `EntityDatabaseValidator`. Раньше это была отдельная библиотека `ggs/data-model`. Слой не знает о ресурсах, плане и JSON:API: зависимости идут только к нему, не от него.

PHP-класс, унаследованный от `Model\Entity`, по одному на таблицу (`example/Entity/`). В нём только константы: `TABLE`, по строковой константе на колонку и `COLUMNS` — описание колонок.

```php
final class CertificateDetailsEntity extends Entity
{
    public const string TABLE = 'certificate_details';

    public const string CERTIFICATE_ID = 'certificate_id';
    public const string BUYER_EMAIL = 'buyer_email';
    public const string RECIPIENT_NAME = 'recipient_name';
    public const string RECIPIENT_EMAIL = 'recipient_email';
    public const string GREETING_TEXT = 'greeting_text';
    public const string DESIGN_TEMPLATE_ID = 'design_template_id';

    public const array COLUMNS = [
        self::CERTIFICATE_ID => ['type' => self::INT, 'constraint' => self::PRIMARY],
        self::BUYER_EMAIL => ['type' => self::STRING],
        self::RECIPIENT_NAME => ['type' => self::STRING, 'nullable' => true],
        self::RECIPIENT_EMAIL => ['type' => self::STRING, 'nullable' => true],
        self::GREETING_TEXT => ['type' => self::STRING, 'nullable' => true],
        self::DESIGN_TEMPLATE_ID => ['type' => self::INT, 'nullable' => true],
    ];
}
```

- **Ключ `COLUMNS` — колонка ресурса.** На неё ссылаются `column` в `ATTRIBUTES` ресурса, `relation` в `model()` и в связях. Колонка таблицы в БД задаётся `column`, если отличается.
- **Одно ограничение на колонку.** `certificate_details.certificate_id` — и первичный, и внешний ключ; записано `PRIMARY`, а связь с сертификатом задаётся в ресурсе.
- **Типы.** `DECIMAL` в словаре типов нет: `certificates.nominal` описан `STRING`, чтобы не терять точность денежной суммы.

Описание колонки:

| Ключ         | Обязательный | Описание |
|--------------|--------------|----------|
| ключ массива | да           | Имя колонки сущности; значение одной из строковых констант класса. |
| `column`     | нет          | Колонка таблицы в БД. По умолчанию совпадает с именем. |
| `type`       | да           | Тип значения: `INT`, `FLOAT`, `STRING`, `BOOL`, `DATE`, `DATETIME`. |
| `constraint` | нет          | `PRIMARY` — первичный ключ таблицы; `FOREIGN` — внешний ключ на другую таблицу. |
| `references` | для `FOREIGN` | Класс сущности, на первичный ключ которой ссылается внешний ключ. Только вместе с `FOREIGN`. |
| `unique`     | нет          | Колонка уникальна в таблице (кроме первичного ключа). По умолчанию `false`. |
| `nullable`   | нет          | Может ли значение быть `null`. По умолчанию `false`. |
| `mode`       | нет          | `READ` — колонку не пишет клиент (её заполняет БД); `READ_WRITE` — по умолчанию. Нужен для `insert` и `update`. |

- **Одна таблица — один класс.** Связь таблиц задаёт `model()` ресурса, а не сущность. Сущность может описывать не все колонки таблицы.
- **Первичный ключ** — ровно одна колонка `PRIMARY`; составных нет. `FOREIGN` ссылается на первичный ключ сущности из `references`, `type` совпадает.
- **Проверки в тестах, не в рантайме.** `Entity::validate()` проверяет константы (ключи и значения `COLUMNS`, один `PRIMARY`, `references` у `FOREIGN`, константы совпадают с ключами `COLUMNS`). `EntityDatabaseValidator::validate($connection, $entity)` сверяет сущность с каталогом PostgreSQL: таблица, колонки, типы, `NOT NULL`, первичный ключ, уникальные индексы, внешние ключи (интеграционный тест `test/Integration/Model/EntityDatabaseTest`).

### Класс ресурса

PHP-класс, унаследованный от `Resource` (`example/Resource/`). Метаданные — публичные константы `TYPE` и `ATTRIBUTES`, связи — статический метод `relationships()`, модель данных — статический метод `model()`. Экземпляров у класса нет, на него ссылаются по `::class`.

```php
final class CertificateResource extends Resource
{
    public const string TYPE = 'certificate';

    public const string ID = 'id';
    public const string CODE = 'code';
    public const string NOMINAL = 'nominal';
    public const string CURRENCY = 'currency';
    public const string STATUS = 'status';
    public const string CREATED_AT = 'created_at';
    public const string PROVIDER = 'provider';
    public const string DETAIL = 'detail';
    public const string DELIVERY = 'delivery';
    public const string DOMAINS = 'domains';

    public const array ATTRIBUTES = [
        self::ID => ['column' => CertificatesEntity::ID, 'type' => self::INT, 'constraint' => self::PRIMARY],
        self::CODE => ['column' => CertificatesEntity::CODE, 'type' => self::STRING],
        self::NOMINAL => ['column' => CertificatesEntity::NOMINAL, 'type' => self::STRING],
        self::CURRENCY => ['column' => CertificatesEntity::CURRENCY, 'type' => self::STRING],
        self::STATUS => [
            'column' => CertificatesEntity::STATUS,
            'type' => self::STRING,
            'filter' => [Operator::Eq, Operator::Ne],
        ],
        self::CREATED_AT => [
            'column' => CertificatesEntity::CREATED_AT,
            'type' => self::DATETIME,
            'filter' => [Operator::Gte, Operator::Lt],
        ],
    ];

    public static function relationships(): array
    {
        return [
            // внешний ключ: certificates.provider_id → providers.id
            self::PROVIDER => new ManyToOne(
                resource: ProviderResource::class,
                relation: [CertificatesEntity::PROVIDER_ID, ProvidersEntity::ID],
            ),
            self::DETAIL => new OneToOne(
                resource: DetailResource::class,
                relation: [CertificatesEntity::ID, CertificateDetailsEntity::CERTIFICATE_ID],
            ),
            self::DELIVERY => new OneToOne(
                resource: DeliveryResource::class,
                relation: [CertificatesEntity::ID, CertificateDeliveryMetaEntity::CERTIFICATE_ID],
            ),
            self::DOMAINS => new OneToMany(
                resource: DomainResource::class,
                relation: [CertificatesEntity::ID, DomainsEntity::CERTIFICATE_ID],
            ),
        ];
    }

    public static function model(): string
    {
        return CertificatesEntity::class;
    }

    // свой формат атрибута для SerializeIterator (см. «Сериализация»)
    public static function serializeNominal(array $values): string
    {
        return number_format((float) $values[CertificatesEntity::NOMINAL], 2, '.', '');
    }
}
```

Значения `INT`, `STRING`, `PRIMARY`, `READ` и т. д. — константы интерфейса `Metadata`, который реализуют `Resource` и `Entity`.

Почему константы, а не PHP-атрибуты (`#[...]`): атрибуты читаются только через Reflection и разбирались бы в каждом запросе, а константы OPcache хранит в разделяемой памяти вместе с кодом. Почему описания атрибутов — массивы: PHP не разрешает `new` в константах. Модель и связи — структуры, поэтому они задаются методами с объектами (`LeftJoin`/`InnerJoin`, `OneToOne`/`OneToMany`), а разбор кешируется на класс.

#### Константы класса ресурса

| Константа | Описание |
|---|---|
| `TYPE` | JSON:API `type` ресурса: в `type` ресурса и в `fields[<type>]`. |
| `ATTRIBUTES` | Атрибуты: ключ — имя в API, значение — описание. |
| `<ИМЯ>` | По строковой константе на атрибут и связь, значение — имя в API (`CertificateResource::CODE = 'code'`). Код ссылается на атрибуты через них. |

Строковые константы, кроме `TYPE`, — ровно ключи `ATTRIBUTES` и имена связей: это проверяет `validate()`. `TYPE` всегда означает тип ресурса, поэтому атрибута `type` нет.

#### Описание атрибута в `ATTRIBUTES`

| Ключ | Обязательный | Описание |
|---|---|---|
| `column` | нет | Колонка ресурса — колонка одной из сущностей `model()`, обычно её константа. По умолчанию — имя атрибута. |
| `columns` | нет | Колонки составного атрибута, вместо `column`; требует метода `serialize<Имя>()`. |
| `type` | да, если нет `columns` | `INT`, `FLOAT`, `STRING`, `BOOL`, `DATE`, `DATETIME`: тип значения фильтра и сериализации. |
| `constraint` | нет | `PRIMARY` — первичный ключ. Внешний ключ — не атрибут, а связь. |
| `nullable` | нет | Может ли значение быть `null` (по умолчанию `false`). |
| `mode` | нет | `READ` или `READ_WRITE` (по умолчанию) — для `insert`/`update`. |
| `filter` | нет | Операторы фильтра: непустой список `Operator`. Без него атрибут не фильтруется ни в одном endpoint'е. |
| `sort` | нет | `true` — по атрибуту можно сортировать. |

- **Имя и колонка разделены:** наружу отдаётся имя, колонку можно переименовать, не сломав клиентов.
- **`type` и `id` зарезервированы JSON:API.** `id` — только имя атрибута `PRIMARY`; колонка `type` выводится под другим именем.
- **Первичный ключ** — ровно один атрибут с одной колонкой; его значение уходит в `id` строкой и в `attributes` не попадает. Идентификатор `detail` и `delivery` — их первичный ключ `certificate_id`.
- **Фильтры и сортировка — в ресурсе** и включаются явно: фильтр без индекса — seq scan, а индексы — свойство таблиц, а не endpoint'а.

#### Связи (`relationships()`)

Метод возвращает массив `имя связи → связь`. Имя — ключ массива (обычно константа ресурса), у самой связи его нет.

| Аргумент | Описание |
|---|---|
| `resource` | Класс ресурса на другой стороне. |
| `relation` | `[колонка этого ресурса, колонка ресурса resource]`, обе — `INT`. |
| `filter`, `sort` | Только у связи по внешнему ключу: как у атрибута. |

| Связь | `relation` | Вид |
|---|---|---|
| `certificate.provider` | `['provider_id', 'id']` | `ManyToOne`, внешний ключ в строке сертификата |
| `certificate.detail` | `['id', 'certificate_id']` | `OneToOne`, обратная сторона |
| `certificate.delivery` | `['id', 'certificate_id']` | `OneToOne`, обратная сторона |
| `certificate.domains` | `['id', 'certificate_id']` | `OneToMany` |
| `domain.logs` | `['id', 'domain_id']` | `OneToMany` |

- **`OneToOne`** — у строки ресурса не больше одной строки `resource`: колонка `resource` — его первичный ключ или `PRIMARY`/`unique` колонка основной таблицы.
- **`ManyToOne`** — не больше одной строки `resource`, но у неё может быть много владельцев; ключ у владельца: внешний ключ на первичный ключ `resource`. Читается отдельным SQL-запросом (`PreloadIterator`).
- **`OneToMany`** — любое число строк; читается отдельным SQL-запросом.
- **Связь по внешнему ключу** (`Resource::foreignRelationship()`): `ManyToOne` или `OneToOne`, у которых своя колонка — не первичный ключ, а колонка `resource` — его первичный ключ (`certificate.provider`). Пишется из строки самого ресурса, в `fields` перечисляется как атрибут.
- **Только целые ключи.** Ключи связей сравнивает PHP (хеш-таблицы, слияние), а для `int` равенство и порядок в PHP те же, что в PostgreSQL.

Связи описывает ресурс: связь — часть типа ресурса, одинаковая в любом endpoint'е, и любую объявленную связь клиент может подключить через `include`.

#### Модель данных (`model()`)

`model()` возвращает сущность или бинарное дерево соединений `Model\LeftJoin`/`InnerJoin(left, right, relation)`: из каких таблиц читается ресурс. Крайняя левая таблица — основная; соединения — «к одному». Правая колонка `relation` колонкой модели не считается, поэтому две присоединённые таблицы могут обе иметь `certificate_id`.

Все ресурсы примера читаются из одной таблицы. Ресурс из нескольких таблиц выглядел бы так (детали и доставка — атрибуты сертификата, а не отдельные ресурсы):

```php
public static function model(): Join
{
    return new LeftJoin(
        left: new InnerJoin(
            left: CertificatesEntity::class,
            right: CertificateDetailsEntity::class,
            relation: [CertificatesEntity::ID, CertificateDetailsEntity::CERTIFICATE_ID],
        ),
        right: CertificateDeliveryMetaEntity::class,
        relation: [CertificatesEntity::ID, CertificateDeliveryMetaEntity::CERTIFICATE_ID],
    );
}
```

`ModelTree::of($class)->from('cc', $needed)` строит из неё элемент `FROM` узлами pg_builder — `RelationReference` (одна таблица) или `JoinExpression` — только с нужными таблицами: `LEFT JOIN` без нужных колонок отбрасывается, `INNER JOIN` остаётся всегда. `expression($column, 'cc')` даёт `ColumnReference` колонки модели. Каждый вызов строит новые узлы, их можно сразу вставлять в запрос. В SQL это выглядит так:

```sql
certificates AS cc JOIN certificate_details AS cc_1 ON cc_1.certificate_id = cc.id
                   LEFT JOIN certificate_delivery_meta AS cc_2 ON cc_2.certificate_id = cc.id
```

Правила дерева (проверяет `ModelTree`, ошибка содержит путь в дереве: `model root.left.right: …`):

- **Основная таблица — крайняя левая.** Она получает алиас места в запросе (`t0`), остальные таблицы — `<алиас>_<номер>` (`t0_1`, `t0_2`) слева направо; номер не зависит от того, какие таблицы подключены.
- **Колонки модели** — колонки всех сущностей дерева, кроме правой колонки `relation` каждого соединения (она равна левой). Остальные имена уникальны: одинаковую колонку двух таблиц сущность переименовывает через `column`.
- **Колонки `relation` ищутся по имени:** левая — в левом поддереве, правая — колонка основной таблицы правого поддерева.
- **Только to-one.** Правая колонка `relation` — `PRIMARY` или `unique`, типы колонок совпадают: строки модели не размножаются.
- **Только нужные таблицы.** `LEFT JOIN` без нужных колонок справа не подключается вместе со всем поддеревом; таблица, через которую присоединяется подключённое соединение, остаётся; `INNER JOIN` подключается всегда.
- **`INNER` значит то, что написано.** `InnerJoin` на верхнем уровне отсекает строки модели без пары; чтобы условие действовало только внутри необязательной части, её берут в скобки — `InnerJoin` справа от `LeftJoin`.

`ModelTree::of($class)` кеширует разбор на класс и бросает `LogicException` при ошибках; `ModelTree::build($class)->errors()` — без кеша и исключения (для `Resource::validate()`).

#### Сериализация

Ресурс из строки запроса плана собирает `SerializeIterator` по описанию ресурса. Сборщик дерева передаёт ему запись `SharedScanNode::columns` этого ресурса: алиас, карту «колонка модели → колонка строки» (`t1_buyer_email`), `fields` и ключи соединений. Всё, что не зависит от строки, итератор готовит один раз в конструкторе; на каждой строке:

- первичный ключ `NULL` — ресурса в строке нет (`LEFT JOIN`), `null`; иначе он — `id`;
- атрибуты `fields` по порядку: если ресурс объявил `serialize<Атрибут>(array $values)` — его результат (`$values` — все колонки атрибута по именам модели, так собирается составной атрибут), иначе единственная колонка, приведённая по `type` (`date`/`datetime` — как есть, `NULL` — `null`);
- связь по внешнему ключу из `fields` — `{id, type}` цели из своей строки или `data: null`;
- ключи соединений — в служебные `keys` (`NULL` не пишется).

```php
// CertificateResource: свой формат атрибута
public static function serializeNominal(array $values): string
{
    return number_format((float) $values[CertificatesEntity::NOMINAL], 2, '.', '');
}
```

**Ручной режим.** Без записи плана `SerializeIterator` вызывает статический `serialize(array $tuple): ?ResourceRecord` ресурса (в базовом классе бросает `LogicException`). Так собраны ручные деревья `example/document.php` и `DocumentTreeTest`: их SQL написан руками (`QUERY1`–`QUERY4` в `example/db/connection.php`), ресурсы читают колонки по алиасам этих запросов. Строка — вся строка SQL-запроса, ресурс читает свои колонки, `null` — ресурса в строке нет.

```php
// DomainResource
public static function serialize(array $tuple): ResourceRecord
{
    return new ResourceRecord(
        id: $tuple['domain_id'],
        type: self::TYPE,
        attributes: [
            self::DOMAIN_NAME => $tuple['domain_name'],
            self::IS_PRIMARY => (bool) $tuple['is_primary'],
            self::CREATED_AT => $tuple['created_at'],
        ],
        relationships: [],
        keys: ['certificate_id' => $tuple['certificate_id']],   // ключ соединения для HashNestJoinIterator
    );
}
```

- **Связь по внешнему ключу** пишет `serialize()` самого ресурса из его строки: `CertificateResource` — `provider` из `provider_id`.
- **Ключи соединения** для узлов плана кладутся в служебные `keys` и в JSON не выводятся.
- **Ручные методы** написаны по алиасам запросов примера (`certificate_id`, `detail_id`, `meta_id`, …) и нужны, пока есть ручные деревья.

#### Проверки

Константы не проверяются в рантайме. Их проверяет `Resource::validate()` в тестах:

- `TYPE` не пустой; у атрибутов только известные ключи и допустимые значения; `column` и `columns` не вместе; `type` есть, если нет `columns`;
- нет атрибута `type`, `id` — только `PRIMARY`; строковые константы совпадают с атрибутами и связями;
- ровно один несоставной `PRIMARY`, нет `FOREIGN` у атрибута;
- связи: имена уникальны и не совпадают с атрибутами, `resource` — наследник `Resource`, обе колонки `relation` есть в моделях и имеют тип `INT`, у `OneToOne` колонка цели уникальна;
- `filter`/`sort` — правильной формы и не у составных атрибутов;
- у составного атрибута есть `serialize<Имя>()`, каждый `serialize*` статический и соответствует атрибуту;
- модель: правила `ModelTree`, все колонки `ATTRIBUTES` есть в модели, первичный ключ уникален в основной таблице.

## Открытые вопросы

- **Схема endpoint'а.** Убрана, пока всё не готово: сейчас доступно всё, что объявлено в ресурсах. Вернуть как ограничения поверх ресурсов (какие связи, поля, фильтры открыты в endpoint'е, обязательные связи).
- **Цепочка предков.** Решение принято (CTE + `IN`, см. [Цепочка предков](#цепочка-предков)), не реализовано; не решено, отбрасывать ли из тела CTE `LEFT JOIN` 1-1 ресурсов.
- **Фильтры.** Оператор `in` в SQL плана (через PDO массив одним параметром не передать); `ne` и `NULL` (`<>` не находит строки с `NULL`, `IS DISTINCT FROM` хуже для индекса).
- **Параметры маршрута** (`/certificates/1`): парсер их не разбирает, планировщик пока не принимает.
- **Merge по неквалифицированным колонкам.** `HashToMergeRule` хранит порядок владельца колонкой без ресурса: одинаковый ключ разных таблиц не отличить.
- **Обратная сторона связи.** `certificate.domains` и `domain.certificate` — стороны одного внешнего ключа; выводить одну из другой или из `FOREIGN` сущностей?
- **Тип и `nullable` атрибута из сущности.** Атрибут повторяет `type` и `nullable` колонки; выводить или проверять совпадение в `validate()`?
- **Обратные связи без `include`.** Связь, у которой ключ на другой стороне (`certificate.domains`), без `include` не известна: не отдавать её или отдавать без `data`?

## Использование

Точки входа в самой библиотеке пока нет: стадии соединяет вызывающий код, как контроллер примера (`example/Controller/CertificatesController`):

```php
use Ggs\JsonApiEngine\Executor\ExecutorBuilder;
use Ggs\JsonApiEngine\Parser\InvalidQueryException;
use Ggs\JsonApiEngine\Parser\QueryParser;
use Ggs\JsonApiEngine\Parser\ResourceNotFoundException;
use Ggs\JsonApiEngine\Plan\Select\QueryPlanner;

try {
    $tree = (new QueryParser())->parse(resource: CertificateResource::class, query: $_GET);   // 1
} catch (InvalidQueryException $e) {
    // 400
} catch (ResourceNotFoundException $e) {
    // 404
}

$plan = (new QueryPlanner())->plan(tree: $tree)['sql'];                  // 2–3: план с SQL; можно кешировать
$document = (new ExecutorBuilder(pdo: $pdo))->document(plan: $plan);     // 4: новое дерево итераторов и реестр

echo json_encode($document);                                            // 5–6: запросы выполняются здесь
```

Отладка: `QueryPlanPrinter::tree($plan)` — дерево плана, `QueryPlanPrinter::queries($plan)` — SQL и параметры каждого запроса, `ExecutorBuilder::build()->explain()` — дерево итераторов.

## Разработка

```bash
composer install
vendor/bin/phpunit --testsuite unit                # модульные тесты, в том числе test/Model
vendor/bin/phpunit --testsuite integration         # test/Integration/Model: сущности против PostgreSQL (docker compose up -d), без БД пропускаются
vendor/bin/psalm                                   # статический анализ
php example/document.php                           # документ по дереву итераторов, собранному руками
php example/certificates.php [URL] [--document]    # CertificatesController: план и SQL для запроса, с --document — документ (по умолчанию include=provider,detail,delivery,domains.logs)
php example/query-plan.php [--steps|--sql|--explain]  # дерево запроса из кода (без парсера) → план → документ; --steps — план после каждого шага
php example/query-tree.php                         # дерево запроса для include=provider,detail,delivery,domains.logs
php example/query-tree.php '/certificates?include=domains.logs&fields[certificate]=code'
php example/db/seed.php [количество сертификатов]  # пересоздать базу примера
```

| Где | Что |
|---|---|
| `example/db/` | SQLite база примера: схема, генератор данных, запросы `QUERY1`–`QUERY4`, `getPDO()`. |
| `example/Entity/`, `example/Resource/` | Сущности и ресурсы над базой примера. |
| `example/Controller/CertificatesController.php` | Точка входа `GET /certificates`: `get($query)` — парсер по `CertificateResource`, планировщик и `ExecutorBuilder::document()`, возвращает `Document`; `plan($query)` — только план. |
| `test/PlanTreeTest.php` | Запрос → план → `ExecutorBuilder` → документ: совпадает с эталоном (с порядком), топология как у ручного дерева, повторное выполнение плана, фильтр, ветка 1:N внутри строки (`HashJoinIterator`), ошибки сборки. |
| `test/Executor/HashJoinIteratorTest.php` | `HashJoinIterator`: элемент на элемент `outer`, `null` проходит, связанные по ключу, пустая связь. |
| `test/Plan/SelectWhereTest.php` | `WHERE` плана: фильтры владельца и 1-1 ресурса (`IS NULL OR`), именованные параметры, выполнение на SQLite примера; колонки фильтра и сортировки держат таблицы модели в FROM. |
| `test/Executor/SerializeIteratorTest.php` | Сериализация по плану: ресурсы из запросов плана примера на SQLite совпадают с эталоном по атрибутам и связи `provider`; составной атрибут, приведение типов, связь по внешнему ключу, ключи, `null` у пустого `LEFT JOIN`. |
| `test/DocumentTreeTest.php` | Дерево из `document.php` с константами ресурсов: документ совпадает с эталоном `test/Fixture/document.json`, каждый ресурс встречается один раз, связь по внешнему ключу без и с `include`, пустая доставка, топология `explain()`. |
