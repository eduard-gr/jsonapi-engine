<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor\Write;

use Ggs\JsonApiEngine\Executor\PushExecutor;
use Ggs\JsonApiEngine\Model\ModelTree;
use Ggs\JsonApiEngine\Parser\Write\KeepOperation;
use Ggs\JsonApiEngine\Parser\Write\UpdateOperation;
use Ggs\JsonApiEngine\Parser\Write\WriteOperation;
use Ggs\JsonApiEngine\Resource;
use LogicException;
use PDO;
use sad_spirit\pg_builder\Delete;
use sad_spirit\pg_builder\nodes\ColumnReference;
use sad_spirit\pg_builder\nodes\QualifiedName;
use sad_spirit\pg_builder\nodes\expressions\InExpression;
use sad_spirit\pg_builder\nodes\expressions\NamedParameter;
use sad_spirit\pg_builder\nodes\expressions\OperatorExpression;
use sad_spirit\pg_builder\nodes\lists\ExpressionList;
use sad_spirit\pg_builder\nodes\range\UpdateOrDeleteTarget;

/**
 * Полная замена связи по владению (PATCH): удаляет детей владельца, не названных в документе. Замена to-one через
 * `lid` или `null` — тот же случай без названных id: текущий ребёнок удаляется (новый вставит `WriteJoinWriter` выше).
 *
 * Поток: операция с поддеревом + `$link` → `input` → ключи владельца → если у связи `replace` — `DELETE FROM
 * <главная таблица ребёнка> WHERE <relation[1]> = :parent AND <pk> NOT IN (id названных детей)` (без названных — все
 * дети владельца) → те же ключи наверх. Связи у операции нет или она без `replace` (частичное изменение у этого
 * владельца) — только `input`. Названные дети — операции с `id`: `UpdateOperation` и `KeepOperation`.
 *
 * Собственных детей удалённого ребёнка удаляет `ON DELETE CASCADE` базы.
 */
final readonly class WriteReplaceWriter implements PushExecutor
{
    /**
     * @param array{0: string, 1: string} $relation       [колонка владельца, колонка ребёнка]
     * @param class-string<Resource>      $child_resource
     */
    public function __construct(
        private PDO $pdo,
        private string $name,
        private array $relation,
        private string $child_resource,
        private PushExecutor $input,
    ) {}

    #[\Override]
    public function push(WriteOperation $operation, array $link = []): array
    {
        $keys = $this->input->push($operation, $link);
        $relationship = $operation->relationship(name: $this->name);
        if ($relationship === null || !$relationship->replace) {
            return $keys;
        }
        $parent_key = $keys[$this->relation[0]]
            ?? throw new LogicException("No key {$this->relation[0]} for {$this->name}");

        $entity = ModelTree::of($this->child_resource)->rootEntity()
            ?? throw new LogicException("{$this->child_resource}: invalid model");
        $primary = $this->child_resource::primaryColumn();
        if (!isset($entity::COLUMNS[$this->relation[1]])) {
            throw new LogicException("{$entity}: link column {$this->relation[1]} is not in the main table");
        }
        $listed = [];
        foreach ($relationship->children as $child) {
            if ($child instanceof UpdateOperation || $child instanceof KeepOperation) {
                $listed[] = $child->identity->id;
            }
        }

        $delete = new Delete(new UpdateOrDeleteTarget(new QualifiedName($entity::TABLE)));
        $delete->where->and(new OperatorExpression(
            '=',
            new ColumnReference($entity::column($this->relation[1])),
            new NamedParameter('parent_key'),
        ));
        $parameters = ['parent_key' => (int) $parent_key];
        if ($listed !== []) {
            $delete->where->and(new InExpression(
                new ColumnReference($entity::column($primary)),
                new ExpressionList(array_map(
                    static fn(int $position): NamedParameter => new NamedParameter("listed_{$position}"),
                    array_keys($listed),
                )),
                true,
            ));
            foreach ($listed as $position => $id) {
                $parameters["listed_{$position}"] = $id;
            }
        }
        StatementRunner::run(pdo: $this->pdo, statement: $delete, parameters: $parameters);

        return $keys;
    }

    #[\Override]
    public function explain(): array
    {
        return [
            'node' => static::class,
            'name' => $this->name,
            'relation' => $this->relation,
            'child_resource' => $this->child_resource,
            'input' => $this->input->explain(),
        ];
    }
}
