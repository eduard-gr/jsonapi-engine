<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor\Write;

use Ggs\JsonApiEngine\Executor\Delete\DeleteResourceWriter;
use Ggs\JsonApiEngine\Executor\Insert\InsertOptionalTableWriter;
use Ggs\JsonApiEngine\Executor\Insert\InsertResourceWriter;
use Ggs\JsonApiEngine\Executor\Insert\InsertTableJoinWriter;
use Ggs\JsonApiEngine\Executor\Insert\InsertTableWriter;
use Ggs\JsonApiEngine\Executor\PushExecutor;
use Ggs\JsonApiEngine\Executor\TableWriter;
use Ggs\JsonApiEngine\Executor\Update\UpdateOptionalTableWriter;
use Ggs\JsonApiEngine\Executor\Update\UpdateResourceWriter;
use Ggs\JsonApiEngine\Executor\Update\UpdateTableJoinWriter;
use Ggs\JsonApiEngine\Executor\Update\UpdateTableWriter;
use Ggs\JsonApiEngine\Parser\Write\WriteOperation;
use Ggs\JsonApiEngine\Plan\Insert\InsertOptionalTableNode;
use Ggs\JsonApiEngine\Plan\Insert\InsertTableJoinNode;
use Ggs\JsonApiEngine\Plan\Insert\InsertTableNode;
use Ggs\JsonApiEngine\Plan\Update\UpdateOptionalTableNode;
use Ggs\JsonApiEngine\Plan\Update\UpdateTableJoinNode;
use Ggs\JsonApiEngine\Plan\Update\UpdateTableNode;
use Ggs\JsonApiEngine\Plan\Write\WriteJoinNode;
use Ggs\JsonApiEngine\Plan\Write\WriteOperationNode;
use Ggs\JsonApiEngine\Plan\Write\WriteReplaceNode;
use Ggs\JsonApiEngine\Plan\Write\WriteRootNode;
use LogicException;
use PDO;

/**
 * Собирает по плану записи (`Plan\Write\WritePlanner`) дерево исполнителей (`…Writer`). Пара к IteratorBuilder: тот
 * строит итераторы по плану SELECT.
 *
 * Поток: неизменяемое дерево плана → `WriteRootWriter` (`root()`) над деревом `PushExecutor` на одно выполнение (план
 * переиспользуемый). Чего нет в плане, даёт вызывающий: корни дерева операций и id родителя из вложенного маршрута.
 *
 * Узел плана и исполнитель соответствуют по основе имени: `WriteRootNode` → `WriteRootWriter`, `RouteParentNode` →
 * `RouteParentWriter` (с id из маршрута), `WriteJoinNode` → `WriteJoinWriter`, `WriteReplaceNode` →
 * `WriteReplaceWriter`, `WriteOperationNode` → `WriteOperationWriter`; ветви места — `InsertResourceNode` →
 * `InsertResourceWriter`, `UpdateResourceNode` → `UpdateResourceWriter`, `DeleteResourceNode` →
 * `DeleteResourceWriter`; уровень таблиц — `InsertTableJoinNode` → `InsertTableJoinWriter`,
 * `InsertOptionalTableNode` → `InsertOptionalTableWriter`, `InsertTableNode` → `InsertTableWriter`
 * (`insertTables()`), `UpdateTableJoinNode` → `UpdateTableJoinWriter`, `UpdateOptionalTableNode` →
 * `UpdateOptionalTableWriter`, `UpdateTableNode` → `UpdateTableWriter` (`updateTables()`).
 *
 * Сам сборщик состояния выполнения не хранит; исполнители, которые выполняют SQL, получают его PDO, проверки
 * существования — флаг `lock`.
 */
