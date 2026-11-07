<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Resource;

use Ggs\JsonApiEngine\Resource;

/**
 * Описание связи между ресурсами: какой ресурс подключается к ресурсу-владельцу и по какому ключу.
 *
 * Ресурс-владелец объявляет свои связи в `relationships()` (имя связи → связь); каждую из них клиент может
 * подключить через `include`. Сама связь хранит только данные: ресурс на другой стороне и ключ.
 *
 * Ключ — `relation`: `[колонка владельца, колонка ресурса resource]`. Ресурсы объединяются по равенству этих
 * колонок: строка ресурса принадлежит строке владельца, если `владелец.relation[0] = ресурс.relation[1]`.
 * Колонки — колонки ресурсов (ключи `COLUMNS` сущностей их моделей, обычно константы сущностей), а не имена
 * атрибутов в API; обе — целые числа (`INT`), потому что ключи сравнивает и PHP (хеш-таблицы, слияние потоков).
 *
 * Вид связи (класс-наследник) говорит, сколько строк на каждой стороне и на какой стороне лежит ключ, а от этого
 * зависит, как связь выполняется (какое поддерево узлов `Plan` строится):
 *  - `OneToOne` — не больше одной строки ресурса у владельца; ключ у ресурса, его колонка уникальна.
 *    `certificate.detail`: `[id, certificate_id]` → `certificates.id = certificate_details.certificate_id`.
 *    Ресурс читается тем же SQL-запросом, что и владелец (`JOIN`): `SharedDataIterator`, `SharedRowJoinIterator`,
 *    `SharedScanIterator`;
 *  - `ManyToOne` — не больше одной строки ресурса у владельца; ключ у владельца: внешний ключ на первичный ключ
 *    ресурса. `certificate.provider`: `[provider_id, id]` → `certificates.provider_id = providers.id`.
 *    Связь пишет сериализация владельца (связь по внешнему ключу), ресурс читается отдельным SQL-запросом: `PreloadIterator`;
 *  - `OneToMany` — любое число строк ресурса у владельца; ключ у ресурса: внешний ключ на колонку владельца.
 *    `certificate.domains`: `[id, certificate_id]` → `certificates.id = domains.certificate_id`.
 *    Ресурс читается отдельным SQL-запросом: `HashNestJoinIterator` или `MergeNestJoinIterator`.
 *
 * Связь сама ничего не выполняет: план строят руками (`example/document.php`), планировщика пока нет.
 *
 * В дереве запроса вид связи — строка `one-to-one`, `many-to-one` или `one-to-many`
 * (`QueryParser::relationshipType()`).
 * `Resource::validate()` проверяет имя связи, что `resource` — наследник `Resource`, что колонки `relation` есть
 * в моделях и имеют тип `INT`, и уникальность колонки ресурса у `OneToOne`.
 */
abstract readonly class Relationship
{
    /**
     * @param class-string<Resource> $resource ресурс на другой стороне связи
     * @param list<string>           $relation [колонка владельца, колонка ресурса resource]
     */
    public function __construct(
        public string $resource,
        public array $relation,
    ) {
    }

}
