<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Select;

use Ggs\JsonApiEngine\Parser\QueryParser;
use Ggs\JsonApiEngine\Resource;

/**
 * Ресурс из дерева запроса — таблица ресурса, лист плана. Входов нет.
 *
 * Роль одна: таблица в FROM запроса, которым владеет ближайший SharedScanNode над ним. Своего запроса,
 * порядка потока и select у ResourceNode нет — всё это у владельца (SharedScanNode), даже если ресурс
 * в запросе один (provider, domain, log). После шага close голый ResourceNode вне строки — ошибка плана.
 *
 * Самый левый ResourceNode строки — ресурс, которому строка принадлежит (SharedScanNode::rowOwner()):
 * его sort считается сортировкой всей строки.
 *
 * fields и filters приходят из дерева запроса как есть и понадобятся при построении SQL
 * (оба читает SharedScanNode::withSelect(): fields — список колонок, filters — WHERE):
 *  - fields — только то, что запросил клиент: имена атрибутов, а не колонки. Ключи связей сюда
 *    не добавляются: их SQL-шаг возьмёт из relation соединений;
 *  - filters любого ресурса идут в WHERE запроса, в котором он читается; в ON — только условие связи
 *    (relation). У ресурса внутри строки (LEFT JOIN) условие пишется как (pk IS NULL OR условие):
 *    строка без этого ресурса остаётся, а строка, где ресурс есть, но не прошёл фильтр, выпадает.
 *
 * Иммутабелен: все свойства readonly и задаются один раз в SelectPlanner::fromTree().
 *
 * Кто читает:
 *  - resource, fields, filters — SharedScanNode::withSelect() (модель ресурса, колонки запроса, WHERE),
 *    SelectPlanPrinter для подписи;
 *  - sort — SharedScanNode::sort() (свободен ли порядок потока для HashToMergeRule).
 *
 * @psalm-import-type TreeFilter from QueryParser
 * @psalm-import-type TreeSort from QueryParser
 */
final class ResourceNode
{
    /**
     * Значения по умолчанию ([]) означают «нет»: ресурс без запрошенных полей, фильтров и сортировки.
     * Так удобно создавать узел в тестах только с resource: new ResourceNode(resource: DetailResource::class).
     *
     * @param class-string<Resource> $resource класс ресурса; его модель данных (model()) даёт FROM этого
     *                                         ресурса — одну таблицу или соединение таблиц
     * @param list<string>           $fields   атрибуты ресурса, запрошенные клиентом (fields дерева запроса)
     * @param list<TreeFilter>       $filters  фильтры ресурса из дерева запроса в том виде, в каком их дал
     *                                         парсер; при построении SQL идут в WHERE
     * @param list<TreeSort>         $sort     клиентская сортировка ресурса. Если не пустая и ресурс — владелец
     *                                         строки, порядок потока занят клиентом, и HashToMergeRule
     *                                         не может упорядочить его по ключу связи
     */
    public function __construct(
        public readonly string $resource,
        public readonly array $fields = [],
        public readonly array $filters = [],
        public readonly array $sort = [],
    ) {}
}
