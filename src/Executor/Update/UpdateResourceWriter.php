<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor\Update;

use Ggs\JsonApiEngine\Executor\PushExecutor;
use Ggs\JsonApiEngine\Executor\TableWriter;
use Ggs\JsonApiEngine\Executor\Write\ResourceRow;
use Ggs\JsonApiEngine\Model\ModelTree;
use Ggs\JsonApiEngine\Parser\Write\KeepOperation;
use Ggs\JsonApiEngine\Parser\Write\UpdateOperation;
use Ggs\JsonApiEngine\Parser\Write\WriteOperation;
use Ggs\JsonApiEngine\Resource;
use LogicException;

/**
 * Граница уровней при изменении: операция → строка «колонка модели → значение» → поддерево таблиц (`input`, из
 * `WriterBuilder` по плану `UpdatePlanner::tables()`). Пара к `InsertResourceWriter`. Исполняет `UpdateOperation` и
 * `KeepOperation`; другая операция — LogicException.
 *
 * Поток: операция (`identity->id`, `attributes`, `references`) + `$link` → `input->push(строка, link + [pk => id])`
 * → ключи всех таблиц; пусто — строки главной таблицы нет (или она чужая: колонки связи от родителя — условие
 * принадлежности), наверху это 404. У `KeepOperation` строка пустая: ресурс только ищется.
 *
 * В строку идут только присланные атрибуты; `null` — это NULL в колонку (присутствие ключа, а не значение, решает,
 * что колонку трогать). Колонка связи от родителя должна быть колонкой модели, иначе принадлежность не проверить —
 * LogicException. Если первичный ключ ресурса и есть колонка связи (`delivery.certificate_id`), id, не равный
 * родителю, — пусто (404) без запроса.
 */
final readonly class UpdateResourceWriter implements PushExecutor
{
    /**
     * @param class-string<Resource> $resource
     */
    public function __construct(
        private string $resource,
        private TableWriter $input,
    ) {}

    #[\Override]
    public function push(WriteOperation $operation, array $link = []): array
    {
        if (!$operation instanceof UpdateOperation && !$operation instanceof KeepOperation) {
            throw new LogicException("{$this->resource}: update needs UpdateOperation or KeepOperation");
        }
        $tree = ModelTree::of($this->resource);
        foreach (array_keys($link) as $column) {
            if (!$tree->hasColumn((string) $column)) {
                throw new LogicException("{$this->resource}: link column {$column} is not a model column");
            }
        }

        $primary = $this->resource::primaryColumn();
        $id = $operation->identity->id;
        // Первичный ключ ребёнка — сама колонка связи (`delivery.certificate_id`): id обязан совпасть с родителем
        if (isset($link[$primary]) && (int) $link[$primary] !== $id) {
            return [];
        }
        $row = [];
        if ($operation instanceof UpdateOperation) {
            $row = ResourceRow::columns(
                resource: $this->resource,
                attributes: $operation->attributes,
                references: $operation->references,
            );
        }

        return $this->input->push($row, [...$link, $primary => $id]);
    }

    #[\Override]
    public function explain(): array
    {
        return [
            'node' => static::class,
            'resource' => $this->resource,
            'input' => $this->input->explain(),
        ];
    }
}
