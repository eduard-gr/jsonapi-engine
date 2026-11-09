<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Select;


/**
 * Над каждым запросом плана ставится его владелец SharedScanNode: над верхним SharedRowJoinNode строки
 * и над каждым ResourceNode вне строки (ресурс без 1-1 — запрос из одного ресурса). HashNestJoinNode
 * внутри строки становится HashJoinNode.
 *
 * Проход, а не правило: узлу нужен контекст сверху — внутри он чьей-то строки или нет.
 * Запускается после подъёма веток владельца: внутри строк остались SharedRowJoinNode, ResourceNode
 * и ветки 1-1 ресурсов. У такой ветки outer — часть той же строки, а inner — отдельный запрос
 * со своим владельцем.
 *
 * После прохода у каждого ResourceNode есть владелец над ним, а вне строк ResourceNode не остаётся.
 */
final class SharedScanPass implements Pass
{
    #[\Override]
    public function __invoke(ResourceNode|SharedScanNode|SqlScanNode|JoinNode $plan): ResourceNode|SharedScanNode|SqlScanNode|JoinNode
    {
        return $this->close(node: $plan, in_row: false);
    }

    /**
     * @param ResourceNode|SharedScanNode|SqlScanNode|JoinNode $node  корень поддерева
     * @param bool                                             $in_row узел уже внутри строки — владелец над ним не нужен
     */
    private function close(ResourceNode|SharedScanNode|SqlScanNode|JoinNode $node, bool $in_row): ResourceNode|SharedScanNode|SqlScanNode|JoinNode
    {
        // Ресурс внутри строки — таблица в FROM владельца; вне строки — запрос из одного ресурса
        if ($node instanceof ResourceNode) {
            return $in_row ? $node : new SharedScanNode(input: $node);
        }

        // Уже закрытый запрос не меняется; собранный (шаг sql) — тоже: SharedScanPass идёт раньше, но дерево
        // после sql тоже остаётся корректным
        if ($node instanceof SharedScanNode || $node instanceof SqlScanNode) {
            return $node;
        }

        // Входы SharedRowJoinNode — часть той же строки. У ветки outer остаётся там, где стоит сама ветка
        // (внутри строки — в строке), а inner — отдельный запрос и начинает с чистого листа
        $is_row = $node instanceof SharedRowJoinNode;

        $node = $node
            ->withOuter(outer: $this->close(node: $node->outer, in_row: $is_row || $in_row))
            ->withInner(inner: $this->close(node: $node->inner, in_row: $is_row));

        // 1:N внутри строки: outer нельзя читать наперёд — хешируется inner
        if ($in_row && $node instanceof HashNestJoinNode) {
            return new HashJoinNode(name: $node->name, relation: $node->relation, outer: $node->outer, inner: $node->inner);
        }

        // Верх строки: SharedRowJoinNode, над которым нет другого SharedRowJoinNode
        return $node instanceof SharedRowJoinNode && !$in_row
            ? new SharedScanNode(input: $node)
            : $node;
    }
}
