<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor\Insert;

use Ggs\JsonApiEngine\Executor\PushExecutor;

/**
 * Привязка корней к существующему родителю из маршрута вложенного ресурса: добавляет колонки связи `link` к каждому
 * ресурсу, который проходит через узел. Стоит над деревом уровня ресурсов, под `InsertRootWriter`; строит
 * `WriterBuilder::root()`, если колонки связи не пустые.
 *
 * Поток: ресурс + входящий `$link` → `input->push(ресурс, link узла + входящий link)` → ключи ресурса.
 *
 * `POST /certificates/1/domains` создаёт домены сертификата 1: `InsertPlanner::routeLink()` берёт связь родителя
 * (`CertificateResource::DOMAINS`, `relation` `[id, certificate_id]`) и даёт `['certificate_id' => 1]`. Так домену
 * не нужна своя связь `certificate` в ресурсе (она изменила бы вывод SELECT). Значение пришло из маршрута, а не из
 * формы документа, поэтому в плане его нет: узел есть только в дереве исполнителей.
 *
 * Существование родителя узел не проверяет: несуществующий `id` — ошибка внешнего ключа в базе. Совпадает ли `link`
 * с чем-то в документе, тоже не проверяет: лист берёт значения колонок связи поверх строки. Колонки связи доходят
 * только до ресурсов, которые прошли через узел (корни): `InsertJoinWriter` даёт своим детям новые колонки связи.
 */
final readonly class InsertRouteLinkWriter implements PushExecutor
{
    /**
     * @param array<string, mixed> $link колонки связи из маршрута (`certificate_id => 1`)
     */
    public function __construct(
        private array $link,
        private PushExecutor $input,
    ) {}

    #[\Override]
    public function push(array $resource, array $link = []): array
    {
        return $this->input->push($resource, [...$this->link, ...$link]);
    }

    #[\Override]
    public function explain(): array
    {
        return [
            'node' => static::class,
            'link' => $this->link,
            'input' => $this->input->explain(),
        ];
    }
}
