# 4а. `AscendingKeyReader($strict)`

> **Реализовано 2026-11-20** как в наброске: `AscendingKeyReader(source, key)` без флага и его наследник
> `UniqueAscendingKeyReader`. Ниже — исходное предложение: «как сейчас» и номера строк описывают код до изменения.
>
> Отличие от наброска: название порядка для текста ошибки — константа `ORDER`, которую наследник переопределяет.

## Проблема

Узел проверяет порядок ключей на входе слияния. Режимов два, и они жёстко привязаны к роли входа: `outer` — строго по
возрастанию (ключ родителя уникален), `inner` — по неубыванию (у родителя много детей). Режим передаётся флагом и
проверяется на каждом элементе:

```php
// ReaderBuilder
outer: new AscendingKeyReader(
    source: …,
    key: $node->relation[0],
    strict: true,
),
inner: new AscendingKeyReader(
    source: …,
    key: $node->relation[1],
    strict: false,
),

// AscendingKeyReader::getIterator(), на каждом элементе
$out_of_order = $previous_key !== null
    && ($this->strict ? $key <= $previous_key : $key < $previous_key);
```

Что здесь плохо: два узла с разным контрактом (один ещё и ловит повтор ключа — признак размноженных строк родителя)
в `explain()` называются одинаково и различаются полем `strict`. Булев аргумент в месте вызова не читается.

## Где менять

| Место | Что сейчас | Что сделать |
|---|---|---|
| `src/Executor/Select/AscendingKeyReader.php:39` | `private bool $strict` в конструкторе | убрать параметр; свойства сделать `protected`, класс — не `final` |
| `src/Executor/Select/AscendingKeyReader.php:64` | сравнение с `$this->strict ? … : …` на каждом элементе | вызов `isOutOfOrder()`; текст ошибки (строка 67) — тоже через метод |
| `src/Executor/Select/AscendingKeyReader.php:48` | `explain()` с ключом `strict` | ключ убрать: узел описывается своим классом |
| `src/Executor/Select/ReaderBuilder.php:125`, `:130` | `strict: true` для outer, `strict: false` для inner | outer — `UniqueAscendingKeyReader`, inner — `AscendingKeyReader` |
| новый `src/Executor/Select/UniqueAscendingKeyReader.php` | — | наследник со строгим сравнением |
| `example/file-merge-document.php:253`, `:258` | то же в ручном дереве | то же |
| `example/polymorphic-document.php:450`, `:455` | то же | то же |
| `example/source-document.php:1230`, `:1235` | то же | то же |
| `test/DocumentTreeTest.php:226`, `:238` | то же | то же |
| `test/Executor/Select/AscendingKeyReaderTest.php` | пять мест с `strict:` | строгие случаи — на новый класс |

## Как предлагается

Тот же приём, что с `ManualSerializeReader`: наследник заменяет одно сравнение.

```php
class AscendingKeyReader implements PullExecutorInterface
{
    public function __construct(
        protected PullExecutorInterface $source,
        protected string $key,
    ) {}

    /** Нарушен ли порядок: ключ меньше предыдущего. */
    protected function isOutOfOrder(int $key, int $previous_key): bool
    {
        return $key < $previous_key;
    }
}

/** Строго по возрастанию: повтор ключа — тоже нарушение (вход outer, ключ родителя уникален). */
final class UniqueAscendingKeyReader extends AscendingKeyReader
{
    #[\Override]
    protected function isOutOfOrder(int $key, int $previous_key): bool
    {
        return $key <= $previous_key;
    }
}
```

```php
// ReaderBuilder
outer: new UniqueAscendingKeyReader(
    source: …,
    key: $node->relation[0],
),
inner: new AscendingKeyReader(
    source: …,
    key: $node->relation[1],
),
```

Польза здесь небольшая: это читаемость `explain()` и места вызова, поведение не меняется. Делать стоит заодно
с чем-то соседним, а не отдельно.
