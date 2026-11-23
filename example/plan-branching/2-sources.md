# 2. Чужие источники: узлы плана вместо проверок в сборщиках

> **Шаг 1 (чтение) реализован 2026-11-23.** В `src`: `Model\SelectForeignDataInterface`,
> `Plan\Select\ForeignDataScanNode`, `HashOneJoinNode`, `SeparateForeignDataRule` (шаг `separate`), правки в
> `EntityScanNodeDraftPass`, `HashToMergeRule`, `BuildSqlPass`, `SelectPlanPrinter`; читатели `ForeignDataScanReader`
> и `HashOneJoinReader`, строки отображения в `ReaderBuilder`; тесты `test/Plan/Select/ForeignDataPlanTest.php`.
> Из прототипа удалены `ForeignDataPlanner`, `ForeignDataHashToMergeRule`, `ForeignDataReaderBuilder`.
> Отличия от наброска ниже — в разделе «Как сделано чтение». **Шаг 2 (запись) реализован в тот же день**: `Model\InsertForeignDataInterface`,
> `UpdateForeignDataInterface`, `DeleteForeignDataInterface`; узлы `InsertForeignDataNode`, `UpdateForeignDataNode`,
> `KeepForeignDataNode`, `DeleteForeignDataNode`; проверки в `WritePlanner`; исполнители `…ForeignDataWriter` и строки
> отображения в `WriterBuilder`; тесты `test/Plan/Write/ForeignDataWriteTest.php`. Прототипа больше нет: в
> `example/source-document.php` остались источники, ресурсы и сценарии. Ответ на вопрос 2: аргументы маршрута уходят
> в источник как есть, родитель вложенного маршрута для чужого источника не планируется.
> Фрагменты «как сейчас» и номера строк ниже описывают прототип до переноса и оставлены как история решения.

Этот файл — про то, в каком виде переносить прототип `example/source-document.php` в `src`.

## Как сделано чтение

- **Узел хранит не готовые списки, а вход и считает их методами.** `ForeignDataScanNode(input, order, keys)`:
  `getDataSource()`, `getColumns()`, `getConditions()`, `getSortOrder()`, `getSerializeColumns()`. В наброске это были
  свойства `source`, `columns`, `conditions`, `order`; данные те же, но не дублируют `ResourceNode`.
- **Черновика над чужим источником нет.** Шаг `close` сразу ставит `ForeignDataScanNode`; шаг `sql` дописывает ему
  ключи (`withKeys()`), шаг `merge` — порядок (`withOrder()`).
- **Ошибка описания ловится при планировании:** ресурс не над чужим источником или источник без
  `SelectForeignDataInterface` — `LogicException` в конструкторе узла.
- **Ответы на вопросы 1 и 3** (приняты как предлагалось): аргументы маршрута — параметр
  `ReaderBuilder::build(plan, registry, arguments)` и `getDocument(plan, arguments)`; ключи соединений — точные, а не
  «все целочисленные колонки».
- **Ограничение:** связь 1-1 с чужим источником у ресурса внутри общей строки — `LogicException` на шаге `close`.

## Что уже решено и в этот пункт больше не входит

- **Соединения.** `HashNestJoinReader` и `MergeNestJoinReader` пропускают ребёнка без родителя при любом источнике.
  Отдельных «свободных» классов и узлов (`Loose…`) нет: сборщик прототипа ставит обычные соединения и ничего не
  выбирает.
- **Имена.** Виды источника названы в модели: `DatabaseEntitySource` (сущность, SQL строит движок) и
  `ForeignDataSource` (чужой источник, читает и пишет своим кодом). Классы, которые работают только с одним видом,
  несут слово `Entity` или `ForeignData`.

Остаётся одно: **факт «ресурс читается из чужого источника» известен планировщику, но в план не записан**.

## Проблема

У ресурса над чужим источником нет SQL. Это следует из описания ресурса (`ModelTree::getRootDataSource()` —
наследник `ForeignDataSource`), то есть известно до выполнения. Но узла для этого в плане нет, и каждый слой
прототипа выясняет это заново через `ForeignDataPlanner::isForeignData()`.

```php
// Планировщик, шаг sql (example/source-document.php:932): чужой источник — это узел запроса,
// которому «забыли» построить SQL
if ($node instanceof EntityScanNodeDraft) {
    if (self::isForeignData(resource: $node->getRootResourceNode()->resource)) {
        return $node;                                                  // остаётся EntityScanNodeDraft
    }

    return $node->withSelect(keys: $keys, ancestors: $ancestors);      // становится EntityScanNode
}

// Сборщик чтения (:1111): узнаёт чужой источник по тому же остатку
if ($node instanceof EntityScanNode) {
    return new ReaderBuilder(pdo: $this->pdo, container: $this->container)->build(plan: $node, registry: $registry);
}
if ($node instanceof EntityScanNodeDraft) {
    return $this->getForeignDataScanReader(node: $node, registry: $registry);   // «значит, чужой источник»
}

// Сборщик записи (:1443): тот же вопрос в третий раз
if (!ForeignDataPlanner::isForeignData(resource: $plan->resource)) {
    return new WriterBuilder(pdo: $this->pdo, lock: $this->lock, container: $this->container)->build(plan: $plan);
}
```

