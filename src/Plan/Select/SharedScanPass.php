<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Select;

use Ggs\JsonApiEngine\Plan\HashJoinNode;
use Ggs\JsonApiEngine\Plan\HashNestJoinNode;
use Ggs\JsonApiEngine\Plan\JoinNode;
use Ggs\JsonApiEngine\Plan\Pass;
use Ggs\JsonApiEngine\Plan\ResourceNode;
use Ggs\JsonApiEngine\Plan\SharedRowJoinNode;
use Ggs\JsonApiEngine\Plan\SharedScanNode;

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
    public function __invoke(ResourceNode|SharedScanNode|JoinNode $plan): ResourceNode|SharedScanNode|JoinNode
    {
        return $this->close(node: $plan, inRow: false);
    }

    /**
     * @param ResourceNode|SharedScanNode|JoinNode $node  корень поддерева
     * @param bool                                 $inRow узел уже внутри строки — владелец над ним не нужен
     */
    private function close(ResourceNode|SharedScanNode|JoinNode $node, bool $inRow): ResourceNode|SharedScanNode|JoinNode
    {
        // Ресурс внутри строки — таблица в FROM владельца; вне строки — запрос из одного ресурса
        if ($node instanceof ResourceNode) {
            return $inRow ? $node : new SharedScanNode(input: $node);
        }

        // Уже закрытый запрос не меняется
        if ($node instanceof SharedScanNode) {
            return $node;
        }

        // Входы SharedRowJoinNode — часть той же строки. У ветки outer остаётся там, где стоит сама ветка
        // (внутри строки — в строке), а inner — отдельный запрос и начинает с чистого листа
        $isRow = $node instanceof SharedRowJoinNode;

        $node = $node
            ->withOuter(outer: $this->close(node: $node->outer, inRow: $isRow || $inRow))
            ->withInner(inner: $this->close(node: $node->inner, inRow: $isRow));

        // 1:N внутри строки: outer нельзя читать наперёд — хешируется inner
        if ($inRow && $node instanceof HashNestJoinNode) {
            return new HashJoinNode(name: $node->name, relation: $node->relation, outer: $node->outer, inner: $node->inner);
        }

        // Верх строки: SharedRowJoinNode, над которым нет другого SharedRowJoinNode
        return $node instanceof SharedRowJoinNode && !$inRow
            ? new SharedScanNode(input: $node)
            : $node;
    }
}
