<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor\Update;

use Ggs\JsonApiEngine\Executor\TableWriter;
use Ggs\JsonApiEngine\Executor\Write\StatementRunner;
use Ggs\JsonApiEngine\Model\Entity;
use LogicException;
use PDO;
use sad_spirit\pg_builder\Select;
use sad_spirit\pg_builder\Update;
use sad_spirit\pg_builder\enums\LogicalOperator;
use sad_spirit\pg_builder\nodes\ColumnReference;
use sad_spirit\pg_builder\nodes\QualifiedName;
use sad_spirit\pg_builder\nodes\SetTargetElement;
use sad_spirit\pg_builder\nodes\SingleSetClause;
use sad_spirit\pg_builder\nodes\TargetElement;
use sad_spirit\pg_builder\nodes\expressions\IsDistinctFromExpression;
use sad_spirit\pg_builder\nodes\expressions\LogicalExpression;
use sad_spirit\pg_builder\nodes\expressions\NamedParameter;
use sad_spirit\pg_builder\nodes\expressions\OperatorExpression;
use sad_spirit\pg_builder\nodes\lists\SetClauseList;
use sad_spirit\pg_builder\nodes\lists\TargetList;
use sad_spirit\pg_builder\nodes\range\RelationReference;
use sad_spirit\pg_builder\nodes\range\UpdateOrDeleteTarget;

/**
 * Одна таблица модели при изменении. Строит `WriterBuilder` по `UpdateTableNode`. Про JSON:API ничего не знает.
 *
 * Поток: строка + `$link` → присланы колонки этой таблицы — `UPDATE <table> SET <колонки> WHERE <key> = :key AND
 * <колонки связи этой таблицы> AND (<колонка> IS DISTINCT FROM :<колонка> OR …) RETURNING <key>, <returning>`;
 * строки не вернулось или колонки не присланы — `SELECT <key>, <returning> … WHERE` то же без IS DISTINCT FROM →
 * ключи: присланные колонки и возвращённые; пусто — строки нет (или она чужая).
 *
 * Колонки связи этой таблицы — колонки `$link` из её колонок модели, кроме `key`: колонка связи с родителем
 * (`domains.certificate_id`), по ней чужой ребёнок не найдётся. NULL в присланной колонке пишется как значение;
 * IS DISTINCT FROM сравнивает NULL как значение, неизменённая строка не перезаписывается.
 */
final readonly class UpdateTableWriter implements TableWriter
{
    /**
     * @param class-string<Entity> $entity
     * @param list<string>         $columns   колонки модели ресурса в этой таблице
     * @param string               $key       колонка поиска строки: первичный ключ главной таблицы или колонка связи
     * @param list<string>         $returning колонки, которые нужны правым сторонам (кроме $key)
     */
    public function __construct(
        private PDO $pdo,
        private string $entity,
        private array $columns,
        private string $key,
        private array $returning = [],
    ) {}

    #[\Override]
    public function push(array $row, array $link = []): array
    {
        $entity = $this->entity;
        $key_value = $link[$this->key] ?? throw new LogicException("{$entity}: no value for key {$this->key}");
        /** @var array<string, mixed> $values */
        $values = array_diff_key(array_intersect_key($row, array_flip($this->columns)), [$this->key => true]);
        $conditions = array_diff_key(array_intersect_key($link, array_flip($this->columns)), [$this->key => true]);
        $returned = array_values(array_unique([$this->key, ...$this->returning]));
        $targets = array_map(
            static fn(string $column): TargetElement => new TargetElement(
                new ColumnReference($entity::column($column)),
            ),
            $returned,
        );

        $found = [];
        if ($values !== []) {
            $update = new Update(
                new UpdateOrDeleteTarget(new QualifiedName($entity::TABLE)),
                new SetClauseList(array_map(
                    static fn(string $column): SingleSetClause => new SingleSetClause(
                        new SetTargetElement($entity::column($column)),
                        new NamedParameter($column),
                    ),
                    array_keys($values),
                )),
            );
            $parameters = [
                ...$values,
                'key' => $key_value,
                ...$this->where(statement: $update, conditions: $conditions),
            ];
            // Только если хоть одно присланное значение отличается от записанного; NULL сравнивается как значение
            $update->where->and(new LogicalExpression(
                array_map(
                    static fn(string $column): IsDistinctFromExpression => new IsDistinctFromExpression(
                        new ColumnReference($entity::column($column)),
                        new NamedParameter($column),
                    ),
                    array_keys($values),
                ),
                LogicalOperator::OR,
            ));
            $update->returning->replace($targets);
            $found = StatementRunner::run(pdo: $this->pdo, statement: $update, parameters: $parameters);
        }
        // Колонки не присланы или значения те же — строку только ищем: нужны её ключи и сам факт, что она есть
        if ($found === []) {
            $select = new Select(new TargetList(array_map(
                static fn(TargetElement $target): TargetElement => clone $target,
                $targets,
            )));
            $select->from[] = new RelationReference(new QualifiedName($entity::TABLE));
            $found = StatementRunner::run(
                pdo: $this->pdo,
                statement: $select,
                parameters: ['key' => $key_value, ...$this->where(statement: $select, conditions: $conditions)],
            );
        }

        return $found === [] ? [] : [...$values, ...$found[0]];
    }

    /**
     * Условие поиска строки: `key = :key` и колонки связи с родителем; значение `:key` подставляет вызывающий.
     *
     * @param array<string, mixed> $conditions колонка связи → значение
     * @return array<string, mixed> параметры колонок связи (`link_<колонка>`)
     */
    private function where(Update|Select $statement, array $conditions): array
    {
        $entity = $this->entity;
        $statement->where->and(new OperatorExpression(
            '=',
            new ColumnReference($entity::column($this->key)),
            new NamedParameter('key'),
        ));
        $parameters = [];
        foreach ($conditions as $column => $value) {
            $statement->where->and(new OperatorExpression(
                '=',
                new ColumnReference($entity::column($column)),
                new NamedParameter("link_{$column}"),
            ));
            $parameters["link_{$column}"] = $value;
        }

        return $parameters;
    }

    #[\Override]
    public function columns(): array
    {
        return $this->columns;
    }

    #[\Override]
    public function explain(): array
    {
        return [
            'node' => static::class,
            'table' => $this->entity::TABLE,
            'key' => $this->key,
            'columns' => $this->columns,
            'returning' => $this->returning,
        ];
    }
}
