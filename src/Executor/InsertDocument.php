<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor;

/**
 * Корень дерева вставки: отправляет каждый корень документа (`DocumentParser::parse()`) в одно дерево узлов.
 *
 * Поток: `list<ресурс>` + `$link` → `root->push(ресурс, link)` по очереди → `list<ключи корня>` в порядке `data` (по ним
 * ответ на `POST` строится SELECT-конвейером — пока не реализовано).
 *
 * `$link` — колонки связи, общие для всех корней: значение из маршрута вложенного ресурса. `POST /certificates/1/domains`
 * создаёт домены сертификата 1: `InsertPlanner::routeLink()` берёт связь родителя (`CertificateResource::DOMAINS`,
 * `relation` `[id, certificate_id]`) и даёт `['certificate_id' => 1]`; передаёт его `WriterBuilder::document()`. Так домену не нужна своя связь `certificate`
 * в ресурсе (она изменила бы вывод SELECT). Существование родителя узел не проверяет: несуществующий `id` — ошибка
 * внешнего ключа в базе. Совпадает ли `$link` с чем-то в документе, тоже не проверяет: лист берёт значения `$link`
 * поверх строки.
 *
 * Транзакцию не открывает: вставка нескольких таблиц и корней атомарна, только если вызывающий обернул `execute()`
 * в транзакцию. Повторный `execute()` вставит всё ещё раз.
 */
final readonly class InsertDocument
{
    /**
     * @param list<array>          $roots корни из `DocumentParser::parse()`
     * @param array<string, mixed> $link  колонки связи из маршрута вложенного ресурса (`certificate_id => 1`), всем корням
     */
    public function __construct(
        private ExecutorWriter $root,
        private array $roots,
        private array $link = [],
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function execute(): array
    {
        return array_map(fn(array $resource): array => $this->root->push($resource, $this->link), $this->roots);
    }

    /**
     * @return array<string, mixed>
     */
    public function explain(): array
    {
        return [
            'node' => static::class,
            'roots' => count($this->roots),
            'link' => $this->link,
            'root' => $this->root->explain(),
        ];
    }
}
