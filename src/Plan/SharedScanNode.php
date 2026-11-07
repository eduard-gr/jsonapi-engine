<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan;

use Ggs\JsonApiEngine\Model\ModelTree;
use Ggs\JsonApiEngine\Operator;
use Ggs\JsonApiEngine\Resource;
use sad_spirit\pg_builder\enums\IsPredicate;
use sad_spirit\pg_builder\enums\JoinType;
use sad_spirit\pg_builder\enums\LogicalOperator;
use sad_spirit\pg_builder\enums\OrderByDirection;
use sad_spirit\pg_builder\nodes\ColumnReference;
use sad_spirit\pg_builder\nodes\expressions\IsExpression;
use sad_spirit\pg_builder\nodes\expressions\LogicalExpression;
use sad_spirit\pg_builder\nodes\expressions\NamedParameter;
use sad_spirit\pg_builder\nodes\expressions\OperatorExpression;
use sad_spirit\pg_builder\nodes\Identifier;
use sad_spirit\pg_builder\nodes\lists\TargetList;
use sad_spirit\pg_builder\nodes\OrderByElement;
use sad_spirit\pg_builder\nodes\range\FromElement;
use sad_spirit\pg_builder\nodes\range\JoinExpression;
use sad_spirit\pg_builder\nodes\ScalarExpression;
use sad_spirit\pg_builder\nodes\TargetElement;
use sad_spirit\pg_builder\Select;

/**
 * Владелец SQL-запроса: один запрос, из каждой строки которого строятся ресурсы его поддерева —
 * ресурс-владелец и все его ONE_TO_ONE (сертификат, detail, delivery) или один ресурс (provider).
 *
 * Стоит над каждым запросом плана, даже если ресурс в запросе один: тогда его вход — сам ResourceNode.
 * Всё под ним — одна строка одного запроса, всё над ним (PreloadNode, nest join) — ветки
 * ресурса-владельца строки, которые соединяются с её потоком. Ветки 1-1 ресурсов остаются внутри строки,
 * у своего ресурса (HashJoinNode logs над delivery): их outer — часть строки, а inner — отдельный запрос
 * со своим владельцем. Ставится шагом Select\SharedScanPass, после того как ветки владельца подняты
 * над строкой.
 *
 * Ровно один вход — верх строки: SharedRowJoinNode (ресурс с 1-1) или ResourceNode (один ресурс).
 * Тип свойства не даёт подставить туда ветку: ветки владельца стоят над ним.
 *
 * Только у владельца хранятся порядок потока (order), запрос (select), его параметры (parameters) и карта колонок
 * строки (columns): один
 * класс вместо двух, поэтому Select\HashToMergeRule, Select\BuildSqlPass и Select\QueryPlanPrinter знают одного
 * владельца. Запрос владелец собирает сам из своего поддерева (withSelect()): FROM — свёртка узлов под ним
 * (ResourceNode → модель ресурса, SharedRowJoinNode → LEFT JOIN по relation, ветка внутри строки → её outer),
 * список колонок — нужные колонки каждого ресурса строки.
 *
 * Имя «Shared» историческое: при одном ресурсе строка ни с кем не делится. При выполнении такой владелец
 * не нуждается в SharedDataIterator — это решение сборщика дерева итераторов, а не плана.
 *
 * Иммутабелен: свойства readonly, изменения — через with…(), которые возвращают копию.
 *
 * @psalm-type RowResource = array{
 *     resource: class-string<Resource>,
 *     alias: string,
 *     columns: array<string, string>,
 *     fields: list<string>,
 *     keys: list<string>,
 * }
 * @psalm-type RowColumns = list<RowResource>
 * @psalm-type QueryParameters = array<string, int|float|string|bool>
 */
final class SharedScanNode
{
    /**
     * @param SharedRowJoinNode|ResourceNode $input   верх строки: цепочка SharedRowJoinNode, в которой собраны
     *                                                все 1-1 ресурсы запроса, или единственный ресурс запроса;
     *                                                самый левый ResourceNode — ресурс-владелец
     * @param string|null                    $order   колонка, по которой поток упорядочен ради MergeNestJoinNode.
     *                                                Проставляет HashToMergeRule; null — порядок ещё свободен
     * @param Select|null                    $select  запрос владельца. Проставляет BuildSqlPass через withSelect();
     *                                                до него — null
     * @param RowColumns|null                $columns всё, что нужно Executor\SerializeIterator, чтобы собрать ресурсы
     *                                                из строки запроса: по ресурсу строки в порядке обхода слева
     *                                                направо (ресурс-владелец — первый) — класс ресурса, его алиас
     *                                                в запросе (t0, t1, …), карта «колонка модели → имя колонки
     *                                                в строке» (t1_buyer_email), запрошенные атрибуты (fields узла)
     *                                                и колонки модели — ключи соединений (keys ResourceRecord).
     *                                                Проставляется вместе с select; до него — null
     * @param QueryParameters                $parameters значения именованных параметров select (:t0_status_0)
     *                                                по имени без двоеточия, уже скаляры для PDO. Проставляются
     *                                                вместе с select; без фильтров — пусто
     */
    public function __construct(
        public readonly SharedRowJoinNode|ResourceNode $input,
        public readonly ?string $order = null,
        public readonly ?Select $select = null,
        public readonly ?array $columns = null,
        public readonly array $parameters = [],
    ) {}

