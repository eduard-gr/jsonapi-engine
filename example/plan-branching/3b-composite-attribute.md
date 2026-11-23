# 3б. Составной атрибут в `ResourceDeserializer`

> **Решено иначе 2026-11-20.** Ниже — исходное предложение (только запретить запись через `mode => READ`); «как
> сейчас» и номера строк описывают код до изменения.
>
> Что сделано: составной атрибут теперь можно записать. Ресурс реализует интерфейс `Resource\DeserializeInterface`
> (`deserialize(attributes, references): строка`), и `WriterBuilder` даёт его писателям
> `ManualResourceDeserializer` вместо `ResourceDeserializer` — выбор по классу ресурса из плана, как с
> `ManualSerializeReader`. Чтение так же переведено на интерфейс `Resource\SerializeInterface` вместо проверки
> `method_exists`.
>
> Из предложения осталась проверка `Resource::validate()`, но мягче: составной атрибут без `DeserializeInterface`
> обязан быть `'mode' => self::READ`. Тогда клиент получает отказ парсера (400), а не `LogicException` при
> выполнении.

## Проблема

После удаления `deserialize<Атрибут>()` составной атрибут (`columns`) записать нельзя. Узнаём об этом в самом низу:

```php
// Executor\Write\ResourceDeserializer::getRow()
if (isset($attribute['columns'])) {
    throw new LogicException("{$class}: composite attribute {$name} can not be written");
}
```

Что здесь плохо: клиент прислал атрибут, который ресурс объявил, парсер его пропустил, план построен, родитель уже
записан — и только тогда `LogicException`. Клиент получает 500 вместо понятного отказа, хотя ответ следует из одной
строки `ATTRIBUTES`.

## Где менять

| Место | Что сейчас | Что сделать |
|---|---|---|
| `src/Resource.php:346` | `$composite = isset($attribute['columns'])`, ниже — проверки составного атрибута | рядом (строка 387) добавить: составной атрибут обязан иметь `mode => READ` |
| `src/Parser/Write/WriteDocumentParser.php:398` | атрибут с `mode => READ` отклоняется парсером | не меняется — именно на это опирается предложение |
| `src/Executor/Write/ResourceDeserializer.php:42` | `LogicException` «composite attribute … can not be written» при выполнении | остаётся как защита от обхода парсера; docblock |
| `test/Executor/Select/Fixture/VoucherResource.php` | составной `validity` без `mode` | добавить `mode => READ` |
| тесты `Resource::validate()` | — | новый случай: составной атрибут без `READ` |

## Как предлагается

Составной атрибут — только для чтения по описанию. Парсер записи уже отклоняет атрибуты с `mode => READ`, поэтому
достаточно потребовать этот режим в `Resource::validate()`:

```php
// Resource::validate()
if ($composite && ($attribute['mode'] ?? null) !== self::READ) {
    $errors[] = "{$location}: composite attribute can not be written, declare 'mode' => self::READ";
}
```

Что получается: ошибка описания ловится в тестах; клиент, приславший такой атрибут, получает обычный отказ парсера
с JSON Pointer до первого запроса; ветка в `ResourceDeserializer` остаётся только как защита от обхода парсера.
