<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Select;

use Ggs\JsonApiEngine\Model\ModelTree;

/**
 * Связь 1-1, у которой хотя бы одна сторона читается из чужого источника, выходит из общей строки:
 *   EntitySharedJoin(A, B) → HashOneJoin(A, B), если A или B — над `Model\ForeignDataSource`
 *
 * Общая строка — это один SQL-запрос, а у чужого источника SQL нет: соединить его с сущностью может только
 * соединение между потоками. Сторона — ресурс, чей поток выходит из поддерева: спуск по outer до `ResourceNode`.
 *
 * Запускается первым (шаг `separate`), до подъёма веток: дальше `HashOneJoinNode` — обычная ветка, её выносит над
 * строкой `LiftBranchFromOuterRule`. Ветка, оставшаяся внутри строки (связь 1-1 ресурса сущности с чужим
 * источником), не поддержана — `EntityScanNodeDraftPass` бросает `LogicException`.
 */
final class SeparateForeignDataRule extends Rule
{
    #[\Override]
    public function apply(ResourceNode|EntityScanNodeDraft|EntityScanNode|ForeignDataScanNode|JoinNode $node): ResourceNode|EntityScanNodeDraft|EntityScanNode|ForeignDataScanNode|JoinNode|null
    {
        if (!$node instanceof EntitySharedJoinNode) {
            return null;
        }
        if (!self::isForeignData(node: $node->outer) && !self::isForeignData(node: $node->inner)) {
            return null;
        }

        return new HashOneJoinNode(
            name: $node->name,
            relation: $node->relation,
            outer: $node->outer,
            inner: $node->inner,
        );
    }

    /**
     * Читается ли из чужого источника ресурс, чей поток выходит из поддерева.
     */
    private static function isForeignData(ResourceNode|EntityScanNodeDraft|EntityScanNode|ForeignDataScanNode|JoinNode $node): bool
    {
        while ($node instanceof JoinNode) {
            $node = $node->outer;
        }

        $resource = $node instanceof ResourceNode ? $node->resource : $node->getRootResourceNode()->resource;

        return ModelTree::of($resource)->isForeignData();
    }
}