    /**
     * Копия с другим верхом строки. Вызывают Rule::transformUp и BuildSqlPass, когда что-то изменилось
     * внутри строки. Тот же вход — тот же узел: копия не создаётся, и неизменённое дерево остаётся общим.
     *
     * @param SharedRowJoinNode|ResourceNode $input новый верх строки
     */
    public function withInput(SharedRowJoinNode|ResourceNode $input): static
    {
        return $input === $this->input
            ? $this
            : clone(object: $this, withProperties: ['input' => $input]);
    }

    /**
     * Копия, упорядоченная по ключу связи. Вызывает HashToMergeRule, когда занимает поток под Merge.
     * Проверку, что порядок свободен (order === null и sort() пуст), делает вызывающий.
     *
     * @param string $order колонка, по которой должен идти поток
     */
    public function withOrder(string $order): static
    {
        return clone(object: $this, withProperties: ['order' => $order]);
    }

    /**
     * Копия с собранным запросом (select), его параметрами (parameters) и картой колонок строки (columns: алиас,
     * колонки, fields и ключи соединений каждого ресурса). Вызывает BuildSqlPass, когда дерево уже окончательное.
     *
     * Ресурсы строки обходятся слева направо, как в FROM; каждому — алиас t0, t1, … (у таблиц внутри модели
     * ресурса — t0_1, t0_2, …, их задаёт ModelTree). Колонки ресурса в запросе:
     *  - первичный ключ — всегда: он даёт id, а NULL в нём значит, что ресурса в строке нет (LEFT JOIN);
     *  - колонки атрибутов fields (Resource::attributeColumns(): у составного — все, у связи по внешнему
     *    ключу — колонка ключа);
     *  - ключи соединений внутри строки: у SharedRowJoinNode родителю (самый левый ресурс outer) — relation[0],
     *    ребёнку (самый левый ресурс inner) — relation[1]; у ветки внутри строки ресурсу outer — relation[0];
     *  - $keys — ключи веток над строкой, их передаёт BuildSqlPass; достаются ресурсу-владельцу.
     *
     * Каждая колонка выводится как <алиас ресурса>_<колонка модели> (t1_buyer_email): алиасы ресурсов уникальны
     * в запросе, колонки — в модели, поэтому имена не совпадают, даже если у двух таблиц есть certificate_id.
     * ModelTree::from() получает эти колонки, колонки фильтров ресурса и (у владельца) колонки ORDER BY
     * и отбрасывает ненужные LEFT JOIN внутри модели ресурса; колонки ON попадают в набор как ключи, поэтому их
     * таблицы остаются. Колонки фильтров и сортировки в список колонок не выводятся.
     *
     * WHERE — фильтры ресурсов строки (filters узлов), все через AND; в ON они не идут:
     *  - фильтр ресурса-владельца (t0) — условие как есть: t0.status = :t0_status_0;
     *  - фильтры 1-1 ресурса (он в строке через LEFT JOIN) — одной группой (t1.pk IS NULL OR (усл. AND …)):
     *    строка без этого ресурса остаётся, а строка, где ресурс есть, но не прошёл фильтр, выпадает;
     *  - колонка — Resource::filterColumn() (у связи по внешнему ключу — колонка ключа), выражение —
     *    ModelTree::expression(); операторы eq, ne, lt, gt, lte, gte — =, <>, <, >, <=, >=;
     *  - значение — именованный параметр :<алиас>_<колонка>_<номер> (номер — по порядку в запросе, поэтому
     *    два условия на одну колонку не совпадают); значения — в parameters, уже скаляры: date — 'Y-m-d',
     *    datetime — 'Y-m-d H:i:s.uP'.
     * TODO: оператор in (Operator::In) — LogicException; ne и NULL: t.c <> :v не находит строки с NULL в t.c.
     *
     * Каждый вызов строит новые объекты pg_builder: узел pg_builder принадлежит одному дереву.
     * Запросы веток внутри строки (их inner) сюда не входят — у них свои владельцы.
     *
     * ORDER BY — порядок строк, то есть ресурса-владельца (алиас t0); сортировки 1-1 ресурсов на него не влияют:
     *  - order — ключ, по которому поток занял HashToMergeRule (MergeNestJoinIterator проверяет порядок ключей);
     *  - клиентский sort владельца по position, с DESC у descending; у составного атрибута — все его колонки.
     *    order и sort вместе не бывают: HashToMergeRule не занимает поток с клиентской сортировкой;
     *  - первичный ключ владельца последним, если его ещё нет: порядок однозначен, и строки с одинаковым
     *    ключом не меняются местами от запуска к запуску.
     *
     * Пока не собирается: цепочка предков (CTE + IN, см. README «Цепочка предков»).
     *
     * @param list<string> $keys колонки модели ресурса-владельца, нужные веткам над строкой (их relation[0])
     *                           и соединению, в inner которого стоит этот запрос (relation[1])
     * @throws \LogicException если внутри строки встретился другой владелец, колонки нет в модели ресурса
     *                         или фильтр с неподдержанным оператором
     */
    public function withSelect(array $keys = []): static
    {
        // Ресурсы строки и нужные им колонки — в порядке обхода, номер ресурса — номер его алиаса
        $resources = [];
        $needed = [];
        $joinKeys = [];
        $this->gather(node: $this->input, keys: $keys, resources: $resources, needed: $needed, joinKeys: $joinKeys);

        $targets = [];
        $columns = [];
        $conditions = [];
        $parameters = [];
        foreach ($resources as $index => $resource) {
            $alias = 't' . $index;
            $tree = ModelTree::of($resource->resource);
            $map = [];
            foreach (array_keys($needed[$index]) as $column) {
                $map[$column] = "{$alias}_{$column}";
                // Вызовы pg_builder — позиционные: имена параметров библиотеки не становятся частью нашего кода
                $targets[] = new TargetElement($tree->expression($column, $alias), new Identifier($map[$column]));
            }
            $columns[] = [
                'resource' => $resource->resource,
                'alias' => $alias,
                'columns' => $map,
                'fields' => $resource->fields,
                'keys' => $joinKeys[$index],
            ];

            // FROM нужны и колонки, которые не выводятся: фильтров ресурса и (у владельца) ORDER BY
            foreach ($resource->filters as $filter) {
                $needed[$index][$resource->resource::filterColumn($filter['attribute'])] = true;
            }
            if ($index === 0) {
                foreach ($this->orderColumns() as $column => $direction) {
                    $needed[$index][$column] = true;
                }
            }

            $condition = $this->condition(resource: $resource, alias: $alias, parameters: $parameters);
            if ($condition !== null) {
                // 1-1 ресурс — в строке через LEFT JOIN: строку без него фильтр не трогает
                $conditions[] = $index === 0 ? $condition : new LogicalExpression(
                    [
                        new IsExpression($tree->expression($resource->resource::primaryColumn(), $alias), IsPredicate::NULL),
                        $condition,
                    ],
                    LogicalOperator::OR,
                );
            }
        }

        $select = new Select(new TargetList($targets));
        $index = 0;
        $select->from[] = $this->from(node: $this->input, needed: $needed, index: $index)[0];
        foreach ($conditions as $condition) {
            $select->where->and($condition);
        }
        $this->orderBy(select: $select);

        return clone(object: $this, withProperties: ['select' => $select, 'columns' => $columns, 'parameters' => $parameters]);
    }

