<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor;

use Ggs\JsonApiEngine\Plan\Insert\InsertJoinNode;
use Ggs\JsonApiEngine\Plan\Insert\InsertResourceNode;
use Ggs\JsonApiEngine\Plan\Insert\InsertTableJoinNode;
use Ggs\JsonApiEngine\Plan\Insert\InsertTableNode;
use PDO;

/**
 * Собирает по плану вставки (`Plan\Insert\InsertPlanner`) дерево исполнителей (`…Writer`). Пара к ExecutorBuilder:
 * тот строит итераторы по плану SELECT.
 *
 * Поток: неизменяемое дерево плана вставки → дерево `ExecutorWriter` или сразу `InsertDocument` над ним.
 *
 * Узел плана и исполнитель соответствуют по основе имени: `InsertJoinNode` → `InsertJoinWriter`, `InsertResourceNode` →
 * `InsertResourceWriter`, `InsertTableJoinNode` → `InsertTableJoinWriter`, `InsertTableNode` → `InsertTableWriter`
 * (с PDO сборщика). Чего нет в плане, даёт вызывающий: корни документа и колонки связи из маршрута (`document()`).
 *
 * Сам сборщик состояния выполнения не хранит; дерево исполнителей строится на одно выполнение, план — переиспользуемый.
 */
final readonly class WriterBuilder
{
    public function __construct(
        private PDO $pdo,
    ) {}

    /**
     * @param list<array>          $roots корни из `DocumentParser::parse()`
     * @param array<string, mixed> $link  колонки связи из маршрута вложенного ресурса (`InsertPlanner::routeLink()`)
     */
    public function document(InsertJoinNode|InsertResourceNode $plan, array $roots, array $link = []): InsertDocument
    {
        return new InsertDocument(root: $this->build(plan: $plan), roots: $roots, link: $link);
    }

    public function build(InsertJoinNode|InsertResourceNode $plan): ExecutorWriter
    {
        return $plan instanceof InsertJoinNode
            ? new InsertJoinWriter(
                name: $plan->name,
                relation: $plan->relation,
                first: $this->build(plan: $plan->first),
                then: $this->build(plan: $plan->then),
            )
            : new InsertResourceWriter(resource: $plan->resource, input: $this->table(plan: $plan->input));
    }

    public function table(InsertTableJoinNode|InsertTableNode $plan): TableWriter
    {
        return $plan instanceof InsertTableJoinNode
            ? new InsertTableJoinWriter(
                relation: $plan->relation,
                first: $this->table(plan: $plan->first),
                then: $this->table(plan: $plan->then),
                optional: $plan->optional,
            )
            : new InsertTableWriter(pdo: $this->pdo, entity: $plan->entity, columns: $plan->columns);
    }
}
