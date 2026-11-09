<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Select;


/**
 * Ветка в outer общей строки поднимается над ней:
 *   SharedRowJoin(Branch(A, C), B) → Branch(SharedRowJoin(A, B), C)
 *
 * Так 1-1 оказываются под всеми ветками, даже если в дереве запроса ветка объявлена раньше (provider
 * до detail). Ветка остаётся привязана к тому же потоку A, просто к нему сначала присоединяется B, поэтому
 * ключ ветки по-прежнему берётся у записей A и связь пишется им.
 *
 * Ветки из inner (ветки 1-1 ресурса) не поднимаются: их outer — другой ресурс (B), и после подъёма
 * ключ брался бы у A. Они остаются внутри строки и становятся HashJoinNode в SharedScanPass.
 */
final class LiftBranchFromOuterRule extends Rule
{
    #[\Override]
    public function apply(ResourceNode|SharedScanNode|SqlScanNode|JoinNode $node): ResourceNode|SharedScanNode|SqlScanNode|JoinNode|null
    {
        // Ветка — любое соединение, кроме SharedRowJoinNode: её inner читается отдельным запросом
        if (!$node instanceof SharedRowJoinNode || !$node->outer instanceof JoinNode || $node->outer instanceof SharedRowJoinNode) {
            return null;
        }

        $branch = $node->outer;                                                      // Branch(A, C)

        return $branch->withOuter(outer: $node->withOuter(outer: $branch->outer));    // Branch(SharedRowJoin(A, B), C)
    }
}
