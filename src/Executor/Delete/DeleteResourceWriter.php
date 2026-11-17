<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor\Delete;

use Ggs\JsonApiEngine\Executor\PushExecutor;
use Ggs\JsonApiEngine\Executor\Write\StatementRunner;
use Ggs\JsonApiEngine\Model\Entity;
use Ggs\JsonApiEngine\Model\ModelTree;
use Ggs\JsonApiEngine\Parser\Write\DeleteOperation;
use Ggs\JsonApiEngine\Parser\Write\WriteOperation;
use Ggs\JsonApiEngine\Resource;
use LogicException;
use PDO;
use sad_spirit\pg_builder\Delete;
use sad_spirit\pg_builder\nodes\ColumnReference;
use sad_spirit\pg_builder\nodes\QualifiedName;
use sad_spirit\pg_builder\nodes\TargetElement;
use sad_spirit\pg_builder\nodes\expressions\NamedParameter;
use sad_spirit\pg_builder\nodes\expressions\OperatorExpression;
use sad_spirit\pg_builder\nodes\range\UpdateOrDeleteTarget;

/**
 * Удаление ресурса: строка главной таблицы модели — `DELETE FROM <главная> WHERE <pk> = :key_id AND <колонки связи>
 * RETURNING <pk>`. Исполняет `DeleteOperation`: элемент связи с `meta.op: remove` и корень `DELETE /…/{id}`. Строит
 * `WriterBuilder` по `DeleteResourceNode`.
 *
 * Потомков удаляет база, а не движок: строки присоединённых таблиц, которые ссылаются на главную
 * (`certificate_details`, а через неё `certificate_delivery_meta`), детей по владению (`domains` → `domain_logs`) и
 * таблицы без ресурса (`domain_configs`) — `ON DELETE CASCADE`. Без каскада — ошибка внешнего ключа базы как
 * есть. Присоединённая таблица, на которую ссылается главная (`vouchers.vendor_id → vendors`), остаётся: её строку
 * могут разделять другие. `Deletable` проверяется только у ресурса, которого удаляет документ; что уходит вместе с ним,
 * задаёт схема базы.
 *
 * Поток: `DeleteOperation` (`identity->id`) + `$link` → SQL → `[pk => id]`; пусто — строки нет. Другая операция —
 * LogicException.
 */
final readonly class DeleteResourceWriter implements PushExecutor
{
    /**
     * @param class-string<Resource> $resource
     */
    public function __construct(
        private PDO $pdo,
        private string $resource,
    ) {}

    #[\Override]
    public function push(WriteOperation $operation, array $link = []): array
    {
        if (!$operation instanceof DeleteOperation) {
            throw new LogicException("{$this->resource}: delete needs DeleteOperation, got " . $operation::class);
        }
        $entity = $this->entity();
        $primary = $this->resource::primaryColumn();
        $statement = new Delete(new UpdateOrDeleteTarget(new QualifiedName($entity::TABLE)));
        $statement->returning->replace([new TargetElement(new ColumnReference($entity::column($primary)))]);
        $statement->where->and(new OperatorExpression(
            '=',
            new ColumnReference($entity::column($primary)),
            new NamedParameter('key_id'),
        ));
        $parameters = ['key_id' => $operation->identity->id];
        // Колонки связи от родителя — условие принадлежности: чужой ребёнок не найдётся
        foreach ($link as $column => $value) {
            if (!isset($entity::COLUMNS[$column])) {
                throw new LogicException("{$entity}: link column {$column} is not in the main table");
            }
            $statement->where->and(new OperatorExpression(
                '=',
                new ColumnReference($entity::column($column)),
                new NamedParameter("link_{$column}"),
            ));
            $parameters["link_{$column}"] = $value;
        }

        return StatementRunner::run(pdo: $this->pdo, statement: $statement, parameters: $parameters) === []
            ? []
            : [$primary => $operation->identity->id];
    }

    /**
     * Главная (крайняя левая) таблица модели ресурса: по ней удаляется строка ресурса.
     *
     * @return class-string<Entity>
     */
    private function entity(): string
    {
        return ModelTree::of($this->resource)->rootEntity()
            ?? throw new LogicException("{$this->resource}: invalid model");
    }

    #[\Override]
    public function explain(): array
    {
        return ['node' => static::class, 'resource' => $this->resource, 'table' => $this->entity()::TABLE];
    }
}
