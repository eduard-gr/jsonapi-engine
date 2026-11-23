# 1. `KeepOperation` внутри ветви изменения

> **Реализовано 2026-11-20.** Ниже — исходное предложение: «как сейчас» и номера строк описывают код до изменения.
>
> Что сделано иначе, чем в наброске: уровня таблиц у ветви `keep` нет. `KeepEntityWriter(pdo, resource)` сам
> выполняет `SELECT` главной таблицы — как `DeleteEntityWriter` сам выполняет `DELETE`; `FindTableWriter` и метод
> в `UpdatePlanner` не понадобились. Узел плана — `Plan\Write\KeepEntityNode(resource)`, без `input`.
>
> Что изменилось в поведении: колонка связи от родителя должна лежать в главной таблице ресурса, иначе
> `LogicException` (раньше keep шёл через все таблицы и находил её в любой); остальные таблицы ресурса при keep
> не читаются.

## Проблема

В дереве операций записи два разных действия над существующим ресурсом:

- `UpdateOperation` — изменить атрибуты;
- `KeepOperation` — ничего не менять, только убедиться, что ресурс есть и принадлежит родителю, и взять его ключи
  (под ним могут быть дети с собственными операциями).

В плане они неразличимы: обе идут в одну ветвь `update` узла `WriteOperationNode`, и её исполнители сами разбираются,
что им прислали. Разбираются в двух местах, на двух уровнях:

```php
// UpdateResourceWriter::push(): уровень ресурса
$row = [];
if ($operation instanceof UpdateOperation) {          // у KeepOperation строка остаётся пустой
    $row = ResourceDeserializer::getRow(
        resource: $this->resource,
        attributes: $operation->attributes,
        references: $operation->references,
    );
}

// UpdateEntityWriter::push(): уровень таблицы
$found = [];
if ($values !== []) {                                  // пустая строка — признак «только найти»
    // … UPDATE … WHERE … IS DISTINCT FROM … RETURNING …
}
if ($found === []) {
    // … SELECT …
}
```

Что здесь плохо:

- **Намерение передаётся пустым массивом.** «Только найти» нигде не названо: это побочное значение пустой строки,
  которое верхний исполнитель создаёт, а нижний угадывает.
- **`explain()` врёт.** Место, где ресурсы только ищутся, в дереве исполнителей показано как `UpdateResourceWriter` над
  `UpdateEntityWriter`, хотя ни одного `UPDATE` там не будет.
- **Для поиска строится лишнее.** Ветвь изменения — это всё поддерево таблиц ресурса, включая
  `UpdateOptionalEntityWriter` с запасным `InsertEntityWriter`; чтобы найти строку, оно не нужно.
- **Два смысла у одного `SELECT`.** `SELECT` в `UpdateEntityWriter` служит и «найти» (для keep), и «отличить „значения
  те же“ от „строки нет“» (для update). Первое известно заранее, второе зависит от данных.

Планировщик при этом уже знает ответ: `WritePlanner::getPlace()` перебирает операции места и строит ветви только под те,
что встретились. Он просто складывает `UpdateOperation` и `KeepOperation` в одну кучу:

```php
$updates = array_any(
    $operations,
    static fn(WriteOperation $operation): bool => $operation instanceof UpdateOperation
        || $operation instanceof KeepOperation,
);
```

## Где менять

| Место | Что сейчас | Что сделать |
|---|---|---|
| `src/Plan/Write/WritePlanner.php:108` | `$updates` = есть `UpdateOperation` **или** `KeepOperation` | разделить на `$updates` и `$keeps` |
| `src/Plan/Write/WritePlanner.php:125` | ветвь `update: new UpdateResourceNode(…)` | рядом добавить ветвь `keep: new KeepEntityNode(…)` |
| `src/Plan/Write/WriteOperationNode.php:31` | три ветви: `add`, `update`, `remove` | четвёртое свойство `?KeepEntityNode $keep`; docblock («его же исполняет `KeepOperation`») |
| `src/Plan/Update/UpdatePlanner.php:39` | `getEntities(resource)` — всё дерево таблиц | новый метод плана поиска: только главная таблица |
| `src/Executor/Write/WriteOperationWriter.php:46` | `UpdateOperation \|\| KeepOperation` → ветвь `update` | отдельная ветка `KeepOperation` → `keep`; параметр конструктора (строки 33–35) |
| `src/Executor/Write/WriterBuilder.php:120` | собирает три ветви места | четвёртая: `keep: new KeepEntityWriter(…)` |
| `src/Executor/Update/UpdateResourceWriter.php:44` | принимает `UpdateOperation` и `KeepOperation` | только `UpdateOperation` |
| `src/Executor/Update/UpdateResourceWriter.php:61` | `if ($operation instanceof UpdateOperation)` вокруг сборки строки | `if` убрать: строка строится всегда |
| `src/Executor/Update/UpdateEntityWriter.php:74` | `if ($values !== [])` — «пустая строка = только найти» | остаётся только для случая «своих колонок этой таблицы не прислали» |
| `src/Executor/Update/UpdateEntityWriter.php:105` | `SELECT`, если `UPDATE` ничего не вернул | остаётся (зависит от данных) |
| новый `src/Plan/Write/KeepEntityNode.php` | — | узел плана ветви `keep` |
| новые `src/Executor/Write/KeepEntityWriter.php`, `FindTableWriter.php` | — | исполнители ветви: уровень ресурса и уровень таблицы |
| `test/Plan/Write/WritePlannerTest.php`, `test/Executor/Write/WriteExecutionTest.php` | ожидают ветвь `update` для keep | ожидания `explain()` и плана |