    /**
     * Фильтры одного ресурса строки через AND (см. withSelect()); null — фильтров нет.
     *
     * @param array<string, int|float|string|bool> $parameters по ссылке: параметры запроса, сюда добавляются
     *                                                         значения фильтров ресурса
     * @throws \LogicException если оператор не поддержан
     */
    private function condition(ResourceNode $resource, string $alias, array &$parameters): ?ScalarExpression
    {
        $class = $resource->resource;
        $tree = ModelTree::of($class);
        $terms = [];
        foreach ($resource->filters as $filter) {
            $column = $class::filterColumn($filter['attribute']);
            $sql = match (Operator::from($filter['operator'])) {
                Operator::Eq => '=',
                // TODO: NULL в колонке — строка не найдена (t.c <> :v); решить, нужен ли IS DISTINCT FROM
                Operator::Ne => '<>',
                Operator::Lt => '<',
                Operator::Gt => '>',
                Operator::Lte => '<=',
                Operator::Gte => '>=',
                // TODO: список значений; через PDO массив одним параметром не передать
                Operator::In => throw new \LogicException("{$class}: filter operator in is not supported yet"),
            };
            $name = "{$alias}_{$column}_" . \count($parameters);
            $parameters[$name] = self::parameter(value: $filter['value'], type: $class::attributeType($filter['attribute']));
            $terms[] = new OperatorExpression($sql, $tree->expression($column, $alias), new NamedParameter($name));
        }

        return match (\count($terms)) {
            0 => null,
            1 => $terms[0],
            default => new LogicalExpression($terms),
        };
    }

