<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Select;

use Ggs\JsonApiEngine\Plan\HashNestJoinNode;
use Ggs\JsonApiEngine\Plan\JoinNode;
use Ggs\JsonApiEngine\Plan\MergeNestJoinNode;
use Ggs\JsonApiEngine\Plan\PreloadNode;
use Ggs\JsonApiEngine\Plan\ResourceNode;
use Ggs\JsonApiEngine\Plan\Rule;
use Ggs\JsonApiEngine\Plan\SharedScanNode;

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
    public function apply(ResourceNode|SharedScanNode|JoinNode $node): ResourceNode|SharedScanNode|JoinNode|null
    {
        if (!$node instanceof PreloadNode
            || !($node->outer instanceof HashNestJoinNode || $node->outer instanceof MergeNestJoinNode)) {
            return null;
        }

        $nest = $node->outer;                                                    // NestJoin(A, B)

        return $nest->withOuter(outer: $node->withOuter(outer: $nest->outer));    // NestJoin(Preload(A, C), B)
    }
}
