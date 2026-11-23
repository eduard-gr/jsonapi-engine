<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Insert;

/**
 * Узел плана вставки: соединение двух частей модели ресурса (`LeftJoin`/`InnerJoin` из `getModel()`). Исполнитель —
 * `Executor\Insert\InsertEntityJoinWriter`.
 *
 * Зависимость уже выбрана планировщиком: `parent` — сторона, от которой зависит другая (её колонка `relation[0]` —
 * первичный ключ, или на неё ссылается колонка ребёнка); она вставляется первой. `child` получает колонку `relation[1]`
 * из ключа `relation[0]` родителя. Необязательная сторона (правая в `LeftJoin`) обёрнута в `InsertOptionalEntityNode`
 * и может оказаться как родителем, так и ребёнком; сам узел о необязательности не знает.
 */
final readonly class InsertEntityJoinNode
{
    /**
     * @param array{0: string, 1: string} $relation [колонка родителя, колонка ребёнка]
     */
    public function __construct(
        public array $relation,
        public InsertEntityJoinNode|InsertEntityNode|InsertOptionalEntityNode $parent,
        public InsertEntityJoinNode|InsertEntityNode|InsertOptionalEntityNode $child,
    ) {}
}
