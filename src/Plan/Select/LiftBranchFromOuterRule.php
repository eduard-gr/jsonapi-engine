<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Select;


/**
 * Ветка в outer общей строки поднимается над ней:
 *   EntitySharedJoin(Branch(A, C), B) → Branch(EntitySharedJoin(A, B), C)
 *
 * Так 1-1 оказываются под всеми ветками, даже если в дереве запроса ветка объявлена раньше (provider
 * до detail). Ветка остаётся привязана к тому же потоку A, просто к нему сначала присоединяется B, поэтому
 * ключ ветки по-прежнему берётся у записей A и связь пишется им.
 *
 * Ветки из inner (ветки 1-1 ресурса) не поднимаются: их outer — другой ресурс (B), и после подъёма
 * ключ брался бы у A. Они остаются внутри строки и становятся HashJoinNode в EntityScanNodeDraftPass.
 */
final class LiftBranchFromOuterRule extends Rule
{
    #[\Override]
    public function apply(ResourceNode|EntityScanNodeDraft|EntityScanNode|ForeignDataScanNode|JoinNode $node): ResourceNode|EntityScanNodeDraft|EntityScanNode|ForeignDataScanNode|JoinNode|null
    {
        // Ветка — любое соединение, кроме EntitySharedJoinNode: её inner читается отдельным запросом
        if (!$node instanceof EntitySharedJoinNode || !$node->outer instanceof JoinNode || $node->outer instanceof EntitySharedJoinNode) {
            return null;
        }

        $branch = $node->outer;                                                      // Branch(A, C)

        return $branch->withOuter(outer: $node->withOuter(outer: $branch->outer));    // Branch(EntitySharedJoin(A, B), C)
    }
}
