<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Select;

use Ggs\JsonApiEngine\Model\ModelTree;


/**
 * Над каждым запросом плана ставится его владелец EntityScanNodeDraft: над верхним EntitySharedJoinNode строки
 * и над каждым ResourceNode вне строки (ресурс без 1-1 — запрос из одного ресурса). HashNestJoinNode
 * внутри строки становится HashJoinNode.
 *
 * Проход, а не правило: узлу нужен контекст сверху — внутри он чьей-то строки или нет.
 * Запускается после подъёма веток владельца: внутри строк остались EntitySharedJoinNode, ResourceNode
 * и ветки 1-1 ресурсов. У такой ветки outer — часть той же строки, а inner — отдельный запрос
 * со своим владельцем.
 *
 * Ресурс над чужим источником (`Model\ForeignDataSource`) владельца-запроса не получает: над ним сразу ставится
 * `ForeignDataScanNode` — отдельный лист без SQL. Связь 1-1 с чужим источником, оставшаяся внутри строки
 * (`HashOneJoinNode` у 1-1 ресурса), — `LogicException`: не поддержано.
 *
 * После прохода у каждого ResourceNode есть владелец над ним, а вне строк ResourceNode не остаётся.
 */
final class EntityScanNodeDraftPass implements PassInterface
{
    #[\Override]
    public function __invoke(ResourceNode|EntityScanNodeDraft|EntityScanNode|ForeignDataScanNode|JoinNode $plan): ResourceNode|EntityScanNodeDraft|EntityScanNode|ForeignDataScanNode|JoinNode
    {
        return $this->close(node: $plan, in_row: false);
    }

    /**
     * @param ResourceNode|EntityScanNodeDraft|EntityScanNode|ForeignDataScanNode|JoinNode $node  корень поддерева
     * @param bool                                             $in_row узел уже внутри строки — владелец над ним не нужен
     */
    private function close(ResourceNode|EntityScanNodeDraft|EntityScanNode|ForeignDataScanNode|JoinNode $node, bool $in_row): ResourceNode|EntityScanNodeDraft|EntityScanNode|ForeignDataScanNode|JoinNode
    {
        // Ресурс над чужим источником — всегда отдельный лист со своим узлом: SQL у него нет. Внутри строки его быть
        // не может: связь 1-1 с ним выносит из строки шаг separate
        if ($node instanceof ResourceNode && ModelTree::of($node->resource)->isForeignData()) {
            if ($in_row) {
                throw new \LogicException(message: 'Foreign data source inside a shared row: ' . $node->resource . ', run the separate step first');
            }

            return new ForeignDataScanNode(input: $node);
        }

        // Ресурс внутри строки — таблица в FROM владельца; вне строки — запрос из одного ресурса
        if ($node instanceof ResourceNode) {
            return $in_row ? $node : new EntityScanNodeDraft(input: $node);
        }

        // Уже закрытый запрос не меняется; собранный (шаг sql) — тоже: EntityScanNodeDraftPass идёт раньше, но дерево
        // после sql тоже остаётся корректным
        if ($node instanceof EntityScanNodeDraft || $node instanceof EntityScanNode || $node instanceof ForeignDataScanNode) {
            return $node;
        }

        // Входы EntitySharedJoinNode — часть той же строки. У ветки outer остаётся там, где стоит сама ветка
        // (внутри строки — в строке), а inner — отдельный запрос и начинает с чистого листа
        $is_row = $node instanceof EntitySharedJoinNode;

        $node = $node
            ->withOuter(outer: $this->close(node: $node->outer, in_row: $is_row || $in_row))
            ->withInner(inner: $this->close(node: $node->inner, in_row: $is_row));

        // Связь 1-1 с чужим источником у ресурса внутри строки: HashOneJoinReader читает outer целиком, а поток
        // строки наперёд читать нельзя
        if ($in_row && $node instanceof HashOneJoinNode) {
            throw new \LogicException(message: "One-to-one relationship '{$node->name}' with a foreign data source inside a shared row is not supported");
        }

        // 1:N внутри строки: outer нельзя читать наперёд — хешируется inner
        if ($in_row && $node instanceof HashNestJoinNode) {
            return new HashJoinNode(name: $node->name, relation: $node->relation, outer: $node->outer, inner: $node->inner);
        }

        // Верх строки: EntitySharedJoinNode, над которым нет другого EntitySharedJoinNode
        return $node instanceof EntitySharedJoinNode && !$in_row
            ? new EntityScanNodeDraft(input: $node)
            : $node;
    }
}
