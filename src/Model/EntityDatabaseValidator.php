<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Model;

use sad_spirit\pg_wrapper\Connection;

/**
 * Сверяет константы сущности с таблицей в БД: колонки, типы, NULL, первичный ключ, уникальность и внешние ключи.
 *
 * Как и `Entity::validate()`, вызывается в тестах (с БД), а не в запросах. Таблица ищется по `search_path`
 * соединения. Колонки таблицы, которых нет в сущности, не проверяются: сущность может описывать часть таблицы.
 */
final class EntityDatabaseValidator
{
    /** тип сущности → типы PostgreSQL (`pg_type.typname`), которые ему соответствуют */
    private const array TYPES = [
        Metadata::INT => ['int2', 'int4', 'int8'],
        Metadata::FLOAT => ['float4', 'float8', 'numeric'],
        Metadata::STRING => ['text', 'varchar', 'bpchar', 'citext'],
        Metadata::BOOL => ['bool'],
        Metadata::DATE => ['date'],
        Metadata::DATETIME => ['timestamptz', 'timestamp'],
    ];

    /**
     * @param class-string<Entity> $entity сущность, уже прошедшая `Entity::validate()`
     *
     * @throws \LogicException со списком всех найденных расхождений
     */
    public static function validate(Connection $connection, string $entity): void
    {
        $table = $entity::TABLE;
        $oid = self::tableOid($connection, $table);
        if ($oid === null) {
            throw new \LogicException("{$entity}:\n- table {$table} does not exist");
        }

        $columns = [];
        foreach ($connection->executeParams(
            'SELECT a.attname AS name, t.typname AS type, a.attnotnull AS not_null
             FROM pg_attribute AS a JOIN pg_type AS t ON t.oid = a.atttypid
             WHERE a.attrelid = $1 AND a.attnum > 0 AND NOT a.attisdropped',
            [$oid],
        ) as $row) {
            $columns[$row['name']] = $row;
        }

        // уникальные индексы по одной колонке, без условия; выражения не соединяются с pg_attribute
        $primary = null;
        $unique = [];
        foreach ($connection->executeParams(
            'SELECT a.attname AS name, i.indisprimary AS is_primary
             FROM pg_index AS i JOIN pg_attribute AS a ON a.attrelid = i.indrelid AND a.attnum = i.indkey[0]
             WHERE i.indrelid = $1 AND i.indisunique AND i.indnkeyatts = 1 AND i.indpred IS NULL',
            [$oid],
        ) as $row) {
            if ($row['is_primary']) {
                $primary = $row['name'];
            } else {
                $unique[$row['name']] = true;
            }
        }

        /** @var array<string, array{target: string, target_column: string}> $foreign */
        $foreign = [];
        foreach ($connection->executeParams(
            "SELECT a.attname AS name, c.confrelid::text AS target, fa.attname AS target_column
             FROM pg_constraint AS c
             JOIN pg_attribute AS a ON a.attrelid = c.conrelid AND a.attnum = c.conkey[1]
             JOIN pg_attribute AS fa ON fa.attrelid = c.confrelid AND fa.attnum = c.confkey[1]
             WHERE c.conrelid = $1 AND c.contype = 'f' AND cardinality(c.conkey) = 1",
            [$oid],
        ) as $row) {
            $foreign[$row['name']] = $row;
        }

        $errors = [];
        foreach ($entity::COLUMNS as $name => $description) {
            $column = $entity::column($name);
            $at = "column {$name} ({$table}.{$column})";
            if (!isset($columns[$column])) {
                $errors[] = "{$at}: column does not exist";
                continue;
            }

            $type = $columns[$column]['type'];
            if (!\in_array($type, self::TYPES[$description['type']] ?? [], true)) {
                $errors[] = "{$at}: type {$description['type']} does not match {$type}";
            }
            $nullable = $description['nullable'] ?? false;
            if ($nullable === (bool) $columns[$column]['not_null']) {
                $errors[] = $nullable ? "{$at}: column is NOT NULL, nullable must be false"
                    : "{$at}: column allows NULL, nullable must be true";
            }

            $constraint = $description['constraint'] ?? null;
            if (($constraint === Metadata::PRIMARY) !== ($primary === $column)) {
                $errors[] = $constraint === Metadata::PRIMARY ? "{$at}: column is not the single-column primary key"
                    : "{$at}: column is the primary key, constraint must be PRIMARY";
            }
            $isUnique = $description['unique'] ?? false;
            if ($constraint !== Metadata::PRIMARY && $isUnique !== isset($unique[$column])) {
                $errors[] = $isUnique ? "{$at}: column has no unique index"
                    : "{$at}: column has a unique index, unique must be true";
            }

            if ($constraint === Metadata::FOREIGN) {
                /** @var class-string<Entity> $references */
                $references = $description['references'];
                $targetTable = $references::TABLE;
                $target = self::tableOid($connection, $targetTable);
                $targetColumn = $references::column($references::primaryColumn());
                if (!isset($foreign[$column]) || $foreign[$column]['target'] !== $target
                    || $foreign[$column]['target_column'] !== $targetColumn) {
                    $errors[] = "{$at}: no foreign key to {$targetTable}.{$targetColumn}";
                }
            } elseif (isset($foreign[$column])) {
                $errors[] = "{$at}: column has a foreign key, constraint must be FOREIGN";
            }
        }

        if ($errors !== []) {
            throw new \LogicException("{$entity}:\n- " . implode("\n- ", $errors));
        }
    }

    /**
     * oid таблицы по имени с учётом `search_path` или null, если таблицы нет.
     */
    private static function tableOid(Connection $connection, string $table): ?string
    {
        $oid = $connection->executeParams('SELECT to_regclass($1)::oid::text AS oid', [$table])->current()['oid'];

        return $oid === null ? null : (string) $oid;
    }
}