Что здесь плохо:

- **Один узел значит два разных состояния.** В `src` оставшийся в плане `EntityScanNodeDraft` — это ошибка «шаг `sql`
  не выполнялся» (`ReaderBuilder` бросает `LogicException`). В прототипе тот же остаток — «ресурс из чужого
  источника». По плану одно от другого не отличить. К тому же узел с именем `Entity…` стоит над ресурсом, у которого
  сущности нет.
- **План не показывает, что будет выполнено.** `SelectPlanPrinter` печатает черновик запроса к сущностям, а
  выполняться будет `ForeignDataScanReader`.
- **Часть работы планировщика делает сборщик.** `getForeignDataScanReader()` (`:1171`) при сборке дерева переводит
  фильтры и сортировку клиента из атрибутов в колонки и считает список колонок. Для сущности это делает
  `EntityScanNodeDraft::withSelect()` в плане.
- **Ошибка описания всплывает поздно.** Ресурс объявлен `InsertableInterface`, а его источник не реализует
  `InsertForeignDataInterface`, — это выясняется при сборке дерева записи (`getForeignDataSource()`), а не при
  планировании.
- **Нужны два параллельных сборщика.** `ForeignDataReaderBuilder` и `ForeignDataWriterBuilder` оборачивают
  `ReaderBuilder` и `WriterBuilder` и перехватывают «свои» узлы.

## Где принимается решение сейчас

| Место | Что решает | Где должно быть |
|---|---|---|
| `ForeignDataPlanner::separate()`, `:908` | связь 1-1 с чужим источником → `HashOneJoinNode` вместо общей строки | в планировщике — уже так |
| `ForeignDataHashToMergeRule::claim()`, `:768` | можно ли слияние: чужой источник отдаёт только свой порядок чтения | в планировщике — уже так |
| `ForeignDataPlanner::sql()`, `:932` | чужому источнику SQL не строится | в планировщике, но результатом должен быть свой узел |
| `ForeignDataPlanner::sql()`, `:952` | цепочка предков не проходит через чужой источник | в планировщике — уже так |
| `ForeignDataReaderBuilder::build()`, `:1111` | «остался черновик — значит чужой источник» | строка отображения узла |
| `ForeignDataReaderBuilder::getForeignDataScanReader()`, `:1171` | колонки, условия, сортировка для источника | в планировщике |
| `ForeignDataWriterBuilder::build()`, `:1431`, `:1443` | писать в сущность или в чужой источник; замена детей в чужом источнике запрещена | узлы плана записи; запрет — в `WritePlanner` |

## Как предлагается

Планировщик решает один раз и записывает решение узлом. Сборщик только отображает узел в исполнителя.

### Чтение

```php
/**
 * Лист плана: ресурс читается из чужого источника. Пара к EntityScanNode: у того SQL, у этого — класс источника
 * и то, что ему передать.
 */
final readonly class ForeignDataScanNode
{
    /**
     * @param class-string<ForeignDataSource&SelectForeignDataInterface> $source     класс источника: объект даст контейнер
     * @param list<string>                                               $columns    колонки модели
     * @param list<array<string, mixed>>                                 $conditions условия клиента, уже в колонках
     * @param list<array<string, mixed>>                                 $order      сортировка клиента, уже в колонках
     * @param list<string>                                               $keys       ключи соединений
     */
    public function __construct(
        public ResourceNode $input,
        public string $source,
        public array $columns,
        public array $conditions,
        public array $order,
        public array $keys,
    ) {}
}
```

```php
// Планировщик: отдельный шаг перед sql
if ($node instanceof EntityScanNodeDraft && self::isForeignData(resource: $node->getRootResourceNode()->resource)) {
    return new ForeignDataScanNode(
        input: $node->getRootResourceNode(),
        source: self::getForeignDataSource(
            resource: $node->getRootResourceNode()->resource,
            capability: SelectForeignDataInterface::class,
        ),
        columns: …,
        conditions: …,
        order: …,
        keys: $keys,
    );
}
```

```php
// ReaderBuilder::getNode(): обычная строка отображения, без isForeignData()
if ($node instanceof ForeignDataScanNode) {
    return $this->getForeignDataScanReader(node: $node, registry: $registry);
}
```

Лучше, если черновик над чужим источником не появляется вовсе: шаг `close` (`EntityScanNodeDraftPass`) сразу
ставит над таким ресурсом заготовку узла чужого источника, а не `EntityScanNodeDraft`. Тогда узел `Entity…` никогда
не стоит над ресурсом без сущности.

### Запись

Сейчас `InsertPlanner` и `UpdatePlanner` «видят чужой источник как сущность из одной таблицы», а сборщик подменяет
исполнителя. Вместо этого — свои узлы:

| Ветвь места (`WriteOperationNode`) | Над сущностью | Над чужим источником |
|---|---|---|
| `add` | `InsertResourceNode` → `InsertEntityNode` | `InsertResourceNode` → `InsertForeignDataNode` |
| `update` | `UpdateResourceNode` → `UpdateEntityNode` | `UpdateResourceNode` → `UpdateForeignDataNode` |
| `keep` | `KeepEntityNode` | `KeepForeignDataNode` |
| `remove` | `DeleteEntityNode` | `DeleteForeignDataNode` |

