<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Select;

/**
 * Соединение по связи из дерева запроса: два входа и relation.
 * Наружу выходит поток outer, inner присоединяется к нему.
 *
 * Абстрактный: смысл соединения задаёт конкретный класс-наследник, сами наследники пустые:
 * EntitySharedJoinNode, PreloadNode, HashNestJoinNode, HashJoinNode, MergeNestJoinNode.
 *
 * Иммутабелен: копия с другим входом — через withOuter()/withInner(), clone with (PHP 8.5).
 * Если новый вход — тот же объект, возвращается сам узел: нетронутые поддеревья не копируются,
 * старое и новое дерево делят их между собой.
 */
abstract class JoinNode
{
    /**
     * @param string                                           $name     имя связи из дерева запроса ('delivery', 'domains')
     * @param array{string, string}                $relation [колонка родителя, колонка ребёнка] — ON для
     *                                                                   EntitySharedJoinNode и ключи для остальных соединений
     * @param ResourceNode|EntityScanNodeDraft|EntityScanNode|ForeignDataScanNode|JoinNode $outer    поток, который выходит наружу
     * @param ResourceNode|EntityScanNodeDraft|EntityScanNode|ForeignDataScanNode|JoinNode $inner    присоединяемое
     */
    public function __construct(
        public readonly string $name,
        public readonly array $relation,
        public readonly ResourceNode|EntityScanNodeDraft|EntityScanNode|ForeignDataScanNode|JoinNode $outer,
        public readonly ResourceNode|EntityScanNodeDraft|EntityScanNode|ForeignDataScanNode|JoinNode $inner,
    ) {}

    /**
     * Копия с другим outer; тот же outer — тот же узел.
     * static: у HashNestJoinNode вернётся HashNestJoinNode, а не JoinNode.
     *
     * @param ResourceNode|EntityScanNodeDraft|EntityScanNode|ForeignDataScanNode|JoinNode $outer новый outer
     */
    public function withOuter(ResourceNode|EntityScanNodeDraft|EntityScanNode|ForeignDataScanNode|JoinNode $outer): static
    {
        return $outer === $this->outer
            ? $this
            : clone(object: $this, withProperties: ['outer' => $outer]);
    }

    /**
     * Копия с другим inner; тот же inner — тот же узел.
     *
     * @param ResourceNode|EntityScanNodeDraft|EntityScanNode|ForeignDataScanNode|JoinNode $inner новый inner
     */
    public function withInner(ResourceNode|EntityScanNodeDraft|EntityScanNode|ForeignDataScanNode|JoinNode $inner): static
    {
        return $inner === $this->inner
            ? $this
            : clone(object: $this, withProperties: ['inner' => $inner]);
    }
}
