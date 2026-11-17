<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor\Update;

use Ggs\JsonApiEngine\Executor\TableWriter;
use LogicException;

/**
 * Соединение двух частей модели при изменении: сначала `main` (левая сторона, её ключ известен), потом `joined` — по
 * ключу, который вернула `main`. Строит `WriterBuilder` по `UpdateTableJoinNode`.
 *
 * Поток: строка + `$link` → `main->push()` → ключи; пусто — строки нет, дальше не идёт (наверху 404) →
 * `joined->push(строка, link + [relation[1] => keys[relation[0]]])` → ключи обеих сторон.
 *
 * Ограничение: пустой ответ `joined` у `InnerJoin` (рассогласование данных) не проверяется.
 */
final readonly class UpdateTableJoinWriter implements TableWriter
{
    /**
     * @param array{0: string, 1: string} $relation [колонка стороны main, колонка стороны joined]
     */
    public function __construct(
        private array $relation,
        private TableWriter $main,
        private TableWriter $joined,
    ) {}

    #[\Override]
    public function push(array $row, array $link = []): array
    {
        $main_keys = $this->main->push($row, $link);
        if ($main_keys === []) {
            return [];
        }
        $joined_link = [
            ...$link,
            $this->relation[1] => $main_keys[$this->relation[0]]
                ?? throw new LogicException("No key {$this->relation[0]} after update of the main side"),
        ];

        return [...$main_keys, ...$this->joined->push($row, $joined_link)];
    }

    #[\Override]
    public function columns(): array
    {
        return [...$this->main->columns(), ...$this->joined->columns()];
    }

    #[\Override]
    public function explain(): array
    {
        return [
            'node' => static::class,
            'relation' => $this->relation,
            'main' => $this->main->explain(),
            'joined' => $this->joined->explain(),
        ];
    }
}
