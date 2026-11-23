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
 * Сущности верят: она — источник истины о таблице, и с БД её никто не сверяет. `validate()` проверяет только
 * согласованность самих констант. Тихий отказ: если таблица разошлась с сущностью (миграция без правки
 * сущности), ошибки не будет — неверный тип даст неверное приведение значения, неуникальная колонка,
 * объявленная `PRIMARY`/`unique`, — размноженные строки модели.
 */
abstract class DatabaseEntitySource extends DataSource
{
    /** таблица; наследник переопределяет */
    public const string TABLE = '';

    /** @var list<string> к ключам источника — имя колонки в БД */
    protected const array COLUMN_KEYS = [...parent::COLUMN_KEYS, 'column'];

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
     * К проверкам источника добавляет свои: `TABLE` не пуст, `column` — строка.
     */
    #[\Override]
    protected static function getValidationErrors(): array
    {
        $errors = [];
        if (static::TABLE === '') {
            $errors[] = 'TABLE must be a non-empty string';
        }
        foreach (static::COLUMNS as $name => $description) {
            if (\is_array($description) && isset($description['column']) && !\is_string($description['column'])) {
                $errors[] = "column {$name}: column must be a string";
            }
        }

        return [...$errors, ...parent::getValidationErrors()];
    }
}
