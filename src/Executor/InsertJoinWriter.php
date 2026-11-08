<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor;

use LogicException;

/**
 * Связь между ресурсами при вставке: сторона с первичным ключом связи (`first`, владелец связи `name`) вставляется
 * первой, её новые дети — после (`then`), с колонкой связи из ключей владельца.
 *
 * Поток: владелец с поддеревом → `first` (вставка, возвращает ключи) → дети из `relationships[name].data` (ресурс или
 * список; их подставил `DocumentParser`) → каждый в `then` с `link = [relation[1] => keys[relation[0]]]` → ключи
 * владельца наверх.
 *
 * Владелец передаётся в `first` целиком, поэтому `first` может быть и другим InsertJoinWriter: связи одного
 * владельца — левый глубокий список, как в SELECT; внутренний вернёт ключи владельца. Детей нет (`data: null`, пустой
 * список, связи нет в документе) — `then` не вызывается.
 *
 * Порядок — забота того, кто строит дерево (`Plan\Insert\InsertPlanner` + `WriterBuilder`, или руками):
 *  - сторона с первичным ключом первой: так устроены `OneToMany` и обратная `OneToOne` (`relation[0]` — ключ
 *    владельца). Связь по внешнему ключу владельца (`provider`) сюда не попадает: её пишет `InsertResourceWriter` из
 *    `{type, id}`, нового целевого ресурса она не создаёт;
 *  - порядок соседних связей важен, если их таблицы ссылаются друг на друга: `certificate_delivery_meta.certificate_id`
 *    ссылается на `certificate_details`, поэтому `detail` должен стоять глубже (раньше) `delivery`. Связи ресурсов
 *    и сущности этого не описывают; планировщик ставит связи в порядке объявления в `relationships()`.
 * Нарушение порядка узел не обнаруживает: упадёт внешний ключ в базе. Нет ключа `relation[0]` у владельца —
 * `LogicException`.
 */
final readonly class InsertJoinWriter implements ExecutorWriter
{
    /**
     * @param array{0: string, 1: string} $relation [колонка владельца, колонка ребёнка]
     */
    public function __construct(
        private string $name,
        private array $relation,
        private ExecutorWriter $first,
        private ExecutorWriter $then,
    ) {}

    #[\Override]
    public function push(array $resource, array $link = []): array
    {
        $keys = $this->first->push($resource, $link);

        /** @var array|null $data */
        $data = $resource['relationships'][$this->name]['data'] ?? null;
        if ($data === null || $data === []) {
            return $keys;
        }
        $value = $keys[$this->relation[0]] ?? throw new LogicException("No key {$this->relation[0]} for {$this->name}");

        /** @var list<array> $children */
        $children = array_is_list($data) ? $data : [$data];
        foreach ($children as $child) {
            $this->then->push($child, [$this->relation[1] => $value]);
        }

        return $keys;
    }

    #[\Override]
    public function explain(): array
    {
        return [
            'node' => static::class,
            'name' => $this->name,
            'relation' => $this->relation,
            'first' => $this->first->explain(),
            'then' => $this->then->explain(),
        ];
    }
}
