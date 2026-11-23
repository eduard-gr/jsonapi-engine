<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Insert;

/**
 * Узел плана вставки: необязательная часть модели ресурса — правая сторона `LeftJoin`. Исполнитель —
 * `Executor\Insert\InsertOptionalEntityWriter`.
 *
 * Оборачивает поддерево таблиц (`input`): оно вставляется, только если в строке ресурса есть хотя бы одна его колонка
 * модели; иначе часть модели отсутствует, как в SELECT отсутствующая сторона `LeftJoin` (`null`). Стоит стороной
 * `InsertEntityJoinNode` — родителем или ребёнком, смотря какой оказалась правая сторона `LeftJoin`. Сторона `InnerJoin`
 * не оборачивается: она вставляется всегда.
 */
final readonly class InsertOptionalEntityNode
{
    public function __construct(
        public InsertEntityJoinNode|InsertEntityNode $input,
    ) {}
}
