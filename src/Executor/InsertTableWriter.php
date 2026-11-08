<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor;

use Ggs\JsonApiEngine\Model\Entity;
use PDO;
use sad_spirit\pg_builder\Insert;
use sad_spirit\pg_builder\nodes\ColumnReference;
use sad_spirit\pg_builder\nodes\expressions\NamedParameter;
use sad_spirit\pg_builder\nodes\expressions\RowExpression;
use sad_spirit\pg_builder\nodes\lists\RowList;
use sad_spirit\pg_builder\nodes\QualifiedName;
use sad_spirit\pg_builder\nodes\range\InsertTarget;
use sad_spirit\pg_builder\nodes\SetTargetElement;
use sad_spirit\pg_builder\nodes\TargetElement;
use sad_spirit\pg_builder\SqlBuilderWalker;
use sad_spirit\pg_builder\Values;

/**
 * Лист дерева вставки: одна строка в одну таблицу. Про JSON:API ничего не знает.
 *
 * Поток: строка + `$link` → свои колонки → `INSERT INTO <table> (…) VALUES (…) RETURNING <pk>` → ключи: записанные
 * колонки и первичный ключ, по именам колонок сущности (ключам `COLUMNS`).
 *
 * Свои колонки — колонки модели этой таблицы из строки (`$columns`, их даёт `TableTreeBuilder` по
 * `ModelTree::columnTable()`) и колонки связи из `$link`, которые есть в сущности. Пишутся только присланные колонки:
 * неупомянутую база заполнит значением по умолчанию. Нет ни одной — `INSERT … DEFAULT VALUES`. Имя колонки в базе —
 * `Entity::column()`, SQL строит pg_builder, значения — именованные параметры, связанные `bindValue()` с типом по PHP
 * (как в SqlScanIterator). Выполненный запрос с параметрами и ключом пишется в `error_log()` (отладка).
 *
 * TODO: `prepare()` на каждый `push()`. Один запрос в конструкторе подготовить нельзя: набор колонок зависит от
 * строки — неприсланная колонка не упоминается, чтобы база поставила `DEFAULT` (`NULL` вместо неё — другое значение,
 * а `DEFAULT` нельзя передать параметром PDO: это ключевое слово, а не значение, и в выражениях вроде `COALESCE`
 * оно запрещено). Улучшения: кеш подготовленных запросов по набору колонок (следующие строки той же формы — только
 * `bindValue()` + `execute()`); для многострочного `INSERT` на PostgreSQL — колонки как объединение по строкам,
 * недостающие значения — `DEFAULT` в `VALUES` (pg_builder `SetToDefault`; SQLite `DEFAULT` в `VALUES` не понимает).
 *
 * Ограничения: первичный ключ — одна целочисленная колонка (`RETURNING` читается как int); ошибки базы (`NOT NULL`,
 * внешний ключ, уникальность) — исключения PDO как есть.
 */
final readonly class InsertTableWriter implements TableWriter
{
    /**
     * @param class-string<Entity> $entity
     * @param list<string>         $columns колонки модели ресурса, которые лежат в этой таблице
     */
    public function __construct(
        private PDO $pdo,
        private string $entity,
        private array $columns,
    ) {}

    #[\Override]
    public function push(array $row, array $link = []): array
    {
        $entity = $this->entity;
        /** @var array<string, mixed> $values */
        $values = [
            ...array_intersect_key($row, array_flip($this->columns)),
            ...array_intersect_key($link, $entity::COLUMNS),
        ];
        $primary = $entity::primaryColumn();

        $sql = $this->sql(names: array_map(static fn(string $name): string => $entity::column($name), array_keys($values)));
        $statement = $this->pdo->prepare($sql);
        foreach ($values as $name => $value) {
            $statement->bindValue(':' . $entity::column($name), $value, match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            });
        }
        $statement->execute();
        $id = (int) $statement->fetchColumn();
        $statement->closeCursor();

        error_log("{$sql}\n    " . (string) json_encode($values) . " → {$id}");

        return [...$values, $primary => $id];
    }

    /**
     * @param list<string> $names колонки таблицы в базе; параметр колонки — `:<колонка>`
     */
    private function sql(array $names): string
    {
        $entity = $this->entity;
        $insert = new Insert(new InsertTarget(new QualifiedName($entity::TABLE)));
        if ($names !== []) {
            $insert->cols->replace(array_map(static fn(string $name): SetTargetElement => new SetTargetElement($name), $names));
            $insert->values = new Values(new RowList([
                new RowExpression(array_map(static fn(string $name): NamedParameter => new NamedParameter($name), $names)),
            ]));
        }
        $insert->returning->replace([new TargetElement(new ColumnReference($entity::column($entity::primaryColumn())))]);

        return (string) preg_replace(pattern: '/\s+/', replacement: ' ', subject: $insert->dispatch(new SqlBuilderWalker()));
    }

    #[\Override]
    public function columns(): array
    {
        return $this->columns;
    }

    #[\Override]
    public function explain(): array
    {
        return ['node' => static::class, 'table' => $this->entity::TABLE, 'columns' => $this->columns];
    }
}