final readonly class WriterBuilder
{
    /**
     * @param bool $lock `FOR KEY SHARE` в проверках существования (PostgreSQL); false — SQLite
     */
    public function __construct(
        private PDO $pdo,
        private bool $lock,
    ) {}

    /**
     * Корень записи. Значения, которых нет в плане, даёт вызывающий: корни дерева операций и id родителя из маршрута.
     *
     * @param list<WriteOperation> $data     корни дерева операций
     * @param int|null             $route_id id родителя из вложенного маршрута; обязателен, если в плане есть `route`
     */
    public function root(WriteRootNode $plan, array $data, ?int $route_id = null): WriteRootWriter
    {
        if (($plan->route === null) !== ($route_id === null)) {
            throw new LogicException('route id must be given exactly when the plan has a route parent');
        }

        return new WriteRootWriter(
            root: $this->build(plan: $plan->input),
            data: $data,
            route: $plan->route === null ? null : new RouteParentWriter(
                pdo: $this->pdo,
                parent: $plan->route->parent,
                relation: $plan->route->relation,
                id: (int) $route_id,
                lock: $this->lock,
            ),
        );
    }

    /**
     * Дерево уровня ресурсов: связи, замены и развилки мест.
     */
    public function build(WriteJoinNode|WriteReplaceNode|WriteOperationNode $plan): PushExecutor
    {
        if ($plan instanceof WriteJoinNode) {
            return new WriteJoinWriter(
                name: $plan->name,
                relation: $plan->relation,
                parent: $this->build(plan: $plan->parent),
                child: $this->build(plan: $plan->child),
            );
        }
        if ($plan instanceof WriteReplaceNode) {
            return new WriteReplaceWriter(
                pdo: $this->pdo,
                name: $plan->name,
                relation: $plan->relation,
                child_resource: $plan->child_resource,
                input: $this->build(plan: $plan->input),
            );
        }

        return new WriteOperationWriter(
            resource: $plan->resource,
            add: $plan->add === null ? null : new InsertResourceWriter(
                resource: $plan->add->resource,
                input: $this->insertTables(plan: $plan->add->input),
            ),
            update: $plan->update === null ? null : new UpdateResourceWriter(
                resource: $plan->update->resource,
                input: $this->updateTables(plan: $plan->update->input),
            ),
            remove: $plan->remove === null ? null : new DeleteResourceWriter(
                pdo: $this->pdo,
                resource: $plan->remove->resource,
            ),
        );
    }

    /**
     * Поддерево таблиц вставки по плану `InsertPlanner::tables()`.
     */
    public function insertTables(InsertTableJoinNode|InsertTableNode|InsertOptionalTableNode $plan): TableWriter
    {
        if ($plan instanceof InsertTableJoinNode) {
            return new InsertTableJoinWriter(
                relation: $plan->relation,
                parent: $this->insertTables(plan: $plan->parent),
                child: $this->insertTables(plan: $plan->child),
            );
        }
        if ($plan instanceof InsertOptionalTableNode) {
            return new InsertOptionalTableWriter(
                input: $this->insertTables(plan: $plan->input),
            );
        }

        return new InsertTableWriter(
            pdo: $this->pdo,
            entity: $plan->entity,
            columns: $plan->columns,
        );
    }

    /**
     * Поддерево таблиц изменения по плану `UpdatePlanner::tables()`.
     */
    public function updateTables(UpdateTableJoinNode|UpdateTableNode|UpdateOptionalTableNode $plan): TableWriter
    {
        if ($plan instanceof UpdateTableJoinNode) {
            return new UpdateTableJoinWriter(
                relation: $plan->relation,
                main: $this->updateTables(plan: $plan->main),
                joined: $this->updateTables(plan: $plan->joined),
            );
        }
        if ($plan instanceof UpdateOptionalTableNode) {
            return new UpdateOptionalTableWriter(
                update: $this->updateTable(plan: $plan->update),
                insert: new InsertTableWriter(
                    pdo: $this->pdo,
                    entity: $plan->insert->entity,
                    columns: $plan->insert->columns,
                ),
            );
        }

        return $this->updateTable(plan: $plan);
    }

    private function updateTable(UpdateTableNode $plan): UpdateTableWriter
    {
        return new UpdateTableWriter(
            pdo: $this->pdo,
            entity: $plan->entity,
            columns: $plan->columns,
            key: $plan->key,
            returning: $plan->returning,
        );
    }
}