## Как предлагается

Четвёртая ветвь места — `keep` — со своим узлом плана и своим исполнителем.

```php
// Plan\Write\WritePlanner::getPlace()
$updates = array_any(
    $operations,
    static fn(WriteOperation $operation): bool => $operation instanceof UpdateOperation,
);
$keeps = array_any(
    $operations,
    static fn(WriteOperation $operation): bool => $operation instanceof KeepOperation,
);

$node = new WriteOperationNode(
    resource: $resource,
    add: …,
    update: $updates
        ? new UpdateResourceNode(
            resource: $resource,
            input: new UpdatePlanner()->getEntities(resource: $resource),
        )
        : null,
    keep: $keeps
        ? new KeepEntityNode(
            resource: $resource,
            input: new UpdatePlanner()->getMainTable(resource: $resource),   // только главная таблица
        )
        : null,
    remove: …,
);
```

```php
// Executor\Write\WriteOperationWriter::push(): развилка остаётся по классу операции, но веток четыре
if ($operation instanceof KeepOperation) {
    $branch = $this->keep ?? throw new LogicException("{$this->resource}: no keep branch in the plan");
}
```

```php
/**
 * Только ищет ресурс: строка главной таблицы по первичному ключу и колонкам связи от родителя.
 * Поток: KeepOperation + $link → ключи; пусто — ресурса нет или он чужой (наверху это 404).
 */
final readonly class KeepEntityWriter implements PushExecutorInterface
{
    public function __construct(
        private Resource $resource,
        private EntityWriterInterface $input,
    ) {}

    #[\Override]
    public function push(WriteOperation $operation, array $link = []): array
    {
        if (!$operation instanceof KeepOperation) {
            throw new LogicException($this->resource::class . ': keep needs KeepOperation');
        }

        return $this->input->push([], [...$link, $this->resource::getPrimaryKeyColumnName() => $operation->identity->id]);
    }
}

/**
 * Уровень таблицы: один SELECT ключей по ключу и условиям принадлежности. UPDATE не выполняет никогда.
 */
final readonly class FindTableWriter implements EntityWriterInterface { … }
```

Что получается:

- `UpdateResourceWriter` принимает только `UpdateOperation` и всегда строит строку — `if` уходит.
- В `explain()` место, где ресурсы только ищутся, так и называется: `KeepEntityWriter` над `FindTableWriter`.
- Для keep не строится поддерево всех таблиц ресурса.

Что остаётся как есть: `SELECT` после `UPDATE` в `UpdateEntityWriter`. Он отличает «прислали те же значения» от «строки
нет», а это видно только по результату запроса.

Почему для keep хватает одной главной таблицы. Ключи, которые возвращает место, нужны только детям: `WriteJoinWriter`
берёт из них `relation[0]` связи и передаёт ребёнку как колонку связи. Детей в документе могут иметь только связи
по владению, а у них `relation[0]` — всегда первичный ключ ресурса (`WriteDocumentParser`, проверка `owner_side`;
`WritePlanner::getRoute()` требует того же). Первичный ключ лежит в главной таблице, поэтому остальные таблицы модели
для keep читать незачем: `certificate_details` у `CertificateWithDetailResource` при keep не трогается.

Сейчас `KeepOperation` идёт через всё дерево таблиц и возвращает ключи каждой из них — это побочный результат общей
ветви с изменением, потребителя у этих ключей нет.
