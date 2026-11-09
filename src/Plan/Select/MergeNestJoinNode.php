<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Select;

/**
 * ONE_TO_MANY, ветка: то же, что HashNestJoinNode, но слиянием — обе стороны упорядочены по ключу связи.
 *
 * Из дерева запроса напрямую не строится: его выбирает HashToMergeRule, когда владельцы потоков
 * обеих сторон могут принять порядок (order). ORDER BY по нему строит SharedScanNode::withSelect().
 */
final class MergeNestJoinNode extends JoinNode {}