Исполнители для правого столбца уже есть в прототипе: `InsertForeignDataWriter`, `UpdateForeignDataWriter`,
`KeepForeignDataWriter`, `DeleteForeignDataWriter`.

`WritePlanner` при этом проверяет один раз, при планировании:

- источник реализует интерфейс операции (`InsertForeignDataInterface` и т. д.) — иначе `LogicException`;
- полная замена детей (`EntityReplaceNode`) не ставится над детьми из чужого источника — иначе `LogicException`
  (сейчас это проверка в сборщике).

## Где менять

| Место | Что сделать |
|---|---|
| `example/source-document.php` → `src/Model` | интерфейсы `SelectForeignDataInterface`, `InsertForeignDataInterface`, `UpdateForeignDataInterface`, `DeleteForeignDataInterface` |
| новый `src/Plan/Select/ForeignDataScanNode.php` | узел плана чтения |
| `src/Plan/Select/HashOneJoinNode.php` (из прототипа) | узел связи 1-1 между потоками |
| `src/Plan/Select/SelectPlanner.php` | шаг `separate` перед остальными; шаг, который ставит `ForeignDataScanNode`; `HashToMergeRule` учитывает порядок чтения источника (сейчас это отдельное `ForeignDataHashToMergeRule`) |
| `src/Plan/Select/BuildSqlPass.php` | цепочка предков обрывается на `ForeignDataScanNode`; сам узел шаг не трогает |
| `src/Plan/Select/SelectPlanPrinter.php` | печать `ForeignDataScanNode` |
| `src/Executor/Select/ForeignDataScanReader.php`, `HashOneJoinReader.php` (из прототипа) | исполнители |
| `src/Executor/Select/ReaderBuilder.php:104` | `getNode()`: строки отображения `ForeignDataScanNode` и `HashOneJoinNode` |
| `src/Executor/Select/ReaderBuilder.php:108` | `EntityScanNodeDraft` в плане → `LogicException` — не меняется, и снова значит только «шаг `sql` не выполнялся» |
| новые `src/Plan/Insert/InsertForeignDataNode.php`, `src/Plan/Update/UpdateForeignDataNode.php`, `src/Plan/Write/KeepForeignDataNode.php`, `src/Plan/Delete/DeleteForeignDataNode.php` | узлы плана записи |
| `src/Plan/Write/WritePlanner.php`, `InsertPlanner`, `UpdatePlanner` | выбор узла по виду источника; проверки интерфейса операции и запрет замены детей |
| `src/Executor/Insert`, `Update`, `Write`, `Delete` (из прототипа) | `InsertForeignDataWriter`, `UpdateForeignDataWriter`, `KeepForeignDataWriter`, `DeleteForeignDataWriter` |
| `src/Executor/Write/WriterBuilder.php:109`, `:145` | строки отображения четырёх новых узлов |
| `example/source-document.php` | исчезают `ForeignDataPlanner`, `ForeignDataReaderBuilder`, `ForeignDataWriterBuilder`; остаются источники, ресурсы и сценарии |

## Что получится

- `EntityScanNodeDraft` в готовом плане снова означает только одно — «шаг `sql` не выполнялся».
- План печатается как есть: чужой источник виден узлом `ForeignDataScanNode`.
- Отдельные сборщики не нужны: это новые строки в `ReaderBuilder` и `WriterBuilder`.
- Ошибки описания (источник не поддерживает операцию, замена детей в чужом источнике) ловятся при планировании.

## Что надо решить до реализации

1. **Аргументы маршрута.** Сейчас каждый источник получает `$arguments` через конструктор сборщика прототипа. Это
   значения, а не форма, поэтому в план они не идут. Значит, `ReaderBuilder` и `WriterBuilder` должны получить их
   параметром (у `WriterBuilder::getRoot()` уже есть похожий `route_id`).
2. **Вложенный маршрут.** Для сущностей родителя из маршрута проверяет `EntityRouteParentNode`. Прототип для чужих
   источников его запрещает: аргументы маршрута уходят в источник как есть. Оставить так или дать источнику свой
   способ проверить родителя?
3. **Ключи соединений.** Сейчас чужой источник отдаёт ключами все целочисленные колонки (`:1178`), потому что ключи
   сверху до него не доходят. В `ForeignDataScanNode` можно передавать точные ключи, как сущности.
4. **Ограничения прототипа, которые переедут как есть:** ветка внутри общей строки рядом с чужим источником —
   `LogicException`; транзакции у чужого источника нет; условий родителя он не получает.

## Порядок

Чтение и запись независимы. Предлагаю два шага:

1. чтение: интерфейс `SelectForeignDataInterface`, `ForeignDataScanNode`, `HashOneJoinNode`, шаги планировщика,
   строки в `ReaderBuilder`;
2. запись: три интерфейса записи, четыре узла, проверки в `WritePlanner`, строки в `WriterBuilder`.
