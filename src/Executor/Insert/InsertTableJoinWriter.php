<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor\Insert;

use LogicException;

/**
 * Соединение таблиц модели при вставке: строка ресурса делится между двумя сторонами; родитель (`parent`, сторона,
 * от которой зависит другая) вставляется первым, ребёнок (`child`) получает колонку связи из его ключей. Строит
 * `WriterBuilder` по `InsertTableJoinNode` из `LeftJoin`/`InnerJoin` модели ресурса.
 *
 * Поток: строка + `$link` → `parent->push()` → ключи → `child->push(строка, link + [relation[1] => keys[relation[0]]])`
 * → объединённые ключи обеих сторон.
 *
 * Необязательную сторону (правую в `LeftJoin`) пропускает обёртка `InsertOptionalTableWriter`, а не этот узел. Её
 * пропуск виден здесь как пустые ключи: пропущенный `parent` не даёт колонку связи, и `child` пишет её значение
 * по умолчанию (обычно NULL). Входящий `$link` уходит в обе стороны: лист берёт из него только колонки своей сущности.
 *
 * Ограничение: ключи сторон сливаются в одну карту; колонка с одним именем в двух таблицах — значение `child`
 * перезапишет значение `parent` (для пары колонок `relation` значение одно и то же).
 */
final readonly class InsertTableJoinWriter implements TableWriter
{
    /**
     * @param array{0: string, 1: string} $relation [колонка родителя, колонка ребёнка]
     */
    public function __construct(
        private array $relation,
        private TableWriter $parent,
        private TableWriter $child,
    ) {}

    #[\Override]
    public function push(array $row, array $link = []): array
    {
        $parent_keys = $this->parent->push($row, $link);

        $child_link = $link;
        if ($parent_keys !== []) {
            $child_link[$this->relation[1]] = $parent_keys[$this->relation[0]]
                ?? throw new LogicException("No key {$this->relation[0]} after insert");
        }
        $child_keys = $this->child->push($row, $child_link);

        return [...$parent_keys, ...$child_keys];
    }

    #[\Override]
    public function columns(): array
    {
        return [...$this->parent->columns(), ...$this->child->columns()];
    }

    #[\Override]
    public function explain(): array
    {
        return [
            'node' => static::class,
            'relation' => $this->relation,
            'parent' => $this->parent->explain(),
            'child' => $this->child->explain(),
        ];
    }
}
