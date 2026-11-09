<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Model;

/**
 * Базовый класс сущности — описания одной таблицы БД, из которых `model()` собирает модель данных.
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
abstract class Entity implements Metadata
{
    use NamedConstants;

    /** таблица; наследник переопределяет */
    public const string TABLE = '';

    /** @var array<string, array<string, mixed>> колонки таблицы; наследник переопределяет */
    public const array COLUMNS = [];

    private const array COLUMN_KEYS = ['column', 'type', 'constraint', 'references', 'unique', 'nullable', 'mode'];

    /**
     * Имя колонки первичного ключа.
     *
     * @throws \LogicException если колонки PRIMARY нет
     */
    public static function primaryColumn(): string
    {
        foreach (static::COLUMNS as $name => $description) {
            if (($description['constraint'] ?? null) === self::PRIMARY) {
                return $name;
            }
        }

        throw new \LogicException(static::class . ': PRIMARY column is not declared');
    }

    /**
     * Колонка таблицы в БД.
     */
    public static function column(string $name): string
    {
        return static::COLUMNS[$name]['column'] ?? $name;
    }

    /**
     * Уникальна ли колонка в таблице: первичный ключ или `unique`.
     */
    public static function isUnique(string $name): bool
    {
        $description = static::COLUMNS[$name] ?? [];

        return ($description['constraint'] ?? null) === self::PRIMARY || ($description['unique'] ?? false) === true;
    }

    /**
     * Проверяет константы сущности. Вызывается в тестах для каждой сущности, а не в запросах.
     *
     * @throws \LogicException со списком всех найденных ошибок
     */
    public static function validate(): void
    {
        $errors = [];

        if (static::TABLE === '') {
            $errors[] = 'TABLE must be a non-empty string';
        }

        $primary = [];
        foreach (static::COLUMNS as $name => $description) {
            $location = "column {$name}";
            if (!\is_array($description)) {
                $errors[] = "{$location}: description must be an array";
                continue;
            }
            foreach (array_diff(array_keys($description), self::COLUMN_KEYS) as $key) {
                $errors[] = "{$location}: unknown key {$key}";
            }
            if (isset($description['column']) && !\is_string($description['column'])) {
                $errors[] = "{$location}: column must be a string";
            }
            if (!isset($description['type'])) {
                $errors[] = "{$location}: type is required";
            } elseif (!\in_array($description['type'], self::TYPES, true)) {
                $errors[] = "{$location}: unknown type {$description['type']}";
            }
            if (isset($description['mode']) && !\in_array($description['mode'], [self::READ, self::READ_WRITE], true)) {
                $errors[] = "{$location}: unknown mode {$description['mode']}";
            }
            foreach (['nullable', 'unique'] as $flag) {
                if (isset($description[$flag]) && !\is_bool($description[$flag])) {
                    $errors[] = "{$location}: {$flag} must be bool";
                }
            }

            $constraint = $description['constraint'] ?? null;
            if ($constraint !== null && !\in_array($constraint, [self::PRIMARY, self::FOREIGN], true)) {
                $errors[] = "{$location}: unknown constraint {$constraint}";
            }
            if ($constraint === self::PRIMARY) {
                $primary[] = $name;
            }
            if ($constraint === self::FOREIGN) {
                $references = $description['references'] ?? null;
                if (!\is_string($references) || !is_subclass_of($references, self::class)) {
                    $errors[] = "{$location}: FOREIGN requires references, a subclass of " . self::class;
                } else {
                    try {
                        $target = $references::COLUMNS[$references::primaryColumn()];
                        if (($description['type'] ?? null) !== ($target['type'] ?? null)) {
                            $errors[] = "{$location}: type must match the primary key type of {$references}";
                        }
                    } catch (\LogicException) {
                        $errors[] = "{$location}: {$references} has no PRIMARY column";
                    }
                }
            } elseif (isset($description['references'])) {
                $errors[] = "{$location}: references is allowed only with FOREIGN";
            }
        }

        if (\count($primary) !== 1) {
            $errors[] = 'exactly one PRIMARY column is required, found ' . \count($primary);
        }

        // строковые константы самого класса, кроме TABLE, — имена колонок
        array_push($errors, ...self::namedConstantErrors(array_fill_keys(array_keys(static::COLUMNS), 'column'), ['TABLE'], 'COLUMNS'));

        if ($errors !== []) {
            throw new \LogicException(static::class . ":\n- " . implode("\n- ", $errors));
        }
    }
}
