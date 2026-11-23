# JSON:API Engine

PHP-библиотека, реализующая JSON API.

Библиотека принимает запрос в формате [JSON:API](https://jsonapi.org/) и описание ресурсов, строит по ним план выполнения и собирает ответ в формате JSON:API.

## Поддерживаемые операции

| Операция | Статус         |
|----------|----------------|
| `select` | В разработке   |
| `insert` | В разработке: парсер документа, планировщик, исполнители; нет ответа и точки входа |
| `update` | В разработке: тот же конвейер записи (`PATCH`), со связями по владению |
| `delete` | В разработке: тот же конвейер записи (`DELETE`), зависимое удаляет каскад базы |

Основная разработка — `select`; запись (`insert`, `update`, `delete`) перенесена из прототипа (см. [Запись](#запись-insert-update-delete)).

## Состояние

Путь запроса собран целиком: URL → дерево запроса → план с SQL → дерево читателей → документ JSON:API (см. [Как это работает](#как-это-работает)).

| Часть | Где | Состояние |
|---|---|---|
| Описание данных: сущности и модели, ресурсы | `src/Model/`, `src/Resource*` | готово; схемы endpoint'а пока нет — всё разрешает ресурс |
| 1. Парсер: запрос → дерево запроса | `src/Parser/` | готово |
| 2. Планировщик: дерево запроса → узлы плана, шаги | `src/Plan/`, `src/Plan/Select/` | готово |
| 3. SQL плана (pg_builder) | `Plan\Select\EntityScanNodeDraft::withSelect()`, `Plan\Select\BuildSqlPass` | список колонок, FROM/ON, WHERE из фильтров, ORDER BY, цепочка предков (CTE + `IN`); нет оператора `in`, параметров маршрута |
| 4. Сборка дерева читателей по плану | `src/Executor/Select/ReaderBuilder.php` | готово |
| 5. Выполнение (читатели по модели Volcano), сериализация | `src/Executor/Select/` | готово |
| 6. Документ JSON:API | `src/Document/` | готово |
| Точка входа библиотеки, транзакция, пагинация | — | нет; весь путь собирает контроллер примера `example/Controller/CertificatesController` |
| Запись (`POST`, `PATCH`, `DELETE`): документ → дерево операций → план → дерево исполнителей | `Parser\Write\`, `Plan\Write\` (+ `Plan\Insert\`, `Update\`, `Delete\`), `Executor\Write\` (+ `Executor\Insert\`, `Update\`, `Delete\`), `Resource\InsertableInterface`/`UpdatableInterface`/`DeletableInterface` | парсер, планировщик и исполнители готовы; нет ответа и контроллера, целей внешних ключей, многих-ко-многим |

Проверено на SQLite и PDO (`example/db`): документ по плану для запроса примера совпадает с эталоном `test/Fixture/document.json`. Целевая СУБД — PostgreSQL; против неё пока ничего не проверяется.

## Зависимости

| Зависимость | Назначение |
|-------------|------------|
| PHP 8.5+ | clone with в узлах плана |
| `ext-ds` ^2.0 | `Ds\Map`, `Ds\Set` в реестре ресурсов, документе и хеш-соединении. |
| `ext-pdo` | Узел `EntityScanReader` выполняет SQL через PDO (пример — SQLite). |
| [`sad_spirit/pg_builder`](https://github.com/sad-spirit/pg-builder) `^3.3` | Строит SQL плана для PostgreSQL. |
| [`psr/container`](https://www.php-fig.org/psr/psr-11/) `^2.0` | Контейнер зависимостей приложения (PSR-11): сборщики `ReaderBuilder` и `WriterBuilder` получают `ContainerInterface` и берут из него объекты клиентских классов по имени класса — в первую очередь ресурсы (см. [Сериализация](#сериализация)). Сам контейнер библиотека не поставляет; в примерах и тестах — `Example\Container\ArrayContainer`. |

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

По запросу клиента и корневому ресурсу endpoint'а библиотека строит план. План — неизменяемое дерево узлов с SQL-запросами. По плану на каждое выполнение собирается дерево читателей: SQL-запросы и операторы передают друг другу строки и ресурсы, а документ JSON:API получается обходом результата.

Связи «к одному» читаются тем же SQL-запросом, что и их родитель (`LEFT JOIN`): из одной строки строится несколько ресурсов. Связь «ко многим» через `JOIN` дала бы декартово произведение, поэтому она читается отдельным SQL-запросом, а дети привязываются к родителям в PHP по ключу связи. Ключи родителей из запроса в запрос не передаются: дочерний запрос повторяет условия родителя сам (см. [Цепочка предков](#цепочка-предков)).

```
GET /certificates?include=…&fields[…]=…&filter[…]=…&sort=…   + корневой ресурс (CertificateResource)
        │
        ▼
1. Парсинг и валидация   QueryParser::parse()            ──►  дерево запроса (массив)
        │                                                      ошибки клиента: 400 / 404
        ▼
2. Планирование          SelectPlanner: fromTree()         ──►  узлы плана (src/Plan/Select)
                         шаги lift → order → close → merge
        │
        ▼
3. SQL                   шаг sql: BuildSqlPass             ──►  EntityScanNodeDraft → EntityScanNode: Select, parameters, columns
                         → EntityScanNodeDraft::withSelect()
        │
        ▼
4. Сборка исполнителя    ReaderBuilder                   ──►  дерево читателей (src/Executor/Select) + ResourceRegistry
        │
        ▼
5. Выполнение            перебор корня (Volcano)           ──►  поток ResourceRecord, связи записаны
        │
        ▼
6. Документ              json_encode(Document)             ──►  {"data": […], "included": […]}
```

Весь путь собран в контроллере примера `example/Controller/CertificatesController`: `get($query)` возвращает `Document`, `plan($query)` — план после шага 3. `php example/certificates.php [URL]` печатает план и SQL, с `--document` — документ. Для запроса по умолчанию `include=provider,detail,delivery,domains.logs` документ совпадает с эталоном `test/Fixture/document.json` (`PlanTreeTest`).

Ошибки разделены по месту: ошибки клиента возникают только в парсере (`InvalidQueryException` — 400, `ResourceNotFoundException` — 404). Всё, что идёт после него, получает проверенное дерево, и несоответствие там — `LogicException`: ошибка кода, а не запроса.

Запись (`POST`, `PATCH`, `DELETE`) устроена так же, но в обратную сторону: данные идут не снизу вверх к документу, а сверху вниз к таблицам (push вместо pull), и тоже через неизменяемый план и дерево исполнителей на одно выполнение — см. [Запись](#запись-insert-update-delete):

```
POST | PATCH | DELETE  + документ JSON:API + ресурс точки входа (+ id из маршрута)
        │
        ▼
1. Разбор и валидация    WriteDocumentParser               ──►  дерево операций (Insert/Update/Keep/DeleteOperation)
        │                insert() / update() / delete()         ошибки клиента: 400 / 403 / 409
        ▼
2. Планирование          WritePlanner                      ──►  узлы плана (src/Plan/Write, Insert, Update, Delete)
        │
        ▼
3. Сборка исполнителя    WriterBuilder                     ──►  дерево исполнителей (src/Executor/Write, Insert, Update, Delete)
        │
        ▼
4. Выполнение            WriteRootWriter::execute()        ──►  INSERT / UPDATE / DELETE, ключи корней
                                                                нет ресурса или он чужой: 404
```

У записи клиентская ошибка есть и после парсера: существование ресурса известно только базе, поэтому 404 (`WriteNotFoundException`) возникает при выполнении, и уже сделанную часть запроса откатывает транзакция вызывающего. Так же при выполнении возникает 400 (`WriteInvalidAttributeException`), когда значение атрибута не принял `deserialize()` ресурса: форму значения парсер не проверяет.

### Модель Volcano

Исполнитель устроен по модели итераторов Volcano (Graefe), как исполнители реляционных СУБД: запрос — дерево операторов, каждый оператор — итератор, который по требованию отдаёт следующий элемент своего потока и сам берёт элементы у своих входов.

| Volcano | Здесь |
|---|---|
| `open()` | первый вызов `getIterator()`: создаётся генератор; `EntityScanReader` выполняет запрос только при первом шаге генератора |
| `next()` | очередной шаг генератора (`foreach`, `current()`/`next()`) |
| `close()` | генератор закончился; повторно не открывается |
| оператор с 0 / 1 / 2 входами | лист `EntityScanReader`; унарные `SerializeReader`, `EntitySharedWindowReader`, `SortReader`; бинарные — соединения (`outer`, `inner`), `PreloadReader` (`source`, `preload`), `EntitySharedAdvanceReader` (`data`, `source`) |

Свойства модели, на которые опирается библиотека:

- **Pull, а не push.** Работу запускает потребитель: корень перебирает `json_encode()` документа, корень тянет свои входы, и так до листьев. Данные поднимаются снизу вверх по одному элементу; пока документ не начал перебор, ни один SQL-запрос не выполнен.
- **Конвейер и блокирующие операторы.** Большинство узлов потоковые: элемент проходит наверх, не дожидаясь остальных (`SerializeReader`, `EntitySharedJoinReader`, `MergeNestJoinReader`). Блокирующие узлы читают один вход целиком до первого элемента наверх: `HashNestJoinReader` — `outer` в хеш-таблицу, `HashJoinReader` — `inner`, `SortReader` — весь вход. Память поэтому определяется блокирующими узлами и реестром, а не числом строк в потоке.
- **Фиксированное число входов.** Число входов узла видно по классу, и узел плана и его читатель называются по одной основе (`MergeNestJoinNode` → `MergeNestJoinReader`): план — дерево операторов, дерево читателей — его экземпляр на одно выполнение.

Чем модель отличается от классической:

- **Два вида элементов.** Снизу по потоку идут строки SQL (`array`), выше `SerializeReader` — ресурсы (`ResourceRecord`). Какой поток у узла, написано в его описании («Поток: вход → выход»).
- **Одна строка — несколько ресурсов.** Классический оператор читает свой вход один; здесь строку одного SQL читают несколько `SerializeReader` через `EntitySharedWindowReader`, а `EntitySharedAdvanceReader` сдвигает её один раз за такт. Тройка работает в ногу (lockstep): каждая ветка за такт отдаёт ровно один элемент, отсутствие ресурса — `null` (см. [Одна строка — несколько ресурсов](#одна-строка--несколько-ресурсов)).
- **Соединение не склеивает кортежи.** Выход соединения — элементы `outer`, которым дописана связь; связанные ресурсы в поток не копируются, а попадают в ответ через реестр и обход документа.
- **Побочный эффект — реестр.** `RegisterReader` над каждым сериализатором регистрирует каждый ресурс в общем `ResourceRegistry` (один объект на `type:id`); `PreloadReader` дочитывает свой вход только ради этого эффекта.
- **Одноразовость.** Читатели хранят состояние и выполняются один раз; повторный перебор молча пуст. План переиспользуется, дерево читателей на каждое выполнение строит `ReaderBuilder`.

### Модель оптимизатора

Планировщик (`Plan\Select\SelectPlanner`) — оптимизатор на правилах (rule-based), без модели стоимости и статистики: решения принимаются по форме дерева и описанию ресурсов, поэтому один запрос всегда даёт один и тот же план. План — дерево неизменяемых узлов, и оптимизация — последовательность переписываний дерева в новое дерево.

- **Неизменяемые узлы.** Свойства узлов `readonly`; «изменить» узел — получить копию через `with…()` (clone with). Тот же вход — тот же объект, поэтому нетронутые поддеревья переиспользуются, а результат каждого шага остаётся целым: `plan()` возвращает дерево после каждого шага (`php example/query-plan.php --steps`).
- **Шаг — `PassInterface`:** функция «дерево → дерево», `$step(plan: $plan)`. Проход видит всё дерево и может нести контекст при обходе (`EntityScanNodeDraftPass` знает, внутри строки он или нет; `BuildSqlPass` передаёт ключи соединений сверху вниз).
- **`Rule` — частный случай `PassInterface`:** локальное правило `apply(node)` смотрит только на узел и его прямые входы и возвращает замену или `null`. Обход написан один раз в `Rule`: снизу вверх (входы уже преобразованы, как `transformUp` в Spark Catalyst), полными кругами, пока за круг не случится ни одной замены (фиксированная точка). Замены считаются, деревья не сравниваются.
- **Порядок шагов фиксирован** (`getSteps()`), каждый рассчитан на результат предыдущих. Правило, которое меняет верх строки, должно идти до `close`: после него вход `EntityScanNodeDraft` — только `EntitySharedJoinNode`/`ResourceNode`, иначе `LogicException`.

Шаги идут от логического плана к физическому:

| Шаг | Вид | Что решает |
|---|---|---|
| `nodes` — `fromTree()` | перевод | дерево запроса → узлы; вид соединения — по виду связи, include — левой цепочкой в порядке объявления |
| `separate` — `SeparateForeignDataRule` | логическое переписывание | связь 1-1 с чужим источником выходит из общей строки: `EntitySharedJoinNode` → `HashOneJoinNode` |
| `lift` — `LiftBranchFromOuterRule` | логическое переписывание | ветки владельца строки поднимаются над строкой: все 1-1 собираются в одну строку |
| `order` — `PreloadBelowNestJoinRule` | логическое переписывание | порядок выполнения: справочник (`PreloadNode`) загружается до nest join |
| `close` — `EntityScanNodeDraftPass` | границы запросов | где кончается один SQL-запрос (`EntityScanNodeDraft`) и какой алгоритм 1:N возможен внутри строки (`HashJoinNode`) |
| `merge` — `HashToMergeRule` | выбор алгоритма | Hash → Merge, где обе стороны можно упорядочить по ключу; порядок у владельца один («интересный порядок», жадно снизу вверх) |
| `sql` — `BuildSqlPass` | генерация | `Select` (pg_builder), параметры и карта колонок у каждого владельца; условия родителей — цепочкой предков (CTE + `IN`) |

Чего оптимизатор не делает: не оценивает стоимость, не перебирает альтернативные планы, не переставляет соединения сверх `lift`/`order`. Неверное дерево на входе — ошибка кода (`LogicException`): клиентские ошибки отсеял парсер.

### 1. Парсинг и валидация

`QueryParser::parse(string $resource, array $query): array` разбирает параметры запроса по корневому ресурсу endpoint'а (`CertificateResource::class`) и строит дерево запроса.

```http
GET /certificates?include=provider,detail,delivery,domains.logs&fields[certificate]=code,status HTTP/1.1
Accept: application/vnd.api+json
```

Что клиенту можно, описывает только ресурс: всё, что в нём объявлено, доступно. Подключить можно любую связь из `getRelationships()` (на любую глубину), выбрать — любые публичные атрибуты, фильтровать и сортировать — по атрибутам, где ресурс объявил `filter`/`sort`. Схемы endpoint'а, которая сужала бы это, пока нет: её добавим, когда всё остальное будет готово.

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
| `fields` | Выбранные атрибуты и связи по внешнему ключу (имена в API). Без `fields[<type>]` — все публичные атрибуты ресурса (`getPublicAttributes()`), в том числе связи по внешнему ключу (`provider`). |
| `filters` | `[{attribute, operator, value}]`, объединяются через AND. Значение уже приведено по `type` атрибута; у `in` — список. |
| `sort` | `[{attribute, descending, position}]` — поля сортировки этого ресурса; `position` — место поля в параметре `sort`, общий порядок полей разных ресурсов. |
| `includes` | Ключ — имя связи; значение — вид связи (`ONE_TO_ONE`/`MANY_TO_ONE`/`ONE_TO_MANY`, по классу связи `OneToOne`/`ManyToOne`/`OneToMany`), её колонки `relation` (`[колонка родителя, колонка ресурса]`) и узел ресурса. |

В дереве только атрибуты, без колонок: атрибут в колонки модели переводит планировщик. Путь узла и его тип в дереве не хранятся: тип есть у ресурса, путь вычисляется при обходе. Структура дерева заморожена: планировщик берёт из него данные как есть.

Правила:

- **Параметры запроса:** только `include`, `fields`, `filter`, `sort`. Любой другой (`page`, …) — 400: JSON:API требует отвечать ошибкой на неподдерживаемые параметры.
- **`include`:** путь `domains.logs` подключает и `domains`, и `logs`. Имя — любая связь из `getRelationships()` ресурса родителя. Нет такой связи — 400.
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

План (`src/Plan`, по операторам: `Plan/Select/`, `Plan/Insert/`, `Plan/Update/`, `Plan/Delete/`, общее для записи — `Plan/Write/`; выборка перенесена из прототипа `../php-tests/src/test24.php`) отделён от выполнения (`src/Executor`; как и планировщики, по операторам: читатели выборки — `Executor/Select/`, исполнители записи — `Executor/Write/`, `Insert/`, `Update/`, `Delete/`): план — неизменяемые узлы без состояния, которые строит и переписывает планировщик; читатели — состояние одного выполнения. План можно выполнять сколько угодно раз, дерево читателей — один. `Executor` зависит от `Plan`, но не наоборот.

Как устроены шаги и почему план без модели стоимости — [Модель оптимизатора](#модель-оптимизатора).

#### Узлы плана

Число входов узла задаёт его класс (модель Volcano). Узел плана и читатель, который его выполняет, называются по одной основе (`HashNestJoinNode` → `HashNestJoinReader`).

| Узел | Входы | Что значит | Откуда берётся |
|---|---|---|---|
| `ResourceNode(resource, fields, filters, sort)` | 0 | Таблица ресурса: модель данных ресурса в FROM запроса, которым владеет ближайший `EntityScanNodeDraft` над ним. Своего запроса нет. | ресурс дерева запроса |
| `EntityScanNodeDraft(input, order)` | 1 | Владелец одного SQL-запроса, даже из одного ресурса. Вход — верх строки: `EntitySharedJoinNode` (ресурс со своими 1-1) или `ResourceNode`. Самый левый `ResourceNode` — владелец строки (`getRootResourceNode()`). Хранит порядок потока и собирает свой запрос (`withSelect()`). | шаг `close` |
| `EntityScanNode(input, order, select, columns, parameters)` | 1 | Тот же владелец после шага `sql`: вход и порядок как у `EntityScanNodeDraft`, плюс собранный `Select`, его параметры и карта колонок строки — все обязательные. План без шага `sql` виден по классу: в нём остались `EntityScanNodeDraft`, и `ReaderBuilder` его не выполняет. | шаг `sql` |
| `ForeignDataScanNode(input, order, keys)` | 1 | Ресурс над чужим источником (`Model\ForeignDataSource`): отдельный лист без SQL, читает код источника. Вход — один `ResourceNode`. Узел отдаёт сборщику класс источника, колонки, условия и сортировку клиента в колонках. | шаг `close` |
| `EntitySharedJoinNode` | 2 | 1-1 в той же строке: inner присоединяется `LEFT JOIN`. | `ONE_TO_ONE` |
| `HashOneJoinNode` | 2 | 1-1 между потоками: одна из сторон — чужой источник, в общую строку она не входит. | шаг `separate` |
| `PreloadNode` | 2 | Многие к одному: inner — отдельный запрос, загружается в реестр после outer. | `MANY_TO_ONE` |
| `HashNestJoinNode` | 2 | 1:N над строкой: хеш по outer, inner — отдельный запрос. | `ONE_TO_MANY` |
| `MergeNestJoinNode` | 2 | 1:N слиянием: обе стороны упорядочены по ключу связи. | шаг `merge` |
| `HashJoinNode` | 2 | 1:N у 1-1 ресурса внутри строки: outer — поток строки, его нельзя читать наперёд, поэтому хеш по inner. | шаг `close` |

Соединение — `JoinNode(name, relation, outer, inner)`: `name` — имя связи в документе, `relation` — `[колонка outer, колонка inner]` (колонки моделей ресурсов). Изменить узел нельзя, только получить копию через `with…()` (clone with, PHP 8.5); тот же вход — тот же объект, поэтому нетронутые поддеревья переиспользуются.

#### Шаги

`SelectPlanner::plan($tree)` возвращает дерево после каждого шага (`nodes`, `separate`, `lift`, `order`, `close`, `merge`, `sql`); `SelectPlanPrinter::getTree()` печатает любое из них. Шаг — `PassInterface` (дерево → дерево); `Rule` — локальное правило, которое применяется снизу вверх до фиксированной точки.

**`nodes` — `fromTree()`.** Ресурс дерева запроса становится `ResourceNode`, каждый include — соединением по виду связи. Include навешиваются левой цепочкой в порядке объявления: каждый следующий — новый корень, всё построенное до него — его outer.

```
HashNestJoinNode domains
  outer: EntitySharedJoinNode delivery
    outer: EntitySharedJoinNode detail
      outer: PreloadNode provider
        outer: ResourceNode CertificateResource
        inner: ResourceNode ProviderResource
      inner: ResourceNode DetailResource
    inner: ResourceNode DeliveryResource
  inner: HashNestJoinNode logs
    outer: ResourceNode DomainResource
    inner: ResourceNode LogResource
```

**`lift` — `LiftBranchFromOuterRule`.** Ветка в outer общей строки поднимается над ней: `EntitySharedJoin(Branch(A, C), B) → Branch(EntitySharedJoin(A, B), C)`. Так все 1-1 оказываются под ветками, даже если ветка объявлена раньше (`provider` до `detail`). Ключ ветки по-прежнему берётся у записей A. Ветки 1-1 ресурса (из inner) не поднимаются: их outer — другой ресурс, они остаются внутри строки.

**`order` — `PreloadBelowNestJoinRule`.** `PreloadNode` опускается под nest join, ближе к строке: `Preload(NestJoin(A, B), C) → NestJoin(Preload(A, C), B)`. Меняется только порядок выполнения: справочник загружается до nest join.

**`close` — `EntityScanNodeDraftPass`.** Над каждым запросом ставится его владелец `EntityScanNodeDraft`: над верхом каждой строки и над каждым `ResourceNode` вне строки. `HashNestJoinNode` внутри строки становится `HashJoinNode`.

**`merge` — `HashToMergeRule`.** `HashNestJoinNode` → `MergeNestJoinNode`, если обе стороны можно упорядочить по ключу связи: outer — по колонке родителя, inner — по колонке ребёнка. Порядок задаёт только владелец запроса (`order`); у него одна ориентация, и занятый другим Merge или клиентским `sort` владелец отказывает. Правило идёт снизу вверх, поэтому глубокие соединения занимают порядок первыми: `logs` получает Merge (домены по `id`), а `domains` над ним остаётся Hash. TODO: колонки порядка не квалифицированы ресурсом.

План после `merge` для `include=provider,detail,delivery,domains.logs`:

```
HashNestJoinNode domains
  outer: PreloadNode provider
    outer: EntityScanNodeDraft row CertificateResource
      input: EntitySharedJoinNode delivery
        outer: EntitySharedJoinNode detail
          outer: ResourceNode CertificateResource
          inner: ResourceNode DetailResource
        inner: ResourceNode DeliveryResource
    inner: EntityScanNodeDraft row ProviderResource
      input: ResourceNode ProviderResource
  inner: MergeNestJoinNode logs
    outer: EntityScanNodeDraft row DomainResource (order by id)
      input: ResourceNode DomainResource
    inner: EntityScanNodeDraft row LogResource (order by domain_id)
      input: ResourceNode LogResource
```

Четыре владельца — четыре SQL-запроса: сертификаты с деталями и доставкой одной строкой, провайдеры, домены, логи.

### 3. SQL

Последний шаг `sql` — `BuildSqlPass`: SQL появляется в плане только здесь, когда дерево уже окончательное. Проход идёт сверху вниз и передаёт ключи соединений к владельцам, которые сами их не видят: соединение отдаёт в outer пришедшие сверху ключи и свой `relation[0]`, в inner — только `relation[1]`. По outer ключи доходят до владельца строки, к которой присоединяется ветка (`provider_id` и `id` — до строки сертификатов). Каждый владелец собирает свой запрос сам и заменяется на `EntityScanNode`: `EntityScanNodeDraft::withSelect($keys)` обходит свою строку слева направо, ресурсы получают алиасы `t0`, `t1`… (таблицы внутри модели ресурса — `t0_1`, `t0_2`…, их задаёт `ModelTree`). SQL строится узлами [pg_builder](https://github.com/sad-spirit/pg-builder), без склейки строк.

#### Список колонок и FROM

- У каждого ресурса строки: первичный ключ (он даёт `id`, а `NULL` в нём — ресурса в строке нет), колонки атрибутов `fields` (у составного — все, у связи по внешнему ключу — колонка ключа) и ключи соединений.
- Колонка выводится как `<алиас ресурса>_<колонка модели>` (`t1_buyer_email`): имена не совпадают, даже если у двух таблиц есть `certificate_id`.
- FROM — `ModelTree::getFrom()` каждого ресурса, только с нужными таблицами (`LEFT JOIN` модели без нужных колонок отбрасывается). Нужны колонки списка, колонки фильтров и (у владельца) колонки `ORDER BY`; последние две в список не выводятся.
- 1-1 ресурсы присоединяются `LEFT JOIN`, в `ON` — только условие связи `relation`.

#### WHERE

Фильтры ресурсов строки, все через `AND`; в `ON` они не идут:

- фильтр владельца строки (`t0`) — условие как есть: `t0.status = :t0_status_0`;
- фильтры 1-1 ресурса — одной группой `(t1.pk IS NULL OR (…))`: строка без этого ресурса остаётся, а строка, где ресурс есть, но не прошёл фильтр, выпадает;
- значение — именованный параметр `:<алиас>_<колонка>_<номер>`; значения лежат в `EntityScanNode::parameters` уже скалярами (`date` — `Y-m-d`, `datetime` — `Y-m-d H:i:s.uP`) и привязываются при выполнении.

#### ORDER BY

Порядок строк — порядок ресурса-владельца (`t0`); сортировки 1-1 ресурсов на него не влияют:

1. `order` — ключ, который занял `HashToMergeRule` (порядок на входах `MergeNestJoinReader` проверяет `AscendingKeyReader`);
2. клиентский `sort` владельца по `position`, `DESC` у `descending`;
3. первичный ключ владельца, если его ещё нет: порядок однозначен от запуска к запуску.

#### Результат

У каждого владельца после шага: `select` (pg_builder `Select`), `parameters` и `columns` — по ресурсу строки слева направо: класс, алиас, карта «колонка модели → колонка строки», `fields` и ключи соединений. По `columns` читатели собирают ресурсы из строки (см. [Сериализация](#сериализация)). `SelectPlanPrinter::getQueries()` печатает SQL и параметры:

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

-- домены: по id — для слияния с логами; certificate_id — ключ связи domains;
-- p1 — строка сертификатов как есть (цепочка предков), без фильтров у неё WHERE пуст
with p1 as (
    select t0.id as id from certificates as t0
    left join certificate_details as t1 on t0.id = t1.certificate_id
    left join certificate_delivery_meta as t2 on t0.id = t2.certificate_id
)
select t0.id as t0_id, t0.domain_name as t0_domain_name, t0.is_primary as t0_is_primary,
       t0.created_at as t0_created_at, t0.certificate_id as t0_certificate_id
from domains as t0 where t0.certificate_id in ( select id from p1 ) order by t0.id

-- логи: по domain_id (order), затем по id; предки — домены (p1) и сертификаты (p2)
with p2 as ( …та же строка сертификатов… ),
p1 as ( select t0.id as id from domains as t0 where t0.certificate_id in ( select id from p2 ) )
select t0.id as t0_id, t0.log_level as t0_log_level, t0.message as t0_message,
       t0.created_at as t0_created_at, t0.domain_id as t0_domain_id
from domain_logs as t0 where t0.domain_id in ( select id from p1 ) order by t0.domain_id, t0.id
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

Дочерний запрос не получает ключи родителей списком `IN (1, 2, …)`: он повторяет условия всех предков, и база возвращает только детей тех родителей, что попадут в ответ. Условия должны повторяться точно: слабее — лишние строки, сильнее — потерянные дети.

Каждый шаг через соединение не 1-1 (`HashNestJoinNode`, `MergeNestJoinNode`, `HashJoinNode`) — CTE и `IN (подзапрос)`:

- запрос outer становится CTE `p{n}` запроса inner. Outer — поддерево: его запрос — `EntityScanNodeDraft`, до которого спускаемся по outer;
- тело CTE — FROM строки outer как есть (все её 1-1 ресурсы через `LEFT JOIN`, ничего не отбрасывается), её WHERE целиком (фильтры владельца и группы `(pk IS NULL OR …)` 1-1 ресурсов) и её собственные предки; в списке колонок только ключ `relation[0]` под своим именем (из ресурса, которому он принадлежит, — владельца строки или 1-1 ресурса), без `ORDER BY`;
- inner получает `WHERE relation[1] IN (select ключ from p{n})` — полусоединение: без ограничения на число родителей, строки не размножаются, уникальность ключа не важна;
- список `WITH` плоский, от дальнего предка к ближнему (`pn`, …, `p1`: CTE ссылается только на объявленные раньше), имена `p1`, `p2`… по глубине от inner; внутри каждого CTE алиасы снова с `t0` (своя область видимости);
- параметры фильтров предка получают префикс CTE (`:p1_t0_status_0`) и лежат в `parameters` дочернего запроса вместе с его собственными;
- CTE ставится всегда, даже если у предка нет фильтров и его WHERE пуст. Таблица сущности может быть view со своими условиями (например, по активному пользователю) или таблицей под RLS: какие строки родителя видны, знает только запрос родителя. Без CTE дочерний запрос прочитал бы детей невидимых родителей — лишние строки и обход ограничений доступа.

Цепочку собирает `BuildSqlPass`: она идёт сверху вниз, как ключи, — outer получает цепочку соединения как есть, inner — с новым ближайшим предком спереди. Сам запрос строит `EntityScanNodeDraft::withSelect($keys, $ancestors)`. Для `include=delivery,domains.logs&filter[status]=ACTIVE&filter[delivery.is_sent]=true` (`php example/certificates.php '<URL>'`) запрос логов:

```sql
with p2 as (
    select t0.id as id
    from certificates as t0
    left join certificate_delivery_meta as t1 on t0.id = t1.certificate_id
    where t0.status = :p2_t0_status_0 and ( t1.certificate_id is null or t1.is_sent = :p2_t1_is_sent_1 )
), p1 as (
    select t0.id as id from domains as t0 where t0.certificate_id in ( select id from p2 )
)
select t0.id as t0_id, t0.log_level as t0_log_level, t0.message as t0_message, t0.created_at as t0_created_at, t0.domain_id as t0_domain_id
from domain_logs as t0
where t0.domain_id in ( select id from p1 )
order by t0.domain_id, t0.id
-- parameters: {"p2_t0_status_0": "ACTIVE", "p2_t1_is_sent_1": true}
```

У 1:N внутри строки (`HashJoinNode` у 1-1 ресурса) предок — запрос самой строки, а ключ берётся у 1-1 ресурса: `select t1.certificate_id as certificate_id from certificates as t0 left join certificate_delivery_meta as t1 …`.

`PreloadNode` (`ManyToOne`) CTE не получает: ресурс по такой связи считается небольшим словарём и читается целиком; в `included` всё равно попадают только те, на кого ссылаются. `LEFT JOIN` 1-1 ресурсов из тела CTE пока не отбрасываются, даже без фильтров по ним (оптимизация на потом). Ребёнок без родителя — ошибка плана: `HashNestJoinReader` и `MergeNestJoinReader` бросают `LogicException`.

### 4. Сборка исполнителя

`ReaderBuilder(PDO, ContainerInterface)` собирает по плану после шага `sql` дерево читателей (контейнер PSR-11 — источник объектов клиентских классов, см. [Зависимости](#зависимости)): `build($plan, $registry)` — само дерево, `getDocument($plan)` — `Document` над ним с новым `ResourceRegistry`. Сборщик состояния выполнения не хранит; на каждое выполнение — новый вызов.

| Узел плана | Читатели |
|---|---|
| `EntityScanNode` с одним ресурсом | `RegisterReader(SerializeReader(EntityScanReader(pdo, sql, parameters), resource, columns[0]), registry)` |
| `EntityScanNode` со строкой | `EntityScanReader` → `EntitySharedWindowReader`; поток строки по её узлам; сверху `EntitySharedAdvanceReader(data, поток строки)` |
| `ResourceNode` внутри строки | `RegisterReader(SerializeReader(EntitySharedWindowReader, resource, следующая запись columns), registry)` — записи идут в порядке обхода строки (outer раньше inner), как их записал `withSelect()` |
| `EntitySharedJoinNode` | `EntitySharedJoinReader(outer, inner, name)` |
| `HashJoinNode` (внутри строки) | `HashJoinReader(поток строки, запрос inner, relation[0], relation[1], name)` |
| `ForeignDataScanNode` | `RegisterReader(SerializeReader(ForeignDataScanReader(объект источника из контейнера, колонки, условия, сортировка, аргументы маршрута), resource, запись колонок узла), registry)` |
| `HashOneJoinNode` | `HashOneJoinReader(outer, inner, relation[0], relation[1], name)` |
| `PreloadNode` | `PreloadReader(source: outer, preload: inner)` |
| `HashNestJoinNode` | `HashNestJoinReader(outer, inner, relation[0], relation[1], name)` |
| `MergeNestJoinNode` | `MergeNestJoinReader(UniqueAscendingKeyReader(outer, relation[0]), AscendingKeyReader(inner, relation[1]), relation[0], relation[1], name)` |

Ошибки плана — `LogicException` при сборке, до выполнения: план без шага `sql` (в нём остался `EntityScanNodeDraft`), `ResourceNode`/`EntitySharedJoinNode`/`HashJoinNode` вне строки, `HashNestJoinNode`/`MergeNestJoinNode`/владелец запроса внутри строки, записи `columns` не того ресурса.

Дерево читателей для плана выше (топология та же, что у дерева, собранного руками в `example/document.php`):

```
HashNestJoinReader domains (id ← certificate_id)
├─ outer: PreloadReader
│   ├─ source: EntitySharedAdvanceReader
│   │   └─ EntitySharedJoinReader delivery
│   │       ├─ EntitySharedJoinReader detail
│   │       │   ├─ Register ─► SerializeReader certificate (t0) ─┐
│   │       │   └─ Register ─► SerializeReader detail (t1) ──────┼─► EntitySharedWindowReader ─► EntityScanReader сертификатов
│   │       └─ Register ─► SerializeReader delivery (t2) ────────┘
│   └─ preload: Register ─► SerializeReader provider ─► EntityScanReader провайдеров
└─ inner: MergeNestJoinReader logs (id ← domain_id)
    ├─ UniqueAscending id ─► Register ─► SerializeReader domain ─► EntityScanReader доменов
    └─ Ascending domain_id ───► Register ─► SerializeReader log ────► EntityScanReader логов
```

`Register` — `RegisterReader(source, registry)`: стоит над каждым сериализатором и кладёт его ресурсы в общий `ResourceRegistry`. `Ascending` — `AscendingKeyReader(source, key)`: проверяет порядок ключей на входах слияния (на входе outer — его строгий наследник `UniqueAscendingKeyReader`).

#### Пример: `example/query-plan.php`

`example/query-plan.php` проходит стадии 2–6 без парсера: дерево запроса `TREE` (см. [Дерево запроса](#дерево-запроса)) → план → дерево читателей → документ.

```php
$steps = (new SelectPlanner())->plan(tree: TREE);       // дерево после каждого шага
$plan = $steps['sql'];                                 // план с SQL

$builder = new ReaderBuilder(pdo: getPDO(), container: new ArrayContainer(services: ExampleResources::getAll()));   // контейнер с объектами ресурсов
$flow = $builder->build(plan: $plan, registry: new ResourceRegistry());   // дерево читателей на одно выполнение
echo json_encode($flow->explain(), JSON_PRETTY_PRINT);                   // топология, ничего не выполняя

echo json_encode($builder->getDocument(plan: $plan));     // новое дерево читателей, выполняется внутри json_encode()
```

`php example/query-plan.php --explain` печатает дерево читателей для этого плана (ниже классы без пространства имён; SQL сертификатов печатается у каждого потребителя `EntitySharedWindowReader` и здесь сокращён после первого раза):

```json
{
    "node": "HashNestJoinReader",
    "name": "domains",
    "outer_key": "id",
    "inner_key": "certificate_id",
    "outer": {
        "node": "PreloadReader",
        "source": {
            "node": "EntitySharedAdvanceReader",
            "data": {
                "node": "EntitySharedWindowReader",
                "source": {
                    "node": "EntityScanReader",
                    "sql": "select t0.id as t0_id, t0.code as t0_code, t0.nominal as t0_nominal, t0.currency as t0_currency, t0.status as t0_status, t0.created_at as t0_created_at, t0.provider_id as t0_provider_id, t1.certificate_id as t1_certificate_id, t1.buyer_email as t1_buyer_email, t1.recipient_name as t1_recipient_name, t1.recipient_email as t1_recipient_email, t1.greeting_text as t1_greeting_text, t1.design_template_id as t1_design_template_id, t2.certificate_id as t2_certificate_id, t2.delivery_channel as t2_delivery_channel, t2.is_sent as t2_is_sent, t2.external_provider_id as t2_external_provider_id from certificates as t0 left join certificate_details as t1 on t0.id = t1.certificate_id left join certificate_delivery_meta as t2 on t0.id = t2.certificate_id order by t0.id"
                }
            },
            "source": {
                "node": "EntitySharedJoinReader",
                "name": "delivery",
                "outer": {
                    "node": "EntitySharedJoinReader",
                    "name": "detail",
                    "outer": {
                        "node": "RegisterReader",
                        "source": {
                            "node": "SerializeReader",
                            "resource": "CertificateResource",
                            "alias": "t0",
                            "fields": ["code", "nominal", "currency", "status", "created_at", "provider"],
                            "source": {
                                "node": "EntitySharedWindowReader",
                                "source": {
                                    "node": "EntityScanReader",
                                    "sql": "… (тот же SQL сертификатов)"
                                }
                            }
                        }
                    },
                    "inner": {
                        "node": "RegisterReader",
                        "source": {
                            "node": "SerializeReader",
                            "resource": "DetailResource",
                            "alias": "t1",
                            "fields": ["buyer_email", "recipient_name", "recipient_email", "greeting_text", "design_template_id"],
                            "source": {
                                "node": "EntitySharedWindowReader",
                                "source": {
                                    "node": "EntityScanReader",
                                    "sql": "… (тот же SQL сертификатов)"
                                }
                            }
                        }
                    }
                },
                "inner": {
                    "node": "RegisterReader",
                    "source": {
                        "node": "SerializeReader",
                        "resource": "DeliveryResource",
                        "alias": "t2",
                        "fields": ["delivery_channel", "is_sent", "external_provider_id"],
                        "source": {
                            "node": "EntitySharedWindowReader",
                            "source": {
                                "node": "EntityScanReader",
                                "sql": "… (тот же SQL сертификатов)"
                            }
                        }
                    }
                }
            }
        },
        "preload": {
            "node": "RegisterReader",
            "source": {
                "node": "SerializeReader",
                "resource": "ProviderResource",
                "alias": "t0",
                "fields": ["name", "website", "created_at"],
                "source": {
                    "node": "EntityScanReader",
                    "sql": "select t0.id as t0_id, t0.\"name\" as t0_name, t0.website as t0_website, t0.created_at as t0_created_at from providers as t0 order by t0.id"
                }
            }
        }
    },
    "inner": {
        "node": "MergeNestJoinReader",
        "name": "logs",
        "outer_key": "id",
        "inner_key": "domain_id",
        "outer": {
            "node": "UniqueAscendingKeyReader",
            "key": "id",
            "source": {
                "node": "RegisterReader",
                "source": {
                    "node": "SerializeReader",
                    "resource": "DomainResource",
                    "alias": "t0",
                    "fields": ["domain_name", "is_primary", "created_at"],
                    "source": {
                        "node": "EntityScanReader",
                        "sql": "with p1 as ( select t0.id as id from certificates as t0 left join certificate_details as t1 on t0.id = t1.certificate_id left join certificate_delivery_meta as t2 on t0.id = t2.certificate_id ) select t0.id as t0_id, t0.domain_name as t0_domain_name, t0.is_primary as t0_is_primary, t0.created_at as t0_created_at, t0.certificate_id as t0_certificate_id from domains as t0 where t0.certificate_id in ( select id from p1 ) order by t0.id"
                    }
                }
            }
        },
        "inner": {
            "node": "AscendingKeyReader",
            "key": "domain_id",
            "source": {
                "node": "RegisterReader",
                "source": {
                    "node": "SerializeReader",
                    "resource": "LogResource",
                    "alias": "t0",
                    "fields": ["log_level", "message", "created_at"],
                    "source": {
                        "node": "EntityScanReader",
                        "sql": "with p2 as ( select t0.id as id from certificates as t0 left join certificate_details as t1 on t0.id = t1.certificate_id left join certificate_delivery_meta as t2 on t0.id = t2.certificate_id ), p1 as ( select t0.id as id from domains as t0 where t0.certificate_id in ( select id from p2 ) ) select t0.id as t0_id, t0.log_level as t0_log_level, t0.message as t0_message, t0.created_at as t0_created_at, t0.domain_id as t0_domain_id from domain_logs as t0 where t0.domain_id in ( select id from p1 ) order by t0.domain_id, t0.id"
                    }
                }
            }
        }
    }
}
```

Что видно в описании:

- `EntitySharedWindowReader` с запросом сертификатов встречается четыре раза — у `EntitySharedAdvanceReader` (`data`, он сдвигает строку) и у трёх `SerializeReader` (`t0`, `t1`, `t2`, они читают окно на ту же строку). Это один объект и один SQL-запрос: `explain()` описывает узел у каждого потребителя.
- `provider` — отдельный запрос под `PreloadReader`: он только наполняет реестр, в поток корня не попадает.
- `logs` соединяются слиянием: домены упорядочены по `id`, логи — по `domain_id`; `domains` над ними — хеш-соединение по `id` сертификата.
- У запросов доменов и логов есть `with p1 …`: цепочка предков повторяет запрос сертификатов (и доменов — для логов), даже без фильтров — сертификаты могут оказаться view или таблицей под RLS.
- Параметров у запросов нет: в `TREE` нет фильтров. С фильтром у `EntityScanReader` появляется ключ `parameters` (у дочерних запросов — и параметры предков, `p1_…`).

### 5. Выполнение

Исполнитель — дерево узлов-читателей по [модели Volcano](#модель-volcano). Каждый узел — `Executor\PullExecutorInterface`: `IteratorAggregate`, чей `getIterator()` отдаёт генератор, и `explain()`. Узел-родитель перебирает поток своих входов, поэтому выполнение — это перебор корня, а данные поднимаются от листьев (`EntityScanReader`) к корню. Запросы выполняются лениво, при первом обращении к генератору; всё дерево выполняется внутри `json_encode()` документа.

Узлы хранят состояние и **одноразовые**: дерево собирается на одно выполнение и повторно не выполняется. Второй перебор молча отдаёт пустой поток.

#### Узлы

| Узел | Поток | Что делает |
|---|---|---|
| `EntityScanReader(PDO, sql, parameters)` | — → строки | Выполняет запрос при первом обращении к генератору, отдаёт строки по одной. Именованные параметры привязывает `bindValue()` с типом по типу PHP (не `execute($массив)`: он передал бы `false` как `''`). |
| `SerializeReader(source, resource, columns)` | строки → `?ResourceRecord` | Строит ресурс из строки по описанию ресурса и записи `EntityScanNode::columns`; `null` — ресурса в строке нет (пустой `LEFT JOIN`). Реестра не знает. В деревьях без плана (`example/document.php`, `DocumentTreeTest`) запись колонок пишется руками. |
| `ManualSerializeReader(source, resource, columns)` | строки → `?ResourceRecord` | Наследник `SerializeReader` для ресурса, реализующего `Resource\SerializeInterface` (ручная сериализация): отдаёт строку «колонка модели → значение» методу ресурса вместо сборки по описанию; `NULL` в первичном ключе и ключи соединений — как у родителя. Ставит его `ReaderBuilder`, если класс ресурса реализует интерфейс; ресурс без интерфейса — `LogicException`. |
| `RegisterReader(source, registry)` | `?ResourceRecord` → `?ResourceRecord` | Стоит над каждым сериализатором: регистрирует ресурс в `ResourceRegistry`, для повторного `type:id` дальше идёт уже зарегистрированный объект; `null` проходит. Без него ресурс не попадёт в `included`. |
| `EntitySharedWindowReader(source)` | строки → строки | Раздаёт одну строку нескольким `SerializeReader`: каждый получает своё «окно» на текущую строку и не может её сдвинуть. |
| `EntitySharedAdvanceReader(data, source)` | ресурсы → ресурсы | Корень поддерева одного SQL: отдаёт ресурс наверх и сдвигает общий источник на следующую строку. |
| `EntitySharedJoinReader(outer, inner, name)` | ресурсы × ресурсы → ресурсы | Склеивает n-й элемент `outer` с n-м элементом `inner` (как `zip`) и пишет связь «к одному»: `outer.relationships[name] = inner` или `null`. |
| `HashNestJoinReader(outer, inner, outer_key, inner_key, name)` | ресурсы × ресурсы → ресурсы | Связь «ко многим»: читает `outer` целиком в хеш-таблицу, затем `inner` построчно дописывает в связь своего родителя. Блокирующий. |
| `HashJoinReader(outer, inner, outer_key, inner_key, name)` | ресурсы строки × ресурсы → ресурсы строки | Связь «ко многим» у 1-1 ресурса внутри общей строки: хеширует `inner` целиком, `outer` идёт потоком; на каждый элемент `outer` — ровно один элемент, `null` проходит как есть. |
| `MergeNestJoinReader(outer, inner, outer_key, inner_key, name)` | ресурсы × ресурсы → ресурсы | Связь «ко многим» слиянием двух потоков, отсортированных по ключу; порядок сам не проверяет. Не блокирует, хеш-таблицы не строит. |
| `AscendingKeyReader(source, key)` | ресурсы → ресурсы | Проверяет на лету, что ключи потока не убывают (повторы допустимы): вход inner слияния. Нарушение — `UnexpectedValueException` посреди потока; `null` проходит. В дереве по плану ставит `ReaderBuilder`, в ручном — тот, кто собирает дерево. |
| `UniqueAscendingKeyReader(source, key)` | ресурсы → ресурсы | Наследник `AscendingKeyReader` со строгой проверкой: повтор ключа — тоже нарушение. Вход outer слияния: ключ главного ресурса уникален. |
| `SortReader(source, key)` | ресурсы → ресурсы | Пересортировывает выход соединения по целочисленному ключу (для `MergeNestJoinReader`). Планировщик его пока не ставит. |
| `PreloadReader(source, preload)` | ресурсы → ресурсы | Пропускает `source`, затем дочитывает `preload`: его ресурсы попадают в реестр (справочник для связи по внешнему ключу). |

#### Одна строка — несколько ресурсов

Запрос сертификатов отдаёт сертификат, детали и доставку одной строкой. `EntitySharedWindowReader` раздаёт её трём `SerializeReader`, `EntitySharedJoinReader` склеивает их результаты по позиции, `EntitySharedAdvanceReader` сдвигает строку, когда наверх ушёл сертификат. Тройка работает только вместе и держится на правиле: каждая ветка за такт читает ровно одну строку. Поэтому отсутствие ресурса — это `yield null`, а не пропуск: доставки нет → `SerializeReader delivery` отдаёт `null` → у сертификата `"delivery": {"data": null}`. По тому же правилу `HashJoinReader` внутри строки отдаёт элемент на каждый элемент своего outer.

#### Связи между запросами

Ключи соединения задаются именами колонок модели: значение берётся через `ResourceRecord::getKey()`; `'id'` — id ресурса, остальные — служебные ключи (`keys`), которые `SerializeReader` кладёт в ресурс по записи `columns` и которые в JSON не выводятся. Ключи — только целые числа.

- `HashNestJoinReader domains`: таблица сертификатов по `id`, домены ищут родителя по `certificate_id`. Каждому сертификату сразу пишется пустая связь `domains: []`, дальше в неё дописываются домены в порядке `inner`.
- `MergeNestJoinReader logs`: домены и логи отсортированы по id домена, логи присоединяются за один совместный проход.
- Соединения только пишут связи и ничего не копируют: связанные ресурсы попадают в ответ через документ (см. [Документ](#6-документ)).
- Ребёнок без родителя пропускается: связь ему записать некому, а в документ он не попадёт. У запросов к базе такого ребёнка обычно нет — дочерний SQL повторяет условия родителя (цепочка предков); он появляется, если база изменилась между двумя запросами, и всегда возможен у чужого источника (`ForeignDataSource`), который условий родителя не получает. Тихий отказ: ошибка плана (неверный ключ связи) исключения не даёт — связи окажутся пустыми. `MergeNestJoinReader` после конца `outer` дочитывает `inner` до конца, чтобы закрыть его курсор.

#### Связь по внешнему ключу

`provider_id` лежит в строке сертификата, поэтому идентификатор провайдера известен без соединения: `provider` — публичный атрибут сертификата (`ManyToOne` по внешнему ключу), и `SerializeReader` пишет связь из своей строки — `{"id": "3", "type": "provider"}` или `{"data": null}` при `NULL`.

Без `include=provider` связь в ответе есть, а провайдера в `included` нет. С `include=provider` провайдеров достаточно загрузить в реестр — это делает `PreloadReader`; соединять ничего не нужно.

#### EXPLAIN

`explain()` описывает узел и его поддерево массивом, ничего не выполняя: класс узла, параметры и входы под именами параметров конструктора; у `SerializeReader` — класс ресурса (по плану — ещё алиас и `fields`), у `EntityScanReader` — SQL и параметры.

```php
$flow = (new ReaderBuilder($pdo, $container))->build($plan, new ResourceRegistry());
echo json_encode($flow->explain(), JSON_PRETTY_PRINT);
```

Полный вывод для запроса примера — в [Пример: `example/query-plan.php`](#пример-examplequery-planphp).

### 6. Документ

Ресурс в потоке — `Document\ResourceRecord`: `id`, `type`, `attributes`, `relationships` (ссылки `{id, type}`) и служебные `keys`. Объект изменяемый: соединения дописывают ему связи после создания.

`Document\ResourceRegistry` хранит один объект на `type:id` за выполнение. Его получают все `RegisterReader` дерева и документ.

`Document\Document(flow, registry)` собирается внутри `json_encode()`:

- `data` — все ресурсы потока корня по порядку;
- `included` — обход в ширину по связям от `data`: каждый identifier ищется в реестре, найденный ресурс добавляется и обходится дальше (сертификат → домен → лог). Каждый ресурс попадает в документ один раз.

Ресурс, до которого нельзя дойти по связям (провайдер справочника, на которого не ссылается ни один сертификат ответа), в документ не попадает. Ресурс, который не загружался (провайдер без `include`), остаётся ссылкой в `relationships`.

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

## Запись (`insert`, `update`, `delete`)

Один конвейер для `POST`, `PATCH` и `DELETE`: документ JSON:API → `Parser\Write\WriteDocumentParser` → дерево операций → `Plan\Write\WritePlanner` (план) → `Executor\Write\WriterBuilder` → дерево исполнителей (push) → строки в таблицах. Примеры — `example/patch-document.php` (девять сценариев: все три запроса, вложенный маршрут, замена связей), `example/insert-plan.php`, `example/insert-document.php` (дерево руками) и `example/insert-route.php`; пошаговый разбор `PATCH` с диаграммами — `example/patch-document.md`. Тесты — `test/Parser/Write/`, `test/Plan/Write/`, `test/Plan/Insert/`, `test/Plan/Update/`, `test/Executor/Write/`, `test/Executor/Insert/`.

```php
$parser = new WriteDocumentParser();
$roots = $parser->insert(document: $body, resource: CertificateResource::class);              // POST: список корней
$roots = [$parser->update(document: $body, resource: CertificateResource::class, id: $id)];   // PATCH
$roots = [$parser->delete(resource: CertificateResource::class, id: $id)];                    // DELETE
// InvalidDocumentException: 400 / 403 / 409 с JSON Pointer, до первого запроса в базу

$plan = new WritePlanner()->plan(resource: CertificateResource::class, roots: $roots);
$pdo->beginTransaction();
$builder = new WriterBuilder(pdo: $pdo, lock: true, container: $container);   // $container — PSR-11 контейнер приложения
$keys = $builder->getRoot(plan: $plan, data: $roots)->execute();                 // ключи корней
$pdo->commit();                                    // WriteNotFoundException (404) — откатить
```

Ответ: `POST` одного ресурса — `201 Created` + `Location` + `{data: {type, id}}`, списка — `201` без `Location`, `data` — идентификаторы в порядке запроса; `PATCH` и `DELETE` — `204 No Content`. Документ после записи не перечитывается: свежие данные клиент берёт через `GET`. Ответ, контроллер и транзакция — забота вызывающего.

**Что можно делать — решает ресурс.** Маркерные интерфейсы `Resource\InsertableInterface`, `Resource\UpdatableInterface`, `Resource\DeletableInterface` (чтение — свойство любого ресурса). Парсер проверяет их до первого запроса, без маркера — 403: вставка — `InsertableInterface`; изменение (корень `PATCH`, даже если меняются только связи; ребёнок `{id}` + ресурс в `included`) — `UpdatableInterface`; удаление, полная замена to-many и замена to-one — `DeletableInterface` цели, по форме документа. Сослаться на существующую запись `{type, id}` можно без маркеров (`provider` у сертификата). У сущностей таких флагов нет; запрет писать колонку — `mode` READ атрибута. Ограничения точки входа или роли — будущая схема.

**Входной документ.** Новые связанные ресурсы передаются в `included` (нестандартный sideposting) и названы `lid` (JSON:API 1.1); `data` у `POST` может быть списком (несколько корней). Операция над элементом связи to-many — в `meta.op` идентификатора:

```php
['data' => ['type' => 'certificate', 'id' => '1',
    'attributes' => ['nominal' => '2500.00', 'recipient_name' => null],           // null — NULL в колонку
    'relationships' => [
        'provider' => ['data' => ['type' => 'provider', 'id' => '2']],            // внешний ключ: только существующий
        'delivery' => ['data' => ['type' => 'delivery', 'lid' => 'del1']],        // to-one: заменить новой
        'domains'  => ['data' => [
            ['type' => 'domain', 'id' => '3'],                                    // есть в included — изменить
            ['type' => 'domain', 'id' => '2', 'meta' => ['op' => 'remove']],      // удалить
            ['type' => 'domain', 'lid' => 'd1'],                                  // новый, из included
        ]],
    ]],
 'included' => [
    ['type' => 'domain', 'id' => '3', 'attributes' => ['is_primary' => true]],
    ['type' => 'domain', 'lid' => 'd1', 'attributes' => ['domain_name' => 'example.com']],
    ['type' => 'delivery', 'lid' => 'del1', 'attributes' => ['delivery_channel' => 'SMS']],
 ]]
```

| элемент связи to-many | операция |
|---|---|
| `{type, lid}` + ресурс в `included` | вставить нового ребёнка (`InsertOperation`) |
| `{type, id}` + ресурс в `included` | изменить своего ребёнка (`UpdateOperation`) |
| `{type, id}` | оставить, только проверить принадлежность (`KeepOperation`) |
| `{type, id, meta: {op: remove}}` | удалить ребёнка (`DeleteOperation`) |

Список без единой пометки `meta.op` — стандартная полная замена JSON:API: дети, не названные в списке, удаляются. Хоть одна пометка — частичное изменение: неназванные не трогаются. Связь to-one по владению — без `meta.op`, по форме: `{lid}` + included — заменить (текущий удаляется), `{id}` + included — изменить, `{id}` — проверить, `null` — удалить текущего. Связи нет в документе — её детей не трогают. Создаются только корень и его потомки по владению; связь по внешнему ключу принимает только `{type, id}` существующего ресурса или `null`.

**Парсер** (`WriteDocumentParser`) проверяет весь документ до первого запроса и сразу выдаёт **дерево операций**: `InsertOperation`, `UpdateOperation`, `KeepOperation`, `DeleteOperation` (вид операции — класс), связь по владению — `RelationshipOperation(replace, children)`, имя ресурса — `PersistentIdentity` (`id`) или `LocalIdentity` (`lid`). Каждый ресурс `included` использован ровно один раз (у ресурса один родитель, циклов нет); дети связи уже в порядке выполнения: удаления, оставленные и изменённые, вставки — освобождённое значение уникальной колонки можно занять новым ребёнком в том же запросе. `meta.op`, `included` и место в документе дальше парсера не идут. Ошибки — `InvalidDocumentException` со статусом 400 (форма документа), 403 (нет возможности ресурса, клиентский `id`) или 409 (`type`/`id` не совпали с маршрутом) и JSON Pointer.

**Планировщик** (`WritePlanner::plan(resource, roots, parent, relationship)`) строит план по форме дерева операций, как план SELECT — по дереву запроса. Место — ресурс и все его операции в одной позиции дерева (корни; все дети одной связи). План — неизменяемые узлы:

- `WriteOperationNode(resource, add, update, keep, remove)` — развилка места с ветвями только тех операций, что в нём встретились: `InsertResourceNode`, `UpdateResourceNode`, `KeepEntityNode` (ресурс только ищут: `KeepOperation`), `DeleteEntityNode`;
- `WriteLinkedKeyNode(key, input)` — сторож над ветвью `update`, `keep` или `remove` места, у ресурса которого первичный ключ — сама колонка связи с родителем (`delivery.certificate_id`): id операции сверяется с ключом родителя до запроса;
- колонки связи места (`relation[1]` связи или вложенного маршрута) планировщик проверяет один раз: для `update` — колонка модели ресурса, для `keep` и `remove` — колонка главной таблицы; иначе `LogicException` при построении плана, исполнители проверку не повторяют;
- `WriteJoinNode(name, relation, parent, child)` — связь по владению: владелец — `parent`, место детей — `child`; связи одного владельца — левый глубокий список в **порядке объявления** в `getRelationships()`: зависимую связь объявляют позже (`delivery` после `detail`, потому что `certificate_delivery_meta` ссылается на `certificate_details`; сущности такую ссылку не описывают);
- `EntityReplaceNode(name, relation, child_resource, input)` — полная замена связи, стоит под узлом связи как его `parent`, а при замене пустым — без узла связи; у `POST` его не бывает;
- `WriteRootNode(input, route)` — корень; `route` — `EntityRouteParentNode` вложенного маршрута;
- уровень таблиц по `getModel()` ресурса: вставка — `InsertPlanner::getEntities()` (`InsertEntityJoinNode(relation, parent, child)`, родитель — сторона, от которой зависит другая, по `PRIMARY`/`references` сущностей; правая сторона `LeftJoin` — `InsertOptionalEntityNode`), изменение — `UpdatePlanner::getEntities()` (`UpdateEntityJoinNode(relation, main, joined)`: сначала главная таблица, потом присоединённая по ключу, который вернула главная; правая сторона `LeftJoin` — `UpdateOptionalEntityNode`: изменить, а если строки нет — вставить).

В плане только форма: значений и id в нём нет, один план годится для любого запроса той же формы. Какая ветвь развилки и выполнять ли замену у конкретного элемента — говорят данные (класс операции, `RelationshipOperation::$replace`).

**Дерево исполнителей** — два уровня, суффикс `…Writer` (у чтения — `…Reader`, `…Node` остаётся за планом); узел плана и исполнитель соответствуют по основе имени, собирает `WriterBuilder`:

- уровень ресурсов (`PushExecutorInterface`, `push(WriteOperation $operation, array $link): array`): `WriteJoinWriter` выполняет владельца (`parent`) и отправляет его детей в `child` с колонкой связи из ключей владельца; `EntityReplaceWriter` удаляет неназванных детей (`DELETE … WHERE <колонка связи> = :parent AND <pk> NOT IN (…)`); `WriteOperationWriter` выбирает ветвь по классу операции, пустые ключи у изменения, проверки и удаления — `WriteNotFoundException` (404, ресурс в `meta`: `{type, id}`);
- граница — `InsertResourceWriter`, `UpdateResourceWriter`, `KeepEntityWriter`, `DeleteEntityWriter`: операция → строка «колонка модели → значение» (атрибуты, внешние ключи) → поддерево таблиц; поиск без изменения (`KeepOperation`) — один `SELECT` главной таблицы по первичному ключу и колонкам связи, без поддерева таблиц; удаление — одна строка главной таблицы, зависимое удаляет база (`ON DELETE CASCADE`);
- уровень таблиц (`EntityWriterInterface`): `InsertEntityJoinWriter`, `InsertOptionalEntityWriter`, `InsertEntityWriter` (`INSERT … RETURNING`); `UpdateEntityJoinWriter`, `UpdateOptionalEntityWriter`, `UpdateEntityWriter` (`UPDATE … WHERE <ключ> = :key AND <колонка связи с родителем> AND (<колонка> IS DISTINCT FROM …) RETURNING`; без результата — `SELECT`, чтобы отличить «нечего менять» от «строки нет»). SQL через pg_builder, запрос — в `error_log()`.

Ключи возвращаются из `push()` наверх, общего контекста нет. У изменения и удаления ребёнка колонка связи с родителем стоит в `WHERE`: чужой ребёнок не найдётся — 404. `WriteRootWriter` отправляет в дерево каждый корень и возвращает их ключи; транзакцию открывает вызывающий.

**Вложенный маршрут.** Ресурс к существующему родителю создаётся по маршруту, а не по связи в документе: `POST /certificates/1/domains` — `WritePlanner::plan(DomainResource::class, $roots, parent: CertificateResource::class, relationship: 'domains')` кладёт в план `EntityRouteParentNode`; id родителя — значение, его получает `WriterBuilder::getRoot(plan, data, route_id)`. `EntityRouteParentWriter` один раз, до первой записи, проверяет, что родитель есть (`SELECT … FOR KEY SHARE` на PostgreSQL, флаг `lock`; нет — 404), и даёт каждому корню колонку связи. Своя связь `certificate` у `DomainResource` не нужна (она попала бы в `fields` по умолчанию и изменила бы документ SELECT).

Дерево исполнителей `PATCH` из примера выше:

```
WriteJoinWriter domains [id, certificate_id]
├─ parent: WriteJoinWriter delivery [id, certificate_id]
│  ├─ parent: EntityReplaceWriter delivery
│  │  └─ input: WriteOperationWriter CertificateWithDetailResource
│  │     └─ update: UpdateResourceWriter
│  │        └─ UpdateEntityJoinWriter [id → certificate_id]
│  │           ├─ main:   UpdateEntityWriter certificates
│  │           └─ joined: UpdateOptionalEntityWriter (UpdateEntityWriter | InsertEntityWriter certificate_details)
│  └─ child: WriteOperationWriter DeliveryResource
│     └─ add: InsertResourceWriter └─ InsertEntityWriter certificate_delivery_meta
└─ child: WriteOperationWriter DomainResource
   ├─ add:    InsertResourceWriter └─ InsertEntityWriter domains
   ├─ update: UpdateResourceWriter └─ UpdateEntityWriter domains
   └─ remove: DeleteEntityWriter domains
```

**Не делается:** типы значений атрибутов и обязательные атрибуты при вставке не проверяются; цель связи по внешнему ключу не проверяется (несуществующая — ошибка внешнего ключа базы); многие-ко-многим нет; перенос существующего ребёнка к другому родителю не поддерживается; необязательная правая сторона модели при изменении — одна таблица.

## Описание данных

Описание данных делится на две части (схема endpoint'а, третья часть, пока убрана):

- **Сущность** (`Model\DatabaseEntitySource`, слой `src/Model`; наследник `Model\DataSource` — общего описания табличной структуры) описывает одну таблицу: колонки, их типы, первичный и уникальные ключи, внешние ключи. О JSON:API она не знает: ресурсы собирают из сущностей свою модель данных, а запись берёт из них порядок вставки таблиц модели (`PRIMARY`/`references`, см. [Запись](#запись-insert-update-delete)); типы значений при записи пока не проверяются.
- **Класс ресурса** описывает JSON:API ресурс: `type`, атрибуты, первичный ключ, связи с другими ресурсами, модель данных (из каких сущностей ресурс читается). Он не зависит от endpoint'а: endpoint называет корневой ресурс, а всё, что объявлено в ресурсах, доступно клиенту.

### Сущность

Слой `src/Model` (`Ggs\JsonApiEngine\Model`) — описание таблиц и моделей данных поверх них: `DataSource`, `DatabaseEntitySource`, `ForeignDataSource`, `MetadataInterface`, `NamedConstants`, `Join`/`LeftJoin`/`InnerJoin`, `ModelTree`. Раньше это была отдельная библиотека `ggs/data-model`. Слой не знает о ресурсах, плане и JSON:API: зависимости идут только к нему, не от него.

PHP-класс, унаследованный от `Model\DatabaseEntitySource`, по одному на таблицу (`example/Entity/`). В нём только константы: `TABLE`, по строковой константе на колонку и `COLUMNS` — описание колонок.

`DatabaseEntitySource` наследует `Model\DataSource` — базовый класс источника данных. `DataSource` описывает только табличную структуру (`COLUMNS`, первичный и уникальные ключи, `validate()`) и не говорит, где лежат данные; `DatabaseEntitySource` добавляет таблицу БД: `TABLE` и ключ `column` (имя колонки в БД). Второй вид источника — чужой: он наследует `Model\ForeignDataSource` и читает данные своим кодом. Классы, которые работают только с одним видом, называются по нему: `Entity…` (`EntityScanNode`, `InsertEntityWriter`) и `ForeignData…` (`ForeignDataScanReader`); без приставки — те, кому вид источника безразличен (`HashNestJoinNode`, `SerializeReader`). Чужой источник может быть только моделью целиком: в `Join` допускаются одни сущности, `ModelTree::getFrom()` для него бросает `LogicException`, `getRootEntity()` возвращает null (`getRootDataSource()` — сам источник). Чтение чужих источников — в `src`. Источник реализует `Model\SelectForeignDataInterface` (`select(columns, conditions, order, arguments)` и статический `getReadOrientation()` — порядок, в котором он отдаёт строки). В плане ресурс над ним — отдельный лист `ForeignDataScanNode`:

- связь 1-1 с чужим источником не входит в общую строку SQL: шаг `separate` делает её `HashOneJoinNode`;
- условий родителя источник не получает, и запрос ребёнка под ним тоже: цепочка предков на чужом источнике обрывается. Лишних связанных пропускает соединение;
- слияние (`MergeNestJoinNode`) выбирается, только если ключ связи — первая колонка ориентации чтения источника и клиент не задал сортировку;
- объект источника `ReaderBuilder` берёт из контейнера зависимостей; аргументы маршрута (`getDocument(plan, arguments)`) передаются каждому чужому источнику как есть;
- связь 1-1 с чужим источником у ресурса внутри общей строки не поддержана — `LogicException` при планировании.

Запись в чужие источники тоже в `src`. Источник реализует `Model\InsertForeignDataInterface` (`insert(row, arguments)`), `UpdateForeignDataInterface` (`update(key, row, arguments)`), `DeleteForeignDataInterface` (`delete(key, arguments)`) — те, которые поддерживает. Место ресурса в дереве записи то же, вид источника решает `WritePlanner`:

| Ветвь места | Над сущностью | Над чужим источником | Исполнитель |
|---|---|---|---|
| `add` | `InsertResourceNode` → `InsertEntityNode` | `InsertResourceNode` → `InsertForeignDataNode` | `InsertForeignDataWriter`: `insert()` |
| `update` | `UpdateResourceNode` → `UpdateEntityNode` | `UpdateResourceNode` → `UpdateForeignDataNode` | `UpdateForeignDataWriter`: `update()` |
| `keep` | `KeepEntityNode` | `KeepForeignDataNode` | `KeepForeignDataWriter`: `update()` с пустой строкой |
| `remove` | `DeleteEntityNode` | `DeleteForeignDataNode` | `DeleteForeignDataWriter`: `delete()` |

- Ошибки описания — `LogicException` при планировании: источник не реализует интерфейс операции; полная замена детей связи, если дети в чужом источнике; вложенный маршрут с чужим источником на любой стороне.
- Аргументы маршрута уходят в источник как есть: `WriterBuilder::getRoot(plan, data, route_id, arguments)`. Родителя из маршрута для чужого источника движок не проверяет.
- Транзакции у чужого источника нет: откат базы его запись не вернёт. Потомков при удалении источник удаляет сам.

Примеры: `example/source-document.php` (источники в памяти), `example/user-profile.php` (источники, которые сами пишут в общую таблицу).

```php
final class CertificateDetailsEntity extends DatabaseEntitySource
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

- **Ключ `COLUMNS` — колонка ресурса.** На неё ссылаются `column` в `ATTRIBUTES` ресурса, `relation` в `getModel()` и в связях. Колонка таблицы в БД задаётся `column`, если отличается.
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

- **Одна таблица — один класс.** Связь таблиц задаёт `getModel()` ресурса, а не сущность. Сущность может описывать не все колонки таблицы.
- **Первичный ключ** — ровно одна колонка `PRIMARY`; составных нет. `FOREIGN` ссылается на первичный ключ сущности из `references`, `type` совпадает.
- **Сущности верят.** Сущность — источник истины о таблице; с БД её никто не сверяет. Если таблица разошлась с сущностью (миграция без правки сущности), ошибки не будет: неверный `type` даст неверное приведение значения, колонка, объявленная `PRIMARY`/`unique`, но не уникальная в БД, — размноженные строки модели.
- **Проверки в тестах, не в рантайме.** `DatabaseEntitySource::validate()` (общая часть — в `DataSource`) проверяет только согласованность констант (ключи и значения `COLUMNS`, один `PRIMARY`, `references` у `FOREIGN`, константы совпадают с ключами `COLUMNS`).

### Класс ресурса

PHP-класс, унаследованный от `Resource` (`example/Resource/`). Метаданные — публичные константы `TYPE` и `ATTRIBUTES`, связи — статический метод `getRelationships()`, модель данных — статический метод `getModel()`. Описание статическое: парсер, планировщик и план ссылаются на ресурс по `::class`. Экземпляр нужен только исполнителям — его создаёт контейнер зависимостей приложения вместе с зависимостями конструктора ресурса (см. [Сериализация](#сериализация)).

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

    public static function getRelationships(): array
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

    public static function getModel(): string
    {
        return CertificatesEntity::class;
    }

    // ручная сериализация ради формата nominal: вызывается на экземпляре ресурса (см. «Сериализация»)
    public function serialize(array $tuple): ResourceRecord { … }
}
```

Значения `INT`, `STRING`, `PRIMARY`, `READ` и т. д. — константы интерфейса `MetadataInterface`, который реализуют `Resource` и `DataSource` (а с ним `DatabaseEntitySource`).

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
| `column` | нет | Колонка ресурса — колонка одной из сущностей `getModel()`, обычно её константа. По умолчанию — имя атрибута. |
| `columns` | нет | Колонки составного атрибута, вместо `column`; чтобы его читать, ресурс реализует `SerializeInterface`; чтобы писать — `DeserializeInterface`, иначе атрибуту нужен `mode` READ. |
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

#### Связи (`getRelationships()`)

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
- **`ManyToOne`** — не больше одной строки `resource`, но у неё может быть много владельцев; ключ у владельца: внешний ключ на первичный ключ `resource`. Читается отдельным SQL-запросом (`PreloadReader`).
- **`OneToMany`** — любое число строк; читается отдельным SQL-запросом.
- **Связь по внешнему ключу** (`Resource::getForeignRelationship()`): `ManyToOne` или `OneToOne`, у которых своя колонка — не первичный ключ, а колонка `resource` — его первичный ключ (`certificate.provider`). Пишется из строки самого ресурса, в `fields` перечисляется как атрибут.
- **Только целые ключи.** Ключи связей сравнивает PHP (хеш-таблицы, слияние), а для `int` равенство и порядок в PHP те же, что в PostgreSQL.

Связи описывает ресурс: связь — часть типа ресурса, одинаковая в любом endpoint'е, и любую объявленную связь клиент может подключить через `include`.

#### Модель данных (`getModel()`)

`getModel()` возвращает сущность или бинарное дерево соединений `Model\LeftJoin`/`InnerJoin(left, right, relation)`: из каких таблиц читается ресурс. Крайняя левая таблица — основная; соединения — «к одному». Правая колонка `relation` колонкой модели не считается, поэтому две присоединённые таблицы могут обе иметь `certificate_id`.

Все ресурсы примера читаются из одной таблицы. Ресурс из нескольких таблиц выглядел бы так (детали и доставка — атрибуты сертификата, а не отдельные ресурсы):

```php
public static function getModel(): Join
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

`ModelTree::of($class)->getFrom('cc', $needed)` строит из неё элемент `FROM` узлами pg_builder — `RelationReference` (одна таблица) или `JoinExpression` — только с нужными таблицами: `LEFT JOIN` без нужных колонок отбрасывается, `INNER JOIN` остаётся всегда. `getExpression($column, 'cc')` даёт `ColumnReference` колонки модели. Каждый вызов строит новые узлы, их можно сразу вставлять в запрос. В SQL это выглядит так:

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

`ModelTree::of($class)` кеширует разбор на класс и бросает `LogicException` при ошибках; `ModelTree::build($class)->getErrors()` — без кеша и исключения (для `Resource::validate()`).

#### Сериализация

Ресурс из строки запроса плана собирает `SerializeReader` по описанию ресурса. Сборщик дерева передаёт ему запись `EntityScanNode::columns` этого ресурса: алиас, карту «колонка модели → колонка строки» (`t1_buyer_email`), `fields` и ключи соединений. Всё, что не зависит от строки, читатель готовит один раз в конструкторе; на каждой строке:

- первичный ключ `NULL` — ресурса в строке нет (`LEFT JOIN`), `null`; иначе он — `id`;
- атрибуты `fields` по порядку: единственная колонка атрибута, приведённая по `type` (`date`/`datetime` — как есть, `NULL` — `null`). Своего формата у отдельного атрибута нет, составной атрибут так не собрать (`LogicException`) — для этого ресурс объявляет `serialize()` (ниже);
- связь по внешнему ключу из `fields` — `{id, type}` цели из своей строки или `data: null`;
- ключи соединений — в служебные `keys` (`NULL` не пишется).

**Колонки сверх `fields`.** Запрос плана выбирает первичный ключ, колонки атрибутов `fields` и ключи соединений; остальное он не выбирает, а ненужный `LEFT JOIN` модели отбрасывает. Ресурс, чьей сериализации нужна колонка, которой нет у запрошенных атрибутов (внутренняя колонка, по которой выбирается вид ресурса), называет её сам — переопределяет `Resource::getRequiredColumns(array $fields): list<string>` (по умолчанию пусто). Метод получает `fields` узла плана и возвращает колонки модели; `EntityScanNodeDraft::withSelect()` добавляет их в список колонок и в карту `EntityScanNode::columns` ресурса. Атрибутом колонка не становится: сам `SerializeReader` её не выводит, в `fields`, фильтрах и сортировке её нет; читает её свой `serialize()` ресурса (ниже). Колонка не из модели — `LogicException` при построении запроса. Пример — `ValidationResource` в `example/polymorphic-document.php`.

**Ресурс — экземпляр из контейнера.** В плане ресурс — имя класса, а исполнителям, которые работают с данными, нужен объект: `ReaderBuilder` берёт его для каждого `SerializeReader` (и `ManualSerializeReader`), `WriterBuilder` — для `InsertResourceWriter` и `UpdateResourceWriter`, через `container->get(класс ресурса)`. Создаёт объект контейнер приложения вместе с зависимостями конструктора, поэтому `serialize()` и `deserialize()` ресурса — обычные (не статические) методы и могут пользоваться сервисами. Отдельных методов на атрибут (`serialize<Атрибут>()`, `deserialize<Атрибут>()`) нет.

```php
final class ValidationResource extends Resource
{
    public function __construct(
        private readonly UrlGenerator $url_generator,   // зависимость: её подставит контейнер приложения
    ) {}

    public function serialize(array $tuple): ResourceRecord { … }   // $this->url_generator доступен
}
```

- **Из контейнера берутся все ресурсы,** и без зависимостей тоже: ресурса в контейнере нет — исключение контейнера при сборке дерева, до выполнения. В примерах и тестах — `new ArrayContainer(services: ExampleResources::getAll())`.
- **Описание остаётся статическим:** `TYPE`, `ATTRIBUTES`, `getRelationships()`, `getModel()`, `getRequiredColumns()` читаются без объекта — парсеру и планировщику контейнер не нужен, план остаётся описанием без состояния.
- **Один объект на все строки и выполнения:** состояние между строками ресурс хранить не должен.
- **Сервис в `serialize()` — не повод ходить в базу или сеть на каждой строке:** это запросы в обход плана (N+1), библиотека их не видит и не запретит.
- **Ручные деревья** создают ресурс сами: `new SerializeReader(source: …, resource: new DomainResource(), columns: QUERY2_DOMAIN)`.

**Ручная сериализация.** Свою обработку данных ресурс объявляет интерфейсом: реализует интерфейс — значит, поддерживает операцию. Для чтения это `Resource\SerializeInterface` с методом `serialize(array $tuple, array $fields): ?ResourceRecord`. По интерфейсу `ReaderBuilder` ставит вместо `SerializeReader` его наследника `ManualSerializeReader`: тот не собирает ресурс по `ATTRIBUTES`, а вызывает метод на каждой строке. Так собирается ресурс, у которого тип или набор атрибутов зависит от строки (полиморфный ресурс — `ValidationResource` в `example/polymorphic-document.php`), и любой свой формат значения: отдельного метода на атрибут (`serialize<Атрибут>()`) больше нет, метод с таким именем никто не вызовет. `CertificateResource` объявляет `serialize()` ради формата `nominal` (`1000.00`).

```php
// ValidationResource: тип и атрибуты выбирает внутренняя колонка method
public static function getRequiredColumns(array $fields): array
{
    return [CertificateValidationsEntity::METHOD];   // не атрибут: без этого запрос плана её не выберет
}

public function serialize(array $tuple, array $fields): ResourceRecord
{
    $method = self::METHODS[$tuple['method']];
    $own = [self::STATUS, ...$method['attributes']];     // атрибуты этого метода проверки
    $attributes = [];
    foreach ($fields as $attribute) {                    // только запрошенное, в порядке запроса
        if (\in_array($attribute, $own, true)) {
            $attributes[$attribute] = $tuple[$attribute];
        }
    }

    return new ResourceRecord(id: (int) $tuple['id'], type: $method['type'], attributes: $attributes, relationships: []);
}
```

- **Строка** `$tuple` — «колонка модели → значение» для всех колонок ресурса в запросе: первичный ключ, колонки атрибутов `fields`, колонки `getRequiredColumns()` и ключи соединений. Алиасов запроса (`t0_…`) и колонок других ресурсов той же строки метод не видит; значения — как пришли из базы, без приведения по `type`.
- **`$fields`** — запрошенные атрибуты и связи по внешнему ключу в порядке запроса, один список на все строки. Выводить надо по нему, а не по наличию колонки в `$tuple`: колонка бывает в строке и как ключ соединения или колонка из `getRequiredColumns()`. Метод, который `$fields` игнорирует, отдаст лишние атрибуты или упадёт на невыбранной колонке («Undefined array key»).
- **На методе целиком:** `id`, `type`, атрибуты и связи по внешнему ключу.
- **На узле остаётся:** первичный ключ `NULL` — метод не вызывается, `null`; ключи соединений дописываются в `keys` готового `ResourceRecord` (`setKey()`).
- **Реализовывать интерфейс без нужды нельзя:** с ним в деревьях по плану общая сборка ресурса выключается всегда.
- **Метод без интерфейса никто не вызовет:** `serialize()` у ресурса, который не реализует `SerializeInterface`, — ошибка `Resource::validate()`.
- **Дерево, собранное руками, выбирает узел само:** ошибиться молча нельзя — `SerializeReader` для ресурса с интерфейсом и `ManualSerializeReader` для ресурса без него бросают `LogicException` в конструкторе.

**Ручная десериализация.** Зеркало для записи — `Resource\DeserializeInterface` с методом `deserialize(array $attributes, array $references, bool $is_insert, ?int $id, array $link): array`: ресурс сам строит строку «колонка модели → значение» из данных операции. Общая раскладка (`ResourceDeserializer`: атрибут → его колонка, связь по внешнему ключу → id цели) для такого ресурса не выполняется; по интерфейсу `WriterBuilder` даёт писателям ресурса (`InsertResourceWriter`, `UpdateResourceWriter`) его наследника `ManualResourceDeserializer`. Так записывается составной атрибут.

```php
// ресурс с составным атрибутом price = {amount, currency} из колонок nominal и currency
final class PricedCertificateResource extends Resource implements UpdatableInterface, SerializeInterface, DeserializeInterface
{
    public const array ATTRIBUTES = [
        …
        self::PRICE => ['columns' => [CertificatesEntity::NOMINAL, CertificatesEntity::CURRENCY]],
    ];

    public function deserialize(array $attributes, array $references, bool $is_insert, ?int $id, array $link): array
    {
        $row = [];
        if (\array_key_exists(self::PRICE, $attributes)) {      // в строку — только присланное
            $row[CertificatesEntity::NOMINAL] = $attributes[self::PRICE]['amount'];
            $row[CertificatesEntity::CURRENCY] = $attributes[self::PRICE]['currency'];
        }

        return $row;
    }
}
```

- **Вход** — то, что уже проверил парсер: присланные атрибуты (имя в API → значение) и связи по внешнему ключу (имя → `PersistentIdentity` или `null`).
- **Контекст операции** — отдельными аргументами: `$is_insert` (ресурс создаётся или изменяется), `$id` (id изменяемого ресурса, `null` при вставке), `$link` (колонки связи с родителем → значение). По ним метод может, например, подставить значение по умолчанию только при создании. Объекта контекста нет намеренно: сначала посмотрим, насколько удобны отдельные аргументы. Всё, что относится к запросу или приложению (пользователь, локаль), ресурс получает через конструктор.
- **На методе целиком:** все присланные атрибуты и связи по внешнему ключу, в том числе обычные. В строку кладётся только присланное: присутствие колонки, а не значение, решает, что её трогать.
- **На писателях остаётся:** первичный ключ и колонки связи с родителем.
- **Проверяется:** каждая колонка строки — колонка модели ресурса, иначе `LogicException`.
- **Не проверяется:** метод, который не положил в строку присланный атрибут, молча его теряет.
- **Плохое значение от клиента** метод отклоняет исключением `Resource\InvalidAttributeException(attribute, detail)`: форму значения парсер не проверяет. Развилка места (`WriteOperationWriter`) превращает его в `WriteInvalidAttributeException` — 400 с `meta: {type, id | lid, attribute}`. Любое другое исключение метода дойдёт до клиента как 500.
- **Общий класс такой ресурс не принимает:** `ResourceDeserializer` для ресурса с интерфейсом — `LogicException` в конструкторе, как и `ManualResourceDeserializer` для ресурса без него.
- **Разрешений интерфейс не даёт:** можно ли ресурс создавать и менять, по-прежнему говорят `InsertableInterface` и `UpdatableInterface`.

Ручные деревья (`example/document.php`, `DocumentTreeTest`) ставят те же узлы (`ManualSerializeReader` — для сертификата): их SQL написан руками (`QUERY1`–`QUERY4` в `example/db/connection.php`), а где в строке лежат колонки ресурса, говорят записи колонок там же (`QUERY1_CERTIFICATE`, `QUERY2_DOMAIN`, …) — то, что по плану даёт `EntityScanNode::columns`.

#### Проверки

Константы не проверяются в рантайме. Их проверяет `Resource::validate()` в тестах:

- `TYPE` не пустой; у атрибутов только известные ключи и допустимые значения; `column` и `columns` не вместе; `type` есть, если нет `columns`;
- нет атрибута `type`, `id` — только `PRIMARY`; строковые константы совпадают с атрибутами и связями;
- ровно один несоставной `PRIMARY`, нет `FOREIGN` у атрибута;
- связи: имена уникальны и не совпадают с атрибутами, `resource` — наследник `Resource`, обе колонки `relation` есть в моделях и имеют тип `INT`, у `OneToOne` колонка цели уникальна;
- `filter`/`sort` — правильной формы и не у составных атрибутов;
- ресурс с составным атрибутом реализует `SerializeInterface`; составной атрибут без `mode` READ требует ещё и `DeserializeInterface`; метод `serialize()` или `deserialize()` без своего интерфейса — ошибка;
- модель: правила `ModelTree`, все колонки `ATTRIBUTES` есть в модели, первичный ключ уникален в основной таблице.

## Открытые вопросы

- **Схема endpoint'а.** Убрана, пока всё не готово: сейчас доступно всё, что объявлено в ресурсах. Вернуть как ограничения поверх ресурсов (какие связи, поля, фильтры открыты в endpoint'е, обязательные связи).
- **Цепочка предков: `LEFT JOIN` в теле CTE.** Цепочка реализована (см. [Цепочка предков](#цепочка-предков)); тело CTE переносит FROM строки как есть. Не решено, отбрасывать ли `LEFT JOIN` 1-1 ресурсов без фильтров.
- **Фильтры.** Оператор `in` в SQL плана (через PDO массив одним параметром не передать); `ne` и `NULL` (`<>` не находит строки с `NULL`, `IS DISTINCT FROM` хуже для индекса).
- **Параметры маршрута** (`/certificates/1`): парсер их не разбирает, планировщик пока не принимает.
- **Merge по неквалифицированным колонкам.** `HashToMergeRule` хранит порядок владельца колонкой без ресурса: одинаковый ключ разных таблиц не отличить.
- **Обратная сторона связи.** `certificate.domains` и `domain.certificate` — стороны одного внешнего ключа; выводить одну из другой или из `FOREIGN` сущностей?
- **Тип и `nullable` атрибута из сущности.** Атрибут повторяет `type` и `nullable` колонки; выводить или проверять совпадение в `validate()`?
- **Запись: порядок соседних связей.** Сейчас — порядок объявления в `getRelationships()`. Ссылка `certificate_delivery_meta` → `certificate_details` в сущностях не описана (колонка-первичный ключ не несёт `references`), поэтому не проверяется: разрешить `references` у `PRIMARY` и сортировать соседей топологически? Та же ссылка делает `delivery` без `detail` невставляемым, а отказывает только база.
- **Запись: ответ и точка входа.** Формат ответа решён (`201` + `Location` + `{data: {type, id}}` у `POST`, `204` у `PATCH` и `DELETE`, без перечитывания), но контроллера и транзакции нет.
- **Запись: подготовленные запросы.** `InsertEntityWriter` и `StatementRunner` (изменение, удаление, проверки) готовят запрос на каждую строку: набор колонок зависит от строки, а `DEFAULT` нельзя передать параметром. Кеш по набору колонок; многострочный `INSERT` с `DEFAULT` в `VALUES` на PostgreSQL; один запрос на всех детей связи вместо запроса на ребёнка.
- **Запись: многие-ко-многим.** Не поддерживается: связь через таблицу связки убрана до необходимости.
- **Запись: ресурс с двумя родителями.** Ссылка по `lid` на ресурс из другой ветки дерева запрещена (400).
- **Запись: параметры маршрута.** `POST /certificates/1/domains` работает через `EntityRouteParentNode` плана (родитель проверяется, нет — 404); разбор маршрута не сделан. Цели связей по внешнему ключу не проверяются: несуществующая — ошибка внешнего ключа в базе.
- **Запись: ошибки базы, типы значений, обязательные атрибуты, конкурентный доступ.** Нарушение `NOT NULL`, уникальности, внешнего ключа — исключение PDO как есть; типы значений атрибутов и обязательные атрибуты при вставке парсер не проверяет; версий строк (ETag) нет.
- **Запись: `PUT` и `PATCH`.** Сейчас только `PATCH`, где список to-many без `meta.op` — полная замена; строго частичный `PATCH` и `PUT` как полная перезапись обсуждались и отложены.
- **Обратные связи без `include`.** Связь, у которой ключ на другой стороне (`certificate.domains`), без `include` не известна: не отдавать её или отдавать без `data`?

## Использование

Точки входа в самой библиотеке пока нет: стадии соединяет вызывающий код, как контроллер примера (`example/Controller/CertificatesController`):

```php
use Ggs\JsonApiEngine\Executor\Select\ReaderBuilder;
use Ggs\JsonApiEngine\Parser\InvalidQueryException;
use Ggs\JsonApiEngine\Parser\QueryParser;
use Ggs\JsonApiEngine\Parser\ResourceNotFoundException;
use Ggs\JsonApiEngine\Plan\Select\SelectPlanner;

try {
    $tree = (new QueryParser())->parse(resource: CertificateResource::class, query: $_GET);   // 1
} catch (InvalidQueryException $e) {
    // 400
} catch (ResourceNotFoundException $e) {
    // 404
}

$plan = (new SelectPlanner())->plan(tree: $tree)['sql'];                  // 2–3: план с SQL; можно кешировать
// 4: новое дерево читателей и реестр; $container — PSR-11 контейнер приложения
$document = (new ReaderBuilder(pdo: $pdo, container: $container))->getDocument(plan: $plan);

echo json_encode($document);                                            // 5–6: запросы выполняются здесь
```

Отладка: `SelectPlanPrinter::getTree($plan)` — дерево плана, `SelectPlanPrinter::getQueries($plan)` — SQL и параметры каждого запроса, `ReaderBuilder::build()->explain()` — дерево читателей.

Запись соединяется так же (подробно — [Запись](#запись-insert-update-delete)):

```php
use Ggs\JsonApiEngine\Executor\Write\WriteNotFoundException;
use Ggs\JsonApiEngine\Executor\Write\WriterBuilder;
use Ggs\JsonApiEngine\Parser\InvalidDocumentException;
use Ggs\JsonApiEngine\Parser\Write\WriteDocumentParser;
use Ggs\JsonApiEngine\Plan\Write\WritePlanner;

try {
    $root = new WriteDocumentParser()->update(document: $body, resource: CertificateResource::class, id: $id);   // 1
} catch (InvalidDocumentException $e) {
    // $e->status: 400 / 403 / 409, тело — ['errors' => [$e->toError()]]
}

$plan = new WritePlanner()->plan(resource: CertificateResource::class, roots: [$root]);                          // 2
$writer = new WriterBuilder(pdo: $pdo, lock: true, container: $container)->getRoot(plan: $plan, data: [$root]);     // 3

$pdo->beginTransaction();
try {
    $writer->execute();                                                                                          // 4
    $pdo->commit();                                    // 204 No Content
} catch (WriteNotFoundException | WriteInvalidAttributeException $e) {
    $pdo->rollBack();                                  // 404 или 400, тело — ['errors' => [$e->toError()]]
}
```

Отладка записи: `$writer->explain()` — дерево исполнителей; каждый выполненный запрос с параметрами и результатом пишется в `error_log()`. `php example/patch-document.php` печатает дерево операций, план и дерево исполнителей текстом.

## Разработка

```bash
composer install
vendor/bin/phpunit --testsuite unit                # модульные тесты, в том числе test/Model
vendor/bin/psalm                                   # статический анализ
php example/document.php                           # документ по дереву читателей, собранному руками
php example/certificates.php [URL] [--document]    # CertificatesController: план и SQL для запроса, с --document — документ (по умолчанию include=provider,detail,delivery,domains.logs)
php example/query-plan.php [--steps|--sql|--explain]  # дерево запроса из кода (без парсера) → план → документ; --steps — план после каждого шага
php example/query-tree.php                         # дерево запроса для include=provider,detail,delivery,domains.logs
php example/query-tree.php '/certificates?include=domains.logs&fields[certificate]=code'
php example/patch-document.php                     # запись POST/PATCH/DELETE на девяти сценариях: документ → дерево операций → план → исполнители; SQL в stderr; транзакции откатываются
php example/insert-plan.php                        # вставка через планировщик: документ → дерево операций → план → дерево исполнителей, ключи
php example/insert-document.php                    # то же дерево исполнителей, собранное руками
php example/insert-route.php                       # вложенный маршрут POST /certificates/1/domains: EntityRouteParentNode → EntityRouteParentWriter
php example/update-document.php                    # PATCH /certificates/{id}: дерево исполнителей изменения, собранное руками; пять сценариев
php example/db/seed.php [количество сертификатов]  # пересоздать базу примера
```

| Где | Что |
|---|---|
| `example/db/` | SQLite база примера: схема, генератор данных, запросы `QUERY1`–`QUERY4`, `getPDO()`. |
| `example/Entity/`, `example/Resource/` | Сущности и ресурсы над базой примера. |
| `example/Controller/CertificatesController.php` | Точка входа `GET /certificates`: `get($query)` — парсер по `CertificateResource`, планировщик и `ReaderBuilder::getDocument()`, возвращает `Document`; `plan($query)` — только план. |
| `test/PlanTreeTest.php` | Запрос → план → `ReaderBuilder` → документ: совпадает с эталоном (с порядком), топология как у ручного дерева, повторное выполнение плана, фильтр, ветка 1:N внутри строки (`HashJoinReader`), ошибки сборки. |
| `test/Executor/Select/HashJoinReaderTest.php` | `HashJoinReader`: элемент на элемент `outer`, `null` проходит, связанные по ключу, пустая связь. |
| `test/Plan/Select/SelectWhereTest.php` | `WHERE` плана: фильтры владельца и 1-1 ресурса (`IS NULL OR`), именованные параметры, выполнение на SQLite примера; колонки фильтра и сортировки держат таблицы модели в FROM. |
| `test/Executor/Select/SerializeReaderTest.php` | Сериализация по плану: ресурсы из запросов плана примера на SQLite совпадают с эталоном по атрибутам и связи `provider`; составной атрибут, приведение типов, связь по внешнему ключу, ключи, `null` у пустого `LEFT JOIN`. |
| `test/Executor/Select/RegisterReaderTest.php` | `RegisterReader`: повторный `type:id` даёт зарегистрированный объект, `null` проходит на своём месте, ресурсы попадают в реестр. |
| `test/Executor/Select/AscendingKeyReaderTest.php` | `AscendingKeyReader`: упорядоченный поток проходит без изменений (с `null`), строгий порядок отвергает повтор ключа, нестрогий — убывание; неупорядоченный `outer` слияния — исключение. |
| `test/Parser/Write/WriteDocumentParserTest.php` | Парсер записи: дерево операций для `POST`, `PATCH` и `DELETE` (классы операций, порядок детей, признак замены), ошибки клиента со статусом и указателем. |
| `test/Plan/Write/WritePlannerTest.php` | Планировщик записи: ветви места только для встреченных операций, связи в порядке объявления, узел замены под узлом связи и без него, объединение по корням, родитель вложенного маршрута. |
| `test/Plan/Insert/InsertPlannerTest.php`, `test/Plan/Update/UpdatePlannerTest.php` | Поддеревья таблиц вставки и изменения по модели: одна таблица, модель из двух таблиц, ресурс без `InsertableInterface`. |
| `test/Executor/Write/WriteExecutionTest.php` | Запись целиком на SQLite примера (транзакция с откатом): `PATCH` обеих таблиц модели и связей, неизменённые значения, отсутствующая строка необязательной таблицы, полная замена и замена пустым, чужой ребёнок — 404, `POST` дерева, вложенный маршрут, `DELETE` с каскадом базы. |
| `test/Executor/Write/ResourceDeserializerTest.php` | Десериализация: строка из данных операции по описанию ресурса и методом `deserialize()` ресурса (`DeserializeInterface`), выбор между ними в `WriterBuilder`, запись составного атрибута через весь конвейер, правила `validate()` для составного атрибута и метода без интерфейса. |
| `test/Executor/Insert/InsertWriterTest.php` | Исполнители вставки на SQLite примера: дерево руками совпадает с деревом по плану, ключ владельца у связанных ресурсов, модель из двух таблиц и пропуск пустой LEFT-стороны, ресурс без `InsertableInterface`, чужая операция. |
| `test/Executor/Insert/OptionalParentTest.php` | Необязательная сторона модели — родитель (`LeftJoin(vouchers, vendors, [vendor_id, id])`, своя схема в SQLite в памяти): план оборачивает `vendors` в `InsertOptionalEntityNode` и ставит её родителем; с атрибутами продавца он вставляется первым и его ключ уходит в `vendor_id`, без них пропускается и `vendor_id` остаётся NULL. |
| `test/DocumentTreeTest.php` | Дерево из `document.php` с константами ресурсов: документ совпадает с эталоном `test/Fixture/document.json`, каждый ресурс встречается один раз, связь по внешнему ключу без и с `include`, пустая доставка, топология `explain()`. |
