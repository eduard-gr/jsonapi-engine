# 3а. Колонки связи в `UpdateResourceWriter` и `DeleteEntityWriter`

> **Реализовано 2026-11-20.** Ниже — исходное предложение: «как сейчас» и номера строк описывают код до изменения.
>
> Что сделано иначе, чем в наброске:
> - сторож `WriteLinkedKeyNode` оборачивает не всё место, а каждую из ветвей `update`, `keep`, `remove` отдельно:
>   пустой ответ сторожа должен дойти до развилки места, которая превращает его в 404;
> - проверка колонок связи зависит от ветвей места: для `update` — колонка модели ресурса (любой таблицы), для
>   `keep` и `remove` — колонка главной таблицы; для `add` проверки нет;
> - у `KeepEntityWriter` (появился позже этого предложения) та же проверка тоже убрана.
>
> Что изменилось в поведении: дерево записи, собранное руками без планировщика, колонки связи больше не проверяет,
> а без сторожа над `UpdateResourceWriter` ресурс с первичным ключом — колонкой связи изменит чужую строку молча.

## Проблема

Ребёнок получает от родителя `$link` — колонки связи со значениями (`['certificate_id' => 1]`). Они становятся
условием принадлежности: чужой ребёнок не найдётся. Имена этих колонок — это `relation[1]` соединения из плана (или
вложенного маршрута), они одни и те же для всех операций места. Но проверяются при каждом `push()`:

```php
// UpdateResourceWriter::push()
$tree = ModelTree::of($this->resource::class);
foreach (array_keys($link) as $column) {
    if (!$tree->hasColumn((string) $column)) {
        throw new LogicException($this->resource::class . ": link column {$column} is not a model column");
    }
}

$primary = $this->resource::getPrimaryKeyColumnName();
$id = $operation->identity->id;
// Первичный ключ ребёнка — сама колонка связи (delivery.certificate_id): id обязан совпасть с родителем
if (isset($link[$primary]) && (int) $link[$primary] !== $id) {
    return [];
}

// DeleteEntityWriter::push()
foreach ($link as $column => $value) {
    if (!isset($entity::COLUMNS[$column])) {
        throw new LogicException("{$entity}: link column {$column} is not in the main table");
    }
    …
}
```

Что здесь плохо:

- **Ошибка описания обнаруживается поздно.** Колонка связи, которой нет в модели, — ошибка ресурса, а не документа.
  Узнаём о ней, когда часть операций уже выполнена (транзакцию придётся откатывать), а не при построении плана.
- **Частный случай спрятан в общем коде.** «Первичный ключ и есть колонка связи» верно для `delivery` и неверно для
  `domains`; это свойство места, но проверяется у каждого ресурса при каждой операции.

## Где менять

| Место | Что сейчас | Что сделать |
|---|---|---|
| `src/Executor/Update/UpdateResourceWriter.php:47` | цикл по `$link`: колонка связи есть в модели? — на каждом `push()` | убрать: проверяет планировщик |
| `src/Executor/Update/UpdateResourceWriter.php:57` | «первичный ключ — колонка связи: id обязан совпасть с родителем» | убрать: это делает `WriteLinkedKeyWriter` над ветвью |
| `src/Executor/Delete/DeleteEntityWriter.php:66` | цикл по `$link`: колонка связи в главной таблице? — на каждом `push()` | проверку убрать, условия запроса оставить |
| `src/Plan/Write/WritePlanner.php:178` | `child: $this->getPlace(resource:, operations:)` — место не знает своих колонок связи | передать `relation[1]` в `getPlace()`, там проверить и поставить `WriteLinkedKeyNode` |
| `src/Plan/Write/WritePlanner.php:82` | `getRoute()`: колонка связи вложенного маршрута | то же для корня под маршрутом |
| `src/Plan/Write/WritePlanner.php:102` | `getPlace(resource, operations)` | новый параметр `link_columns`, проверка `getLinkColumns()` |
| `src/Executor/Write/WriterBuilder.php:100` | `build()`: соединение, замена, место | новая строка: `WriteLinkedKeyNode` → `WriteLinkedKeyWriter` |
| новые `src/Plan/Write/WriteLinkedKeyNode.php`, `src/Executor/Write/WriteLinkedKeyWriter.php` | — | узел-обёртка и его исполнитель |
| `test/Executor/Write/WriteExecutionTest.php`, `test/Plan/Write/WritePlannerTest.php` | топология без обёртки | ожидания плана и `explain()` для `delivery` |

## Как предлагается

Колонки связи места — в узле плана; проверка — при построении; частный случай — отдельный узел-обёртка, как
`AscendingKeyReader` над входом слияния.

```php
// Plan\Write\WritePlanner: имена колонок связи известны из соединения или маршрута
private static function getLinkColumns(string $resource, array $link_columns): array
{
    $tree = ModelTree::of($resource);
    foreach ($link_columns as $column) {
        if (!$tree->hasColumn($column)) {
            throw new LogicException("{$resource}: link column {$column} is not a model column");
        }
    }

    return $link_columns;
}

// Первичный ключ ребёнка — колонка связи: над ветвями места ставится сторож
$place = \in_array($resource::getPrimaryKeyColumnName(), $link_columns, true)
    ? new WriteLinkedKeyNode(
        key: $resource::getPrimaryKeyColumnName(),
        input: $place,
    )
    : $place;
```

```php
/**
 * Стоит над местом, где первичный ключ ресурса — сама колонка связи: id операции обязан совпасть с ключом родителя.
 * Не совпал — пусто (наверху 404) без запроса; совпал — операция идёт дальше.
 */
final readonly class WriteLinkedKeyWriter implements PushExecutorInterface
{
    public function __construct(
        private string $key,
        private PushExecutorInterface $input,
    ) {}

    #[\Override]
    public function push(WriteOperation $operation, array $link = []): array
    {
        if ((int) $link[$this->key] !== $operation->identity->id) {
            return [];
        }

        return $this->input->push($operation, $link);
    }
}
```

Что получается: `UpdateResourceWriter::push()` — только «строка из операции → таблицы», `DeleteEntityWriter` — только
запрос; ошибка описания — `LogicException` при построении плана, до первого запроса.

Оговорка: вставке (`InsertOperation`) сторож не нужен — у новой строки id ещё нет; `WriteLinkedKeyNode` ставится над
ветвями `update`, `keep` и `remove`, а не над всем местом.
