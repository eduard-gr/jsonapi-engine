<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Write;

/**
 * Узел плана записи: связь по владению (to-many и to-one) `name` между ресурсами. Исполнитель —
 * `Executor\Write\WriteJoinWriter`.
 *
 * `parent` — владелец (или уже построенная цепочка его связей), `child` — место детей связи. Связи одного владельца —
 * левый глубокий список в порядке объявления в `relationships()`: владелец в самом низу, пишется первым. Узел только
 * передаёт детей: полная замена — отдельный узел `WriteReplaceNode` под `parent`. У связи нет ни одного ребёнка ни у
 * одного владельца места — узла связи нет.
 *
 * Неизменяемый, без состояния.
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
