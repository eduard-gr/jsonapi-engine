<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Insert;

/**
 * Узел плана вставки: соединение двух частей модели ресурса (`LeftJoin`/`InnerJoin` из `model()`). Исполнитель —
 * `Executor\InsertTableJoinWriter`.
 *
 * Порядок уже выбран планировщиком: `first` — сторона с первичным ключом связи, `then` получает колонку `relation[1]`
 * из ключа `relation[0]` стороны `first`. `optional` — сторона справа в `LeftJoin` (`first` или `then`, смотря какая
 * вставляется первой), она пропускается, если в строке нет её колонок; null — `InnerJoin`.
 */
final readonly class InsertTableJoinNode
{
    /**
     * @param array{0: string, 1: string} $relation [колонка `first`, колонка `then`]
     * @param 'first'|'then'|null         $optional
     */
    public function __construct(
        public array $relation,
        public InsertTableJoinNode|InsertTableNode $first,
        public InsertTableJoinNode|InsertTableNode $then,
        public ?string $optional,
    ) {}
}
