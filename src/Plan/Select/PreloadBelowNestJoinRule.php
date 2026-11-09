<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Select;


/**
 * PreloadNode опускается под nest join, ближе к строке:
 *   Preload(NestJoin(A, B), C) → NestJoin(Preload(A, C), B)
 *
 * Nest join отдаёт наружу поток своего outer, поэтому preload по-прежнему видит записи A.
 * Перестановка меняет только порядок выполнения: справочник загружается до nest join.
 */
final class PreloadBelowNestJoinRule extends Rule
{
    #[\Override]
    public function apply(ResourceNode|SharedScanNode|SqlScanNode|JoinNode $node): ResourceNode|SharedScanNode|SqlScanNode|JoinNode|null
    {
        if (!$node instanceof PreloadNode
            || !($node->outer instanceof HashNestJoinNode || $node->outer instanceof MergeNestJoinNode)) {
            return null;
        }

        $nest_join = $node->outer;                                                    // NestJoin(A, B)

        return $nest_join->withOuter(outer: $node->withOuter(outer: $nest_join->outer));    // NestJoin(Preload(A, C), B)
    }
}
