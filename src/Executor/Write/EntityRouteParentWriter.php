<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor\Write;

use Ggs\JsonApiEngine\Model\ModelTree;
use Ggs\JsonApiEngine\Resource;
use LogicException;
use PDO;
use sad_spirit\pg_builder\Select;
use sad_spirit\pg_builder\enums\LockingStrength;
use sad_spirit\pg_builder\nodes\ColumnReference;
use sad_spirit\pg_builder\nodes\LockingElement;
use sad_spirit\pg_builder\nodes\QualifiedName;
use sad_spirit\pg_builder\nodes\TargetElement;
use sad_spirit\pg_builder\nodes\expressions\NamedParameter;
use sad_spirit\pg_builder\nodes\expressions\OperatorExpression;
use sad_spirit\pg_builder\nodes\lists\TargetList;
use sad_spirit\pg_builder\nodes\range\RelationReference;

/**
 * Родитель из вложенного маршрута (`POST /certificates/{id}/domains`): проверка, что он есть, и колонки связи для
 * корней. Строит `WriterBuilder::getRoot()` по `EntityRouteParentNode` и id из маршрута.
 *
 * Поток: id из маршрута → `SELECT <pk> FROM <главная таблица родителя> WHERE <pk> = :id` [`FOR KEY SHARE`] → нет —
 * `WriteNotFoundException` (404); есть — `[relation[1] => id]`.
 *
 * Проверка идёт через главную таблицу модели: если это view или таблица под RLS, невидимый пользователю родитель —
 * тоже 404, а не вставка к чужому (внешний ключ базы RLS не видит). `lock` = `FOR KEY SHARE` (PostgreSQL): родителя не
 * удалят между проверкой и вставкой; ту же блокировку берёт сам внешний ключ при вставке. SQLite такой конструкции не
 * знает — `lock` выключается параметром.
 *
 * Тем же запросом позже лягут проверки целей связей по внешнему ключу (пока не делаются: несуществующая цель — ошибка
 * внешнего ключа базы).
 */
final readonly class EntityRouteParentWriter
{
    /**
     * @param class-string<Resource>      $parent
     * @param array{0: string, 1: string} $relation [первичный ключ родителя, колонка связи ребёнка]
     */
    public function __construct(
        private PDO $pdo,
        private string $parent,
        private array $relation,
        private int $id,
        private bool $lock,
    ) {}

    /**
     * @return array<string, int>
     * @throws WriteNotFoundException
     */
    public function link(): array
    {
        $entity = ModelTree::of($this->parent)->getRootEntity()
            ?? throw new LogicException("{$this->parent}: invalid model");
        $primary = $entity::getPrimaryKeyColumnName();
        $select = new Select(new TargetList([new TargetElement(new ColumnReference($entity::getColumn($primary)))]));
        $select->from[] = new RelationReference(new QualifiedName($entity::TABLE));
        $select->where->and(new OperatorExpression(
            '=',
            new ColumnReference($entity::getColumn($primary)),
            new NamedParameter('id'),
        ));
        if ($this->lock) {
            $select->locking[] = new LockingElement(LockingStrength::KEY_SHARE);
        }
        if (StatementRunner::run(pdo: $this->pdo, statement: $select, parameters: ['id' => $this->id]) === []) {
            throw new WriteNotFoundException(
                type: $this->parent::getType(),
                id: $this->id,
                detail: "{$this->parent::getType()} {$this->id} not found",
            );
        }

        return [$this->relation[1] => $this->id];
    }

    /**
     * @return array<string, mixed>
     */
    public function explain(): array
    {
        return [
            'node' => static::class,
            'parent' => $this->parent,
            'relation' => $this->relation,
            'id' => $this->id,
            'lock' => $this->lock,
        ];
    }
}