    /**
     * Значение фильтра из дерева запроса — скаляр для PDO: date — 'Y-m-d', datetime — с микросекундами и смещением
     * (PostgreSQL сравнивает его с timestamptz точно; с timestamp смещение отбрасывается).
     *
     * @throws \LogicException если значение не скаляр и не дата
     */
    private static function parameter(mixed $value, ?string $type): int|float|string|bool
    {
        if ($value instanceof \DateTimeInterface) {
            return $type === Resource::DATE ? $value->format('Y-m-d') : $value->format('Y-m-d H:i:s.uP');
        }
        if (\is_int($value) || \is_float($value) || \is_string($value) || \is_bool($value)) {
            return $value;
        }

        throw new \LogicException('Unsupported filter value ' . get_debug_type($value));
    }

    /**
     * Клиентская сортировка запроса. Строка принадлежит одному ресурсу, и порядок её строк — это порядок
     * этого ресурса; сортировки 1-1 ресурсов внутри строки на поток не влияют.
     * Используется в HashToMergeRule: непустая сортировка — поток занят клиентом.
     *
     * @return array sort ресурса-владельца из дерева запроса
     */
    public function sort(): array
    {
        return $this->rowOwner()->sort;
    }

    /**
     * Ресурс, которому принадлежит строка: самый левый ResourceNode. 1-1 ресурсы присоединяются справа (inner),
     * поэтому по outer от верха строки всегда спускаемся к ресурсу-владельцу. У запроса из одного ресурса
     * это сам вход.
     *
     * Используется в sort() и в Select\QueryPlanPrinter для подписи строки.
     *
     * @throws \LogicException если слева в строке оказался не ResourceNode — значит, ветка владельца
     *                         не была поднята над строкой
     */
    public function rowOwner(): ResourceNode
    {
        // Спуск по outer: каждый SharedRowJoinNode присоединил к строке ещё один 1-1 ресурс справа
        $node = $this->input;
        while ($node instanceof SharedRowJoinNode) {
            $node = $node->outer;
        }

        // После подъёма веток слева в строке может остаться только ResourceNode
        if (!$node instanceof ResourceNode) {
            throw new \LogicException(message: 'Shared row must start with ResourceNode, got ' . $node::class);
        }

        return $node;
    }

    /**
     * ORDER BY запроса по ресурсу-владельцу строки (см. withSelect()). Каждая колонка — один раз, по первому
     * упоминанию.
     *
     * @throws \LogicException если колонки нет в модели ресурса-владельца
     */
    private function orderBy(Select $select): void
    {
        $tree = ModelTree::of($this->rowOwner()->resource);
        foreach ($this->orderColumns() as $column => $direction) {
            $select->order[] = new OrderByElement($tree->expression($column, 't0'), $direction);
        }
    }

    /**
     * Колонки ORDER BY ресурса-владельца по порядку (см. withSelect()): их же withSelect() передаёт в FROM.
     *
     * @return array<string, OrderByDirection|null> колонка модели → направление (null — по умолчанию, ASC)
     */
    private function orderColumns(): array
    {
        $owner = $this->rowOwner();
        $resource = $owner->resource;

        $columns = [];
        if ($this->order !== null) {
            $columns[$this->order] = null;
        }

        $sort = $owner->sort;
        usort($sort, static fn(array $a, array $b): int => $a['position'] <=> $b['position']);
        foreach ($sort as $field) {
            foreach ($resource::attributeColumns($field['attribute']) as $column) {
                if (!\array_key_exists($column, $columns)) {
                    $columns[$column] = $field['descending'] ? OrderByDirection::DESC : null;
                }
            }
        }

        $primary = $resource::primaryColumn();
        if (!\array_key_exists($primary, $columns)) {
            $columns[$primary] = null;
        }

        return $columns;
    }

