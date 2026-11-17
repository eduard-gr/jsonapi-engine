<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor\Write;

use Ggs\JsonApiEngine\Executor\PushExecutor;
use Ggs\JsonApiEngine\Parser\Write\WriteOperation;

/**
 * Корень записи: проверки по всему запросу, затем каждый корень дерева операций в одно дерево исполнителей. Проверка
 * родителя вложенного маршрута проходит один раз и до первой записи, а не на каждом корне. Сам не исполнитель уровня
 * ресурсов: сверху ему ничего не приходит. Строит `WriterBuilder::root()` по `WriteRootNode`.
 *
 * Поток: `route->link()` (проверка родителя, колонки связи; null — маршрут не вложенный) → `root->push(корень, link)`
 * по очереди → `list<ключи корня>`.
 *
 * Транзакцию не открывает: атомарность — у вызывающего.
 */
final readonly class WriteRootWriter
{
    /**
     * @param list<WriteOperation> $data корни дерева операций
     */
    public function __construct(
        private PushExecutor $root,
        private array $data,
        private ?RouteParentWriter $route,
    ) {}

    /**
     * @return list<array<string, mixed>>
     * @throws WriteNotFoundException родителя из маршрута нет; ресурса нет или он чужой при выполнении дерева
     */
    public function execute(): array
    {
        $link = $this->route?->link() ?? [];

        return array_map(
            fn(WriteOperation $operation): array => $this->root->push($operation, $link),
            $this->data,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function explain(): array
    {
        return [
            'node' => static::class,
            'data' => count($this->data),
            ...($this->route === null ? [] : ['route' => $this->route->explain()]),
            'root' => $this->root->explain(),
        ];
    }
}
