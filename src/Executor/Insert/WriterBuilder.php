<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor\Insert;

use Ggs\JsonApiEngine\Executor\PushExecutor;
use Ggs\JsonApiEngine\Plan\Insert\InsertJoinNode;
use Ggs\JsonApiEngine\Plan\Insert\InsertOptionalTableNode;
use Ggs\JsonApiEngine\Plan\Insert\InsertResourceNode;
use Ggs\JsonApiEngine\Plan\Insert\InsertTableJoinNode;
use Ggs\JsonApiEngine\Plan\Insert\InsertTableNode;
use PDO;

/**
 * Собирает по плану вставки (`Plan\Insert\InsertPlanner`) дерево исполнителей (`…Writer`). Пара к IteratorBuilder:
 * тот строит итераторы по плану SELECT.
 *
 * Поток: неизменяемое дерево плана вставки → дерево `PushExecutor` или сразу `InsertRootWriter` над ним.
 *
 * Узел плана и исполнитель соответствуют по основе имени: `InsertJoinNode` → `InsertJoinWriter`, `InsertResourceNode` →
 * `InsertResourceWriter`, `InsertTableJoinNode` → `InsertTableJoinWriter`, `InsertOptionalTableNode` →
 * `InsertOptionalTableWriter`, `InsertTableNode` → `InsertTableWriter` (с PDO сборщика). Чего нет в плане, даёт вызывающий: корни документа и колонки связи из маршрута (`root()`; непустые
 * колонки связи — узел `InsertRouteLinkWriter` над деревом).
 *
 * Сам сборщик состояния выполнения не хранит; дерево исполнителей строится на одно выполнение, план — переиспользуемый.
 */
final readonly class WriterBuilder
{
    public function __construct(
        private PDO $pdo,
    ) {}

    /**
     * @param list<array>          $data  ресурсы `data` документа из `DocumentParser::parse()`
     * @param array<string, mixed> $link  колонки связи из маршрута вложенного ресурса (`InsertPlanner::routeLink()`)
     */
    public function root(InsertJoinNode|InsertResourceNode $plan, array $data, array $link = []): InsertRootWriter
    {
        $tree = $this->build(plan: $plan);
        if ($link !== []) {
            // Колонки связи из маршрута — значение, а не форма плана: узел есть только в дереве исполнителей
            $tree = new InsertRouteLinkWriter(
                link: $link,
                input: $tree,
            );
        }

        return new InsertRootWriter(
            root: $tree,
            data: $data,
        );
    }

    public function build(InsertJoinNode|InsertResourceNode $plan): PushExecutor
    {
        return $plan instanceof InsertJoinNode
            ? new InsertJoinWriter(
                name: $plan->name,
                relation: $plan->relation,
                parent: $this->build(plan: $plan->parent),
                child: $this->build(plan: $plan->child),
            )
            : new InsertResourceWriter(resource: $plan->resource, input: $this->table(plan: $plan->input));
    }

    public function table(InsertTableJoinNode|InsertTableNode|InsertOptionalTableNode $plan): TableWriter
    {
        return match (true) {
            $plan instanceof InsertTableJoinNode => new InsertTableJoinWriter(
                relation: $plan->relation,
                parent: $this->table(plan: $plan->parent),
                child: $this->table(plan: $plan->child),
            ),
            $plan instanceof InsertOptionalTableNode => new InsertOptionalTableWriter(
                input: $this->table(plan: $plan->input),
            ),
            default => new InsertTableWriter(
                pdo: $this->pdo,
                entity: $plan->entity,
                columns: $plan->columns,
            ),
        };
    }
}
