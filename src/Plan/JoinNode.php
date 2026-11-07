<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan;

/**
 * Соединение по связи из дерева запроса: два входа и relation.
 * Наружу выходит поток outer, inner присоединяется к нему.
 *
 * Абстрактный: смысл соединения задаёт конкретный класс-наследник, сами наследники пустые:
 * SharedRowJoinNode, PreloadNode, HashNestJoinNode, HashJoinNode, MergeNestJoinNode.
 *
 * Иммутабелен: копия с другим входом — через withOuter()/withInner(), clone with (PHP 8.5).
 * Если новый вход — тот же объект, возвращается сам узел: нетронутые поддеревья не копируются,
 * старое и новое дерево делят их между собой.
 */
abstract class JoinNode
{
    /**
     * @param string                               $name     имя связи из дерева запроса ('delivery', 'domains')
     * @param array{string, string}                $relation [колонка родителя, колонка ребёнка] — ON для
     *                                                       SharedRowJoinNode и ключи для остальных соединений
     * @param ResourceNode|SharedScanNode|JoinNode $outer    поток, который выходит наружу
     * @param ResourceNode|SharedScanNode|JoinNode $inner    присоединяемое
     */
    public function __construct(
        public readonly string $name,
        public readonly array $relation,
        public readonly ResourceNode|SharedScanNode|JoinNode $outer,
        public readonly ResourceNode|SharedScanNode|JoinNode $inner,
    ) {}

    /**
     * Копия с другим outer; тот же outer — тот же узел.
     * static: у HashNestJoinNode вернётся HashNestJoinNode, а не JoinNode.
     *
     * @param ResourceNode|SharedScanNode|JoinNode $outer новый outer
     */
    public function withOuter(ResourceNode|SharedScanNode|JoinNode $outer): static
    {
        return $outer === $this->outer
            ? $this
            : clone(object: $this, withProperties: ['outer' => $outer]);
    }

    /**
     * Копия с другим inner; тот же inner — тот же узел.
     *
     * @param ResourceNode|SharedScanNode|JoinNode $inner новый inner
     */
    public function withInner(ResourceNode|SharedScanNode|JoinNode $inner): static
    {
        return $inner === $this->inner
            ? $this
            : clone(object: $this, withProperties: ['inner' => $inner]);
    }
}
