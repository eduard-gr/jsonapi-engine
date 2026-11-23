# Ветвление в исполнителях, которое можно вынести в план

Образец — `SerializeReader` и `ManualSerializeReader`: раньше один узел на каждой строке спрашивал себя «есть ли
у ресурса свой `serialize()`», теперь это два узла, а выбор один раз делает `ReaderBuilder`, глядя на класс ресурса
из плана.

Общий признак таких мест: исполнитель при выполнении проверяет то, что известно ещё при построении плана — описание
ресурса, форму дерева операций, роль узла. Таких мест четыре, по файлу на каждое. В каждом файле: в чём проблема, код «как сейчас», таблица
«Где менять» с файлами и строками и набросок «как предлагается».

Весь код «как предлагается» — наброски: они не выполнялись и в `src` не внесены. Классы и узлы с новыми именами
(`KeepEntityNode`, `FindTableWriter`, `ForeignDataScanNode`, …) пока не существуют.

| Файл | Что | Где основные изменения |
|---|---|---|
| [1-keep-operation.md](1-keep-operation.md) | `KeepOperation` внутри ветви изменения | `WritePlanner`, `WriteOperationNode`, `WriteOperationWriter`, `UpdateResourceWriter` |
| [2-sources.md](2-sources.md) | чужие источники: узлы плана вместо проверок в сборщиках — **реализовано** (чтение и запись) | прототип `example/source-document.php` → `Plan\Select`, `ReaderBuilder`, `WriterBuilder` |
| [3a-link-columns.md](3a-link-columns.md) | проверка колонок связи на каждой операции | `UpdateResourceWriter`, `DeleteEntityWriter`, `WritePlanner` |
| [3b-composite-attribute.md](3b-composite-attribute.md) | составной атрибут падает при выполнении | `Resource::validate()` |
| [4a-ascending-key.md](4a-ascending-key.md) | флаг `strict` у `AscendingKeyReader` | `AscendingKeyReader`, `ReaderBuilder`, ручные деревья |
| [4b-lock.md](4b-lock.md) | флаг `lock` — оставить как есть | — |

Пункты 3а и 3б — не выбор между реализациями, а тот же корень: исполнитель при каждом вызове проверяет описание,
которое от вызова к вызову не меняется.

## Что в план не выносится

Здесь исполнитель ветвится по данным документа или строки, а не по описанию. План этого знать не может.

| Место | По чему ветвится | Почему остаётся |
|---|---|---|
| `WriteOperationWriter` | класс операции элемента | ветви в плане уже есть; какая сработает, решает документ |
| `EntityReplaceWriter` | `RelationshipOperation::$replace` | полная или частичная замена — данные, а не форма плана (принятое решение) |
| `InsertOptionalEntityWriter`, `UpdateOptionalEntityWriter` | прислано ли что-то для необязательной таблицы | зависит от атрибутов конкретной операции |
| `UpdateEntityWriter`, `SELECT` после `UPDATE` | вернул ли `UPDATE` строку | «значения те же» и «строки нет» различимы только по результату |
| `EntitySharedJoinReader`, `HashJoinReader`, `RegisterReader` | `null` вместо ресурса | пустой `LEFT JOIN` в конкретной строке |
| `SerializeReader`, `ManualSerializeReader` | `NULL` в первичном ключе | то же: ресурса нет в этой строке |

## С чего начинать

1. **[Пункт 1](1-keep-operation.md) (`keep`)** — реализован 2026-11-20. Был: небольшой, целиком в `src`, убирает ветвление сразу из двух писателей и исправляет
   `explain()`.
2. **[Пункт 3б](3b-composite-attribute.md) (составной атрибут)** — решён 2026-11-20 иначе: интерфейсы `SerializeInterface`/`DeserializeInterface`. Было: одна проверка в `validate()`.
3. **[Пункт 3а](3a-link-columns.md) (колонки связи)** — реализован 2026-11-20. Был: меняет узлы плана записи; удобно делать вместе с пунктом 1, пока открыт
   `WritePlanner::getPlace()`.
4. **[Пункт 2](2-sources.md) (источники)** — самый крупный, привязан к переносу прототипа в `src`.
5. **[Пункт 4а](4a-ascending-key.md)** — реализован 2026-11-20. Было: по случаю; **[4б](4b-lock.md)** — не трогать.
