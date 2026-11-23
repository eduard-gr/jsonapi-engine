<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Model;

/**
 * Базовый класс сущности — описания одной таблицы БД, из которых `getModel()` собирает модель данных. Сущность —
 * источник (`DataSource`), данные которого лежат в таблице: к описанию колонок она добавляет таблицу и имена колонок в БД.
 *
 * Наследник объявляет только константы: `TABLE`, по строковой константе на каждую колонку (значение — имя
 * колонки сущности) и `COLUMNS` — карту `имя колонки сущности => описание`. Имя колонки сущности — это
 * колонка модели, которая подключает таблицу; колонка таблицы в БД задаётся `column`, если отличается.
 *
 * Постоянная колонка: `'mode' => self::FIXED` и `'value' => <значение>` (только вместе). Сущность с ней — это не
 * вся таблица, а её строки с этим значением: одна таблица на несколько видов записей, вид — константа
 * (`user_components.component_id = 70`). Клиент значение не присылает и не меняет, подставляет его движок:
 *  - чтение — вместо таблицы в `FROM` стоит подзапрос `(select * from <таблица> where <колонка> = <значение>)`
 *    (`ModelTree::getFrom()`), поэтому условие действует и в `JOIN`, и в теле CTE предка;
 *  - вставка — колонка со значением добавляется в `INSERT`;
 *  - изменение, удаление, поиск, полная замена детей, проверка родителя маршрута — условие в `WHERE`.
 * Значение пишется в SQL литералом, а не параметром: оно из кода, а не от клиента, и частичный индекс по нему
 * PostgreSQL использует только при литерале. Колонка, уникальная лишь среди строк своего вида
 * (`user_profile_id`), может быть объявлена `PRIMARY`.
 *
 * Сущности верят: она — источник истины о таблице, и с БД её никто не сверяет. `validate()` проверяет только
 * согласованность самих констант. Тихий отказ: если таблица разошлась с сущностью (миграция без правки
 * сущности), ошибки не будет — неверный тип даст неверное приведение значения, неуникальная колонка,
 * объявленная `PRIMARY`/`unique`, — размноженные строки модели.
 */
abstract class DatabaseEntitySource extends DataSource
{
    /** таблица; наследник переопределяет */
    public const string TABLE = '';

    /** @var list<string> к ключам источника — имя колонки в БД и значение постоянной колонки */
    protected const array COLUMN_KEYS = [...parent::COLUMN_KEYS, 'column', 'value'];

    /** @var list<string> `TABLE` — не имя колонки */
    protected const array RESERVED_CONSTANTS = ['TABLE'];

    /**
     * Колонка таблицы в БД.
     */
    public static function getColumn(string $name): string
    {
        return static::COLUMNS[$name]['column'] ?? $name;
    }

    /**
     * Постоянные колонки сущности: колонка → значение (`'mode' => self::FIXED`, `'value' => …`). Пусто — у сущности
     * их нет. По ним движок ограничивает строки таблицы (см. описание класса).
     *
     * @return array<string, int|float|string|bool>
     */
    public static function getFixedValues(): array
    {
        $values = [];
        foreach (static::COLUMNS as $name => $description) {
            if ((($description['mode'] ?? 0) & self::FIXED) !== 0) {
                $values[$name] = $description['value'];
            }
        }

        return $values;
    }

    /**
     * К проверкам источника добавляет свои: `TABLE` не пуст, `column` — строка, постоянная колонка описана полностью
     * (`FIXED` и `value` только вместе, значение соответствует `type`, колонка не `PRIMARY` и не `WRITE`).
     */
    #[\Override]
    protected static function getValidationErrors(): array
    {
        $errors = [];
        if (static::TABLE === '') {
            $errors[] = 'TABLE must be a non-empty string';
        }
        foreach (static::COLUMNS as $name => $description) {
            if (!\is_array($description)) {
                continue;
            }
            if (isset($description['column']) && !\is_string($description['column'])) {
                $errors[] = "column {$name}: column must be a string";
            }
            $mode = $description['mode'] ?? self::READ_WRITE;
            $is_fixed = \is_int($mode) && ($mode & self::FIXED) !== 0;
            if ($is_fixed !== \array_key_exists('value', $description)) {
                $errors[] = "column {$name}: FIXED mode and value are declared only together";
                continue;
            }
            if (!$is_fixed) {
                continue;
            }
            if (($mode & self::WRITE) !== 0) {
                $errors[] = "column {$name}: FIXED column can not be WRITE";
            }
            if (($description['constraint'] ?? null) === self::PRIMARY) {
                $errors[] = "column {$name}: FIXED column can not be PRIMARY";
            }
            if (!self::isValueOfType(value: $description['value'], type: $description['type'] ?? null)) {
                $errors[] = "column {$name}: value does not match type " . (string) ($description['type'] ?? '');
            }
        }

        return [...$errors, ...parent::getValidationErrors()];
    }

    /**
     * Подходит ли значение постоянной колонки её типу. NULL не подходит никогда: условие `колонка = NULL` не находит
     * ни одной строки.
     */
    private static function isValueOfType(mixed $value, mixed $type): bool
    {
        if ($type === self::INT) {
            return \is_int($value);
        }
        if ($type === self::FLOAT) {
            return \is_int($value) || \is_float($value);
        }
        if ($type === self::BOOL) {
            return \is_bool($value);
        }

        return \is_string($value);
    }
}
