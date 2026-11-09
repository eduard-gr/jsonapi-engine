<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Insert;

/**
 * Узел плана вставки: связь `name` между ресурсами. Исполнитель — `Executor\Insert\InsertJoinWriter`.
 *
 * `parent` вставляет владельца связи (родитель — сторона с первичным ключом: `relation[0]` — ключ владельца), `child` —
 * его новых детей из `relationships[name]` с колонкой `relation[1]`. Связи одного владельца — левый глубокий список:
 * `parent` может быть другим `InsertJoinNode` того же владельца; порядок списка — порядок вставки (см. `InsertPlanner`).
 */
final readonly class InsertJoinNode
{
    /**
     * @param array{0: string, 1: string} $relation [колонка владельца, колонка ребёнка]
     */
    public function __construct(
        public string $name,
        public array $relation,
        public InsertJoinNode|InsertResourceNode $parent,
        public InsertJoinNode|InsertResourceNode $child,
    ) {}
}
