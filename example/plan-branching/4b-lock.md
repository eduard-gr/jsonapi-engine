# 4б. `lock` в `EntityRouteParentWriter`

> Код «как предлагается» — набросок: он не выполнялся и в `src` не внесён. Номера строк — на момент написания.

## Проблема

```php
// EntityRouteParentWriter::link()
if ($this->lock) {
    $select->locking[] = new LockingElement(LockingStrength::KEY_SHARE);
}
```

Флаг проходит через `WriterBuilder(pdo, lock, container)` в каждый исполнитель, которому нужна проверка существования.

## Где менять

Менять не предлагается. Места, где флаг живёт:

| Место | Что сейчас |
|---|---|
| `src/Executor/Write/WriterBuilder.php:68` | `private bool $lock` — параметр сборщика |
| `src/Executor/Write/WriterBuilder.php:92` | передаётся в `EntityRouteParentWriter` |
| `src/Executor/Write/EntityRouteParentWriter.php:67` | `if ($this->lock)` добавляет `FOR KEY SHARE` |
| `src/Executor/Write/EntityRouteParentWriter.php:91` | `lock` в `explain()` |

## Как предлагается

Оставить флагом. От предыдущих случаев он отличается по существу: это не свойство ресурса и не форма плана, а
свойство базы (`FOR KEY SHARE` есть в PostgreSQL и нет в SQLite примера). Один и тот же план выполняется на обеих
базах, поэтому узлу плана про блокировку знать нечего.

Если флагов диалекта станет больше одного, их стоит собрать в один объект настроек базы и передавать его вместо
отдельных аргументов — но это не про план:

```php
final readonly class DatabaseDialect
{
    public function __construct(
        public bool $key_share_lock,
    ) {}
}

new WriterBuilder(
    pdo: $pdo,
    dialect: new DatabaseDialect(
        key_share_lock: true,
    ),
    container: $container,
);
```
