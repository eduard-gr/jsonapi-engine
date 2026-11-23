<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Model;

/**
 * Базовый класс источника данных — описания табличной структуры, которую отдаёт источник: колонки, их типы,
 * первичный и уникальные ключи. Любой источник данных отдаёт табличное представление; где данные лежат, этот класс
 * не говорит. Видов два: `DatabaseEntitySource` — сущность, источник в таблице БД (добавляет `TABLE`, SQL строит
 * движок), и `ForeignDataSource` — чужой источник, который читает и пишет своим кодом. Классы и методы, которые
 * зависят от вида источника, называются по нему: `Entity…` (`EntityScanNode`, `InsertEntityWriter`) и `ForeignData…`
 * (`ForeignDataScanReader`, `InsertForeignDataWriter`); без приставки — те, кому вид источника безразличен.
 *
 * Наследник объявляет только константы: по строковой константе на каждую колонку (значение — имя колонки) и
 * `COLUMNS` — карту `имя колонки => описание` с ключами `type`, `constraint`, `references`, `unique`, `nullable`,
 * `mode`. Имя колонки — это колонка модели данных (`Resource::getModel()`).
 *
 * Описанию верят: с настоящими данными его никто не сверяет. `validate()` проверяет только согласованность самих
 * констант. Тихий отказ: если данные разошлись с описанием, ошибки не будет — неверный тип даст неверное приведение
 * значения, неуникальная колонка, объявленная `PRIMARY`/`unique`, — размноженные строки модели.
 */
abstract class DataSource implements MetadataInterface
{
    use NamedConstants;

    /** @var array<string, array<string, mixed>> колонки источника; наследник переопределяет */
    public const array COLUMNS = [];

    /** @var list<string> допустимые ключи описания колонки; `DatabaseEntitySource` добавляет свои */
    protected const array COLUMN_KEYS = ['type', 'constraint', 'references', 'unique', 'nullable', 'mode'];

    /** @var list<string> строковые константы наследников, которые не являются именами колонок (`TABLE` у сущности) */
    protected const array RESERVED_CONSTANTS = [];

    /**
     * Имя колонки первичного ключа.
     *
     * @throws \LogicException если колонки PRIMARY нет
     */
    public static function getPrimaryKeyColumnName(): string
    {
        foreach (static::COLUMNS as $name => $description) {
            if (($description['constraint'] ?? null) === self::PRIMARY) {
                return $name;
            }
        }

        throw new \LogicException(static::class . ': PRIMARY column is not declared');
    }

    /**
     * Уникальна ли колонка в строках источника: первичный ключ или `unique`.
     */
    public static function isUnique(string $name): bool
    {
        $description = static::COLUMNS[$name] ?? [];

        return ($description['constraint'] ?? null) === self::PRIMARY || ($description['unique'] ?? false) === true;
    }

    /**
     * Проверяет константы источника. Вызывается в тестах для каждого источника, а не в запросах.
     *
     * @throws \LogicException со списком всех найденных ошибок
     */
    public static function validate(): void
    {
        $errors = static::getValidationErrors();
        if ($errors !== []) {
            throw new \LogicException(static::class . ":\n- " . implode("\n- ", $errors));
        }
    }

    /**
     * Ошибки описания: ключи и значения `COLUMNS`, ровно один `PRIMARY`, `references` у `FOREIGN`, константы
     * совпадают с ключами `COLUMNS`. Наследник (`DatabaseEntitySource`) добавляет проверки своих констант.
     *
     * @return list<string>
     */
    protected static function getValidationErrors(): array
    {
        $errors = [];
        $primary = [];
        foreach (static::COLUMNS as $name => $description) {
            $location = "column {$name}";
            if (!\is_array($description)) {
                $errors[] = "{$location}: description must be an array";
                continue;
            }
            foreach (array_diff(array_keys($description), static::COLUMN_KEYS) as $key) {
                $errors[] = "{$location}: unknown key {$key}";
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
                        $target = $references::COLUMNS[$references::getPrimaryKeyColumnName()];
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

        // строковые константы самого класса, кроме служебных, — имена колонок
        array_push($errors, ...self::getNamedConstantErrors(
            array_fill_keys(array_keys(static::COLUMNS), 'column'),
            static::RESERVED_CONSTANTS,
            'COLUMNS',
        ));

        return $errors;
    }
}
