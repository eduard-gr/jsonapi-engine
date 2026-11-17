<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Write;

/**
 * Узел плана записи: связь по владению (to-many и to-one) `name` между ресурсами. Исполнитель — `WriteJoinWriter`.
 * Связи одного владельца — левый глубокий список. Только передаёт детей: полная замена —
 * отдельный узел `WriteReplaceNode` под `parent`. У связи нет ни одного элемента — узла связи нет.
 */
final readonly class WriteJoinNode
{
    /**
     * @param array{0: string, 1: string} $relation [колонка владельца, колонка ребёнка]
     */
    public function __construct(
        public string $name,
        public array $relation,
        public WriteJoinNode|WriteReplaceNode|WriteOperationNode $parent,
        public WriteJoinNode|WriteReplaceNode|WriteOperationNode $child,
    ) {}
}
