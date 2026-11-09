<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor\Insert;

use Ggs\JsonApiEngine\Executor\PushExecutor;
use LogicException;

/**
 * Связь между ресурсами при вставке: родитель (`parent`, владелец связи `name`, сторона с первичным ключом связи)
 * вставляется первым, его новые дети — после (`child`), с колонкой связи из ключей владельца.
 *
 * Поток: владелец с поддеревом → `parent` (вставка, возвращает ключи) → дети из `relationships[name].data` (ресурс или
 * список; их подставил `DocumentParser`) → каждый в `child` с `link = [relation[1] => keys[relation[0]]]` → ключи
 * владельца наверх.
 *
 * Владелец передаётся в `parent` целиком, поэтому `parent` может быть и другим InsertJoinWriter: связи одного
 * владельца — левый глубокий список, как в SELECT; внутренний вернёт ключи владельца. Детей нет (`data: null`, пустой
 * список, связи нет в документе) — `child` не вызывается.
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
final readonly class InsertJoinWriter implements PushExecutor
{
    /**
     * @param array{0: string, 1: string} $relation [колонка владельца, колонка ребёнка]
     */
    public function __construct(
        private string $name,
        private array $relation,
        private PushExecutor $parent,
        private PushExecutor $child,
    ) {}

    #[\Override]
    public function push(array $resource, array $link = []): array
    {
        $keys = $this->parent->push($resource, $link);

        /** @var array|null $data */
        $data = $resource['relationships'][$this->name]['data'] ?? null;
        if ($data === null || $data === []) {
            return $keys;
        }
        $value = $keys[$this->relation[0]] ?? throw new LogicException("No key {$this->relation[0]} for {$this->name}");

        /** @var list<array> $children */
        $children = array_is_list($data) ? $data : [$data];
        foreach ($children as $child_resource) {
            $this->child->push($child_resource, [$this->relation[1] => $value]);
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
            'parent' => $this->parent->explain(),
            'child' => $this->child->explain(),
        ];
    }
}
