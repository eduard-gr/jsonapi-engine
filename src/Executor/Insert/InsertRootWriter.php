<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor\Insert;

use Ggs\JsonApiEngine\Executor\PushExecutor;

/**
 * Корень дерева вставки: отправляет каждый корень документа (`DocumentParser::parse()`) в одно дерево узлов.
 * Сам не `PushExecutor`: сверху ему ничего не приходит, его запускают `execute()`. Строит `WriterBuilder::root()`.
 *
 * Поток: `list<ресурс>` → `root->push(ресурс)` по очереди → `list<ключи корня>` в порядке `data` (по ним ответ на `POST`
 * строится SELECT-конвейером — пока не реализовано).
 *
 * Привязку корней к родителю из маршрута вложенного ресурса делает не корень, а узел `InsertRouteLinkWriter` под ним.
 *
 * Транзакцию не открывает: вставка нескольких таблиц и корней атомарна, только если вызывающий обернул `execute()`
 * в транзакцию. Повторный `execute()` вставит всё ещё раз.
 */
final readonly class InsertRootWriter
{
    /**
     * @param list<array> $data ресурсы `data` документа из `DocumentParser::parse()`
     */
    public function __construct(
        private PushExecutor $root,
        private array $data,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function execute(): array
    {
        return array_map(fn(array $resource): array => $this->root->push($resource), $this->data);
    }

    /**
     * @return array<string, mixed>
     */
    public function explain(): array
    {
        return [
            'node' => static::class,
            'data' => count($this->data),
            'root' => $this->root->explain(),
        ];
    }
}
