<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor\Write;

use Ggs\JsonApiEngine\Executor\PushExecutor;
use Ggs\JsonApiEngine\Parser\Write\WriteOperation;
use LogicException;

/**
 * Связь по владению (to-many и to-one) при записи: родитель (`parent`), затем его дети из
 * `relationship(name)->children` в `child` с `link = [relation[1] => keys[relation[0]]]`. Строит `WriterBuilder` по
 * `WriteJoinNode`. To-one приходит от парсера тем же списком (один ребёнок). Сам в базу не пишет.
 *
 * Поток: операция с поддеревом → `parent` → ключи → каждый ребёнок в `child` по порядку (парсер уже упорядочил:
 * удаления, оставленные и изменённые, вставки) → ключи родителя наверх. Связи у операции нет — дети не трогаются.
 * Полную замену выполняет `WriteReplaceWriter` внутри `parent`, то есть до детей.
 */
final readonly class WriteJoinWriter implements PushExecutor
{
    /**
     * @param array{0: string, 1: string} $relation [колонка родителя, колонка ребёнка]
     */
    public function __construct(
        private string $name,
        private array $relation,
        private PushExecutor $parent,
        private PushExecutor $child,
    ) {}

    #[\Override]
    public function push(WriteOperation $operation, array $link = []): array
    {
        $keys = $this->parent->push($operation, $link);
        $relationship = $operation->relationship(name: $this->name);
        if ($relationship === null) {
            return $keys;
        }
        $parent_key = $keys[$this->relation[0]]
            ?? throw new LogicException("No key {$this->relation[0]} for {$this->name}");
        foreach ($relationship->children as $child) {
            $this->child->push($child, [$this->relation[1] => $parent_key]);
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