    /**
     * Ресурсы строки слева направо и колонки, нужные каждому (см. withSelect()). Порядок обхода тот же, что
     * в from(): outer раньше inner.
     *
     * @param ResourceNode|SharedScanNode|JoinNode $node      узел внутри строки
     * @param list<string>                         $keys      ключи для самого левого ресурса узла
     * @param list<ResourceNode>                   $resources по ссылке: ресурсы строки
     * @param list<array<string, true>>            $needed    по ссылке: колонки модели каждого ресурса (ключи,
     *                                                        в порядке добавления)
     * @param list<list<string>>                   $joinKeys  по ссылке: ключи соединений каждого ресурса, без повторов
     * @return int номер самого левого ресурса узла
     * @throws \LogicException если внутри строки встретился другой владелец
     */
    private function gather(
        ResourceNode|SharedScanNode|JoinNode $node,
        array $keys,
        array &$resources,
        array &$needed,
        array &$joinKeys,
    ): int
    {
        if ($node instanceof ResourceNode) {
            $resource = $node->resource;
            $columns = [$resource::primaryColumn()];
            foreach ($node->fields as $name) {
                array_push($columns, ...$resource::attributeColumns($name));
            }

            $resources[] = $node;
            $needed[] = array_fill_keys([...$columns, ...$keys], true);
            $joinKeys[] = array_values(array_unique($keys));

            return \count($resources) - 1;
        }

        if ($node instanceof SharedScanNode) {
            throw new \LogicException(message: 'Shared row inside another shared row: ' . $node->rowOwner()->resource);
        }

        [$parentColumn, $childColumn] = $node->relation;
        $parent = $this->gather(
            node: $node->outer,
            keys: [...$keys, $parentColumn],
            resources: $resources,
            needed: $needed,
            joinKeys: $joinKeys,
        );

        // inner SharedRowJoinNode — ещё та же строка; inner ветки — отдельный запрос со своим владельцем
        if ($node instanceof SharedRowJoinNode) {
            $this->gather(node: $node->inner, keys: [$childColumn], resources: $resources, needed: $needed, joinKeys: $joinKeys);
        }

        return $parent;
    }

    /**
     * FROM строки: свёртка поддерева из SharedRowJoinNode, веток 1-1 ресурсов и ResourceNode.
     *
     * ON SharedRowJoinNode — только условие связи: колонка relation[0] ресурса-родителя (самый левый ресурс
     * outer) = колонка relation[1] ресурса-ребёнка (самый левый ресурс inner), обе как выражения модели
     * (ModelTree::expression()). Фильтры клиента в ON не идут.
     *
     * @param ResourceNode|SharedScanNode|JoinNode $node   узел внутри строки
     * @param list<array<string, true>>            $needed колонки каждого ресурса строки из gather()
     * @param int                                  $index  по ссылке: номер следующего ресурса строки
     * @return array{FromElement, class-string<Resource>, string} FROM поддерева, его самый левый ресурс
     *                                                             и алиас этого ресурса — для ON родителя
     * @throws \LogicException если внутри строки встретился другой владелец
     */
    private function from(ResourceNode|SharedScanNode|JoinNode $node, array $needed, int &$index): array
    {
        if ($node instanceof ResourceNode) {
            $name = 't' . $index;
            $from = ModelTree::of($node->resource)->from($name, $needed[$index++]);

            return [$from, $node->resource, $name];
        }

        // Владелец внутри владельца бывает только в inner ветки, а туда from() не спускается
        if ($node instanceof SharedScanNode) {
            throw new \LogicException(message: 'Shared row inside another shared row: ' . $node->rowOwner()->resource);
        }

        // Ветка внутри строки: в FROM строки — только её outer, inner — отдельный запрос со своим владельцем
        if (!$node instanceof SharedRowJoinNode) {
            return $this->from(node: $node->outer, needed: $needed, index: $index);
        }

        // outer — строка до этого 1-1, inner — 1-1 ресурс (возможно, со своими 1-1)
        [$left, $parent, $parentAlias] = $this->from(node: $node->outer, needed: $needed, index: $index);
        [$right, $child, $childAlias] = $this->from(node: $node->inner, needed: $needed, index: $index);
        [$parentColumn, $childColumn] = $node->relation;

        $join = new JoinExpression($left, $right, JoinType::LEFT);
        // В ON — только условие связи; фильтры клиента идут в WHERE
        $join->on = new OperatorExpression(
            '=',
            ModelTree::of($parent)->expression($parentColumn, $parentAlias),
            ModelTree::of($child)->expression($childColumn, $childAlias),
        );

        return [$join, $parent, $parentAlias];
    }
}
