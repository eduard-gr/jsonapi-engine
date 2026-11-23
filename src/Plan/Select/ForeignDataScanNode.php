<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Select;

use Ggs\JsonApiEngine\Model\ForeignDataSource;
use Ggs\JsonApiEngine\Model\ModelTree;
use Ggs\JsonApiEngine\Model\SelectForeignDataInterface;
use Ggs\JsonApiEngine\Operator;
use LogicException;

/**
 * Лист плана: ресурс читается из чужого источника (`Model\ForeignDataSource`) — его кодом, без SQL. Пара к
 * `EntityScanNode`: у того запрос, у этого — класс источника и то, что ему передать. Исполнитель —
 * `Executor\Select\ForeignDataScanReader` под `SerializeReader` и `RegisterReader`.
 *
 * Поток: вход — `ResourceNode` (поля, фильтры и сортировка клиента в атрибутах) → выход для сборщика: класс
 * источника (`getDataSource()`), колонки (`getColumns()`), условия и сортировка клиента в колонках
 * (`getConditions()`, `getSortOrder()`) и запись колонок для сериализации (`getSerializeColumns()`).
 *
 * Чужой источник — всегда отдельный лист: в общую строку запроса он не входит (его 1-1 связи становятся
 * `HashOneJoinNode` на шаге `separate`), поэтому вход — один `ResourceNode`. Ставит узел шаг `close`
 * (`EntityScanNodeDraftPass`) — сразу вместо `EntityScanNodeDraft`: узел `Entity…` над ресурсом без сущности
 * не появляется. Шаг `merge` может занять порядок (`order`), шаг `sql` дописывает ключи соединений (`keys`).
 *
 * Чего узел источнику не передаёт: условий родителя. Цепочка предков на чужом источнике обрывается — ни он сам,
 * ни запрос ребёнка под ним не получают условий сверху; лишних связанных отбрасывает соединение.
 *
 * `order` источнику тоже не передаётся: шаг `merge` занимает его, только если ключ связи — первая колонка
 * ориентации чтения источника (`SelectForeignDataInterface::getReadOrientation()`), то есть поток уже идёт в этом
 * порядке. Нарушение ловит `AscendingKeyReader` на входе слияния.
 *
 * Ошибка описания — `LogicException` в конструкторе, то есть при планировании: модель ресурса не чужой источник
 * или источник не реализует `SelectForeignDataInterface`.
 */
final class ForeignDataScanNode
{
    /**
     * @param ResourceNode $input ресурс, который читается из чужого источника
     * @param string|null  $order колонка, по которой поток упорядочен ради MergeNestJoinNode; null — порядок свободен
     * @param list<string> $keys  ключи соединений над узлом; дописывает шаг sql
     * @throws LogicException если ресурс не над чужим источником или источник не умеет читать
     */
    public function __construct(
        public readonly ResourceNode $input,
        public readonly ?string $order = null,
        public readonly array $keys = [],
    ) {
        $this->getDataSource();
    }

    /**
     * Копия, упорядоченная по ключу связи. Вызывает HashToMergeRule; проверку, что порядок свободен и совпадает
     * с ориентацией чтения источника, делает вызывающий.
     */
    public function withOrder(string $order): static
    {
        return clone(object: $this, withProperties: ['order' => $order]);
    }

    /**
     * Копия с ключами соединений. Вызывает BuildSqlPass (шаг sql), когда дерево уже окончательное.
     *
     * @param list<string> $keys колонки, нужные соединениям над узлом
     */
    public function withKeys(array $keys): static
    {
        return clone(object: $this, withProperties: ['keys' => array_values(array_unique($keys))]);
    }

    /**
     * Ресурс узла. Имя то же, что у `EntityScanNodeDraft` и `EntityScanNode`: главный ресурс потока.
     */
    public function getRootResourceNode(): ResourceNode
    {
        return $this->input;
    }

    /**
     * Клиентская сортировка ресурса из дерева запроса. Непустая — поток занят клиентом (HashToMergeRule).
     */
    public function getSort(): array
    {
        return $this->input->sort;
    }

    /**
     * Класс источника: объект по нему даёт контейнер зависимостей при сборке дерева.
     *
     * @return class-string<ForeignDataSource&SelectForeignDataInterface>
     * @throws LogicException если модель ресурса не чужой источник или источник не реализует SelectForeignDataInterface
     */
    public function getDataSource(): string
    {
        $resource = $this->input->resource;
        $source = ModelTree::of($resource)->getRootDataSource();
        if ($source === null || !is_subclass_of($source, ForeignDataSource::class)) {
            throw new LogicException("{$resource} is not read from a foreign data source");
        }
        if (!is_subclass_of($source, SelectForeignDataInterface::class)) {
            throw new LogicException("{$resource}: source {$source} does not implement SelectForeignDataInterface");
        }

        return $source;
    }

    /**
     * Колонки, которые источник должен отдать: первичный ключ, колонки атрибутов `fields`, колонки
     * `Resource::getRequiredColumns()` и ключи соединений.
     *
     * @return list<string>
     */
    public function getColumns(): array
    {
        return $this->input->resource::getColumnsOf(fields: $this->input->fields, extra: $this->keys);
    }

    /**
     * Условия клиента в колонках, все через И.
     *
     * @return list<array{column: string, operator: Operator, value: mixed}>
     */
    public function getConditions(): array
    {
        $resource = $this->input->resource;

        return array_map(
            static fn(array $filter): array => [
                'column' => $resource::getFilterColumn(name: $filter['attribute']),
                'operator' => Operator::from($filter['operator']),
                'value' => $filter['value'],
            ],
            $this->input->filters,
        );
    }

    /**
     * Сортировка клиента в колонках, по `position`; пусто — источник читает в своей ориентации.
     *
     * @return list<array{column: string, descending: bool}>
     */
    public function getSortOrder(): array
    {
        $resource = $this->input->resource;
        $sort = $this->input->sort;
        usort(
            $sort,
            static fn(array $left_sort, array $right_sort): int => $left_sort['position'] <=> $right_sort['position'],
        );

        return array_map(
            static fn(array $entry): array => [
                'column' => $resource::getFilterColumn(name: $entry['attribute']),
                'descending' => $entry['descending'],
            ],
            $sort,
        );
    }

    /**
     * Запись колонок ресурса для `SerializeReader` — в том же виде, что запись `EntityScanNode::columns`: колонка
     * модели → имя колонки в строке (у чужого источника они совпадают), `fields` и ключи соединений.
     *
     * @return array{resource: class-string, alias: string, columns: array<string, string>, fields: list<string>, keys: list<string>}
     */
    public function getSerializeColumns(): array
    {
        $columns = $this->getColumns();

        return [
            'resource' => $this->input->resource,
            'alias' => 'source',
            'columns' => array_combine($columns, $columns),
            'fields' => $this->input->fields,
            'keys' => $this->keys,
        ];
    }
}
