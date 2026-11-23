<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Select;

use Ggs\JsonApiEngine\Model\ModelTree;
use Ggs\JsonApiEngine\Operator;
use Ggs\JsonApiEngine\Resource;
use sad_spirit\pg_builder\enums\IsPredicate;
use sad_spirit\pg_builder\enums\JoinType;
use sad_spirit\pg_builder\enums\LogicalOperator;
use sad_spirit\pg_builder\enums\OrderByDirection;
use sad_spirit\pg_builder\nodes\ColumnReference;
use sad_spirit\pg_builder\nodes\CommonTableExpression;
use sad_spirit\pg_builder\nodes\expressions\InExpression;
use sad_spirit\pg_builder\nodes\expressions\IsDistinctFromExpression;
use sad_spirit\pg_builder\nodes\expressions\IsExpression;
use sad_spirit\pg_builder\nodes\expressions\LogicalExpression;
use sad_spirit\pg_builder\nodes\expressions\NamedParameter;
use sad_spirit\pg_builder\nodes\expressions\OperatorExpression;
use sad_spirit\pg_builder\nodes\Identifier;
use sad_spirit\pg_builder\nodes\lists\TargetList;
use sad_spirit\pg_builder\nodes\OrderByElement;
use sad_spirit\pg_builder\nodes\QualifiedName;
use sad_spirit\pg_builder\nodes\range\FromElement;
use sad_spirit\pg_builder\nodes\range\JoinExpression;
use sad_spirit\pg_builder\nodes\range\RelationReference;
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
 * со своим владельцем. Ставится шагом EntityScanNodeDraftPass, после того как ветки владельца подняты
 * над строкой.
 *
 * Ровно один вход — верх строки: EntitySharedJoinNode (ресурс с 1-1) или ResourceNode (один ресурс).
 * Тип свойства не даёт подставить туда ветку: ветки владельца стоят над ним.
 *
 * Только у владельца хранится порядок потока (order). Запрос владелец собирает сам из своего поддерева
 * (withSelect()): FROM — свёртка узлов под ним (ResourceNode → модель ресурса, EntitySharedJoinNode → LEFT JOIN
 * по relation, ветка внутри строки → её outer), список колонок — нужные колонки каждого ресурса строки; условия
 * родительских запросов — цепочкой предков (WITH p1, p2, … и IN, см. withSelect()). Собранный запрос с картой
 * колонок строки и параметрами живёт уже не здесь, а в EntityScanNode: withSelect() возвращает его, и BuildSqlPass
 * (шаг sql) ставит его вместо этого узла. В плане после шага sql EntityScanNodeDraft не остаётся.
 *
 * Имя «Shared» историческое: при одном ресурсе строка ни с кем не делится. При выполнении такой владелец
 * не нуждается в EntitySharedWindowReader — это решение сборщика дерева читателей, а не плана.
 *
 * Иммутабелен: свойства readonly, изменения — через with…(), которые возвращают копию (withSelect() — новый узел
 * EntityScanNode).
 *
 * @psalm-type Ancestor = array{
 *     node: EntityScanNodeDraft,
 *     resource: ResourceNode,
 *     key: string,
 *     column: string,
 * }
 */
final class EntityScanNodeDraft
{
    /**
     * @param EntitySharedJoinNode|ResourceNode $input   верх строки: цепочка EntitySharedJoinNode, в которой собраны
     *                                                все 1-1 ресурсы запроса, или единственный ресурс запроса;
     *                                                самый левый ResourceNode — ресурс-владелец
     * @param string|null                    $order   колонка, по которой поток упорядочен ради MergeNestJoinNode.
     *                                                Проставляет HashToMergeRule; null — порядок ещё свободен
     */
    public function __construct(
        public readonly EntitySharedJoinNode|ResourceNode $input,
        public readonly ?string $order = null,
    ) {}

    /**
     * Копия с другим верхом строки. Вызывают Rule::transformUp и BuildSqlPass, когда что-то изменилось
     * внутри строки. Тот же вход — тот же узел: копия не создаётся, и неизменённое дерево остаётся общим.
     *
     * @param EntitySharedJoinNode|ResourceNode $input новый верх строки
     */
    public function withInput(EntitySharedJoinNode|ResourceNode $input): static
    {
        return $input === $this->input
            ? $this
            : clone(object: $this, withProperties: ['input' => $input]);
    }

    /**
     * Копия, упорядоченная по ключу связи. Вызывает HashToMergeRule, когда занимает поток под Merge.
     * Проверку, что порядок свободен (order === null и getSort() пуст), делает вызывающий.
     *
     * @param string $order колонка, по которой должен идти поток
     */
    public function withOrder(string $order): static
    {
        return clone(object: $this, withProperties: ['order' => $order]);
    }

    /**
     * Собранный запрос владельца — новый узел EntityScanNode с тем же входом и order, запросом (select), его
     * параметрами (parameters) и картой колонок строки (columns: алиас, колонки, fields и ключи соединений каждого
     * ресурса). Вызывает BuildSqlPass, когда дерево уже окончательное, и ставит результат вместо этого узла.
     *
     * Ресурсы строки обходятся слева направо, как в FROM; каждому — алиас t0, t1, … (у таблиц внутри модели
     * ресурса — t0_1, t0_2, …, их задаёт ModelTree). Колонки ресурса в запросе:
     *  - первичный ключ — всегда: он даёт id, а NULL в нём значит, что ресурса в строке нет (LEFT JOIN);
     *  - колонки атрибутов fields (Resource::getAttributeColumns(): у составного — все, у связи по внешнему
     *    ключу — колонка ключа);
     *  - колонки, которые ресурс запросил сам, — Resource::getRequiredColumns() с fields узла: их нет у атрибутов
     *    fields, но они нужны сериализации ресурса; попадают в список и в карту columns, атрибутами не становятся;
     *  - ключи соединений внутри строки: у EntitySharedJoinNode родителю (самый левый ресурс outer) — relation[0],
     *    ребёнку (самый левый ресурс inner) — relation[1]; у ветки внутри строки ресурсу outer — relation[0];
     *  - $keys — ключи веток над строкой, их передаёт BuildSqlPass; достаются ресурсу-владельцу.
     *
     * Каждая колонка выводится как <алиас ресурса>_<колонка модели> (t1_buyer_email): алиасы ресурсов уникальны
     * в запросе, колонки — в модели, поэтому имена не совпадают, даже если у двух таблиц есть certificate_id.
     * ModelTree::getFrom() получает эти колонки, колонки фильтров ресурса и (у владельца) колонки ORDER BY
     * и отбрасывает ненужные LEFT JOIN внутри модели ресурса; колонки ON попадают в набор как ключи, поэтому их
     * таблицы остаются. Колонки фильтров и сортировки в список колонок не выводятся.
     *
     * WHERE — фильтры ресурсов строки (filters узлов), все через AND; в ON они не идут:
     *  - фильтр ресурса-владельца (t0) — условие как есть: t0.status = :t0_status_0;
     *  - фильтры 1-1 ресурса (он в строке через LEFT JOIN) — одной группой (t1.pk IS NULL OR (усл. AND …)):
     *    строка без этого ресурса остаётся, а строка, где ресурс есть, но не прошёл фильтр, выпадает;
     *  - колонка — Resource::getFilterColumn() (у связи по внешнему ключу — колонка ключа), выражение —
     *    ModelTree::getExpression(); операторы eq, lt, gt, lte, gte — =, <, >, <=, >=; ne — IS DISTINCT FROM (а не <>:
     *    строка с NULL в колонке тоже считается «не равной» значению и остаётся);
     *  - значение — именованный параметр :<алиас>_<колонка>_<номер> (номер — по порядку в запросе, поэтому
     *    два условия на одну колонку не совпадают); значения — в parameters, уже скаляры: date — 'Y-m-d',
     *    datetime — 'Y-m-d H:i:s.uP'.
     * TODO: оператор in (Operator::In) — LogicException (от парсера не приходит: он отклоняет in сам, 400).
     *
     * Каждый вызов строит новые объекты pg_builder: узел pg_builder принадлежит одному дереву.
     * Запросы веток внутри строки (их inner) сюда не входят — у них свои владельцы.
     *
     * ORDER BY — порядок строк, то есть ресурса-владельца (алиас t0); сортировки 1-1 ресурсов на него не влияют:
     *  - order — ключ, по которому поток занял HashToMergeRule (порядок ключей на входах MergeNestJoinReader проверяет AscendingKeyReader);
     *  - клиентский sort владельца по position, с DESC у descending; у составного атрибута — все его колонки.
     *    order и sort вместе не бывают: HashToMergeRule не занимает поток с клиентской сортировкой;
     *  - первичный ключ владельца последним, если его ещё нет: порядок однозначен, и строки с одинаковым
     *    ключом не меняются местами от запуска к запуску.
     *
     * Цепочка предков ($ancestors) — условия родительских запросов вместо списка их ключей. Каждый предок —
     * запрос родителя по соединению не 1-1 (HashNestJoinNode, MergeNestJoinNode, HashJoinNode; не PreloadNode)
     * и становится CTE p1, p2, … (p1 — ближайший):
     *  - тело CTE — FROM строки предка как есть (все её 1-1 ресурсы через LEFT JOIN, ничего не отбрасывается) и
     *    WHERE строки предка целиком (фильтры владельца и группы 1-1 ресурсов), без ORDER BY; в списке колонок
     *    только ключ связи key — колонка модели ресурса resource строки предка (владельца строки или 1-1 ресурса),
     *    выводится под своим именем;
     *  - предки предка — тоже его условие: тело p{n} получает WHERE <его владелец>.<column предка n+1>
     *    IN (select <key предка n+1> from p{n+1}); список WITH плоский, от дальнего к ближнему (pn, …, p1):
     *    CTE ссылается только на объявленные раньше;
     *  - сам запрос получает WHERE t0.<column ближайшего предка> IN (select <key> from p1) — полусоединение:
     *    без ограничения числа родителей, строки не размножаются, уникальность ключа не важна;
     *  - алиасы внутри каждого CTE снова с t0 (своя область видимости); параметры фильтров предка — с префиксом
     *    CTE (:p1_t0_status_0) и попадают в parameters этого запроса.
     * Так база отдаёт только детей тех родителей, что попадут в ответ: условие совпадает с родительским точно.
     * CTE ставится всегда, даже если WHERE предка пуст: таблица сущности может быть view со своими условиями
     * (например, по активному пользователю) или таблицей под RLS, и какие строки родителя видны, знает только
     * запрос предка. Без CTE ребёнок прочитал бы детей невидимых родителей — это и лишние строки, и обход
     * ограничений доступа на дочерних таблицах. Не убирать как «лишний» (решение пользователя).
     *
     * @param list<string>   $keys      колонки модели ресурса-владельца, нужные веткам над строкой (их relation[0])
     *                                  и соединению, в inner которого стоит этот запрос (relation[1])
     * @param list<Ancestor> $ancestors цепочка предков от ближайшего: запрос родителя (node, до шага sql или после —
     *                                  нужны только его строка и фильтры), ресурс его строки с ключом связи
     *                                  (resource — тот же объект ResourceNode, что в строке), ключ (key, relation[0])
     *                                  и колонка ребёнка (column, relation[1]) — у владельца строки запроса
     *                                  на уровень ниже (у этого запроса для ближайшего предка)
     * @throws \LogicException если внутри строки встретился другой владелец, колонки нет в модели ресурса,
     *                         фильтр с неподдержанным оператором или ресурса предка нет в его строке
     */
    public function withSelect(array $keys = [], array $ancestors = []): EntityScanNode
    {
        // Сначала CTE: параметры нумеруются в порядке текста запроса (WITH раньше основного запроса)
        $parameters = [];
        $with = self::getAncestry(ancestors: $ancestors, parameters: $parameters);

        [$resources, $selected, $needed, $join_keys, $conditions] = $this->getScanParts(
            keys: $keys,
            fields: true,
            prefix: '',
            parameters: $parameters,
        );

        $targets = [];
        $columns = [];
        foreach ($resources as $index => $resource) {
            $alias = 't' . $index;
            $tree = ModelTree::of($resource->resource);
            $map = [];
            foreach (array_keys($selected[$index]) as $column) {
                $map[$column] = "{$alias}_{$column}";
                // Вызовы pg_builder — позиционные: имена параметров библиотеки не становятся частью нашего кода
                $targets[] = new TargetElement($tree->getExpression($column, $alias), new Identifier($map[$column]));
            }
            $columns[] = [
                'resource' => $resource->resource,
                'alias' => $alias,
                'columns' => $map,
                'fields' => $resource->fields,
                'keys' => $join_keys[$index],
            ];
        }

        // FROM нужны колонки ORDER BY владельца и колонка связи с ближайшим предком
        foreach ($this->getOrderColumns() as $column => $direction) {
            $needed[0][$column] = true;
        }
        if ($ancestors !== []) {
            $needed[0][$ancestors[0]['column']] = true;
            $conditions[] = $this->getSemiJoin(column: $ancestors[0]['column'], key: $ancestors[0]['key'], cte: 'p1');
        }

        $select = new Select(new TargetList($targets));
        foreach ($with as $cte) {
            $select->with[] = $cte;
        }
        $index = 0;
        $select->from[] = $this->getFrom(node: $this->input, needed: $needed, index: $index)[0];
        foreach ($conditions as $condition) {
            $select->where->and($condition);
        }
        $this->orderBy(select: $select);

        return new EntityScanNode(
            input: $this->input,
            order: $this->order,
            select: $select,
            columns: $columns,
            parameters: $parameters,
        );
    }

    /**
     * CTE цепочки предков (см. withSelect()) в порядке WITH: от дальнего предка (pn) к ближнему (p1).
     *
     * @param list<Ancestor>                       $ancestors  цепочка от ближайшего
     * @param array<string, int|float|string|bool> $parameters по ссылке: параметры запроса, сюда добавляются
     *                                                         значения фильтров предков
     * @return list<CommonTableExpression>
     * @throws \LogicException см. getAncestorSelect()
     */
    private static function getAncestry(array $ancestors, array &$parameters): array
    {
        $with = [];
        for ($level = \count($ancestors) - 1; $level >= 0; $level--) {
            $ancestor = $ancestors[$level];
            $name = 'p' . ($level + 1);
            $body = $ancestor['node']->getAncestorSelect(
                resource: $ancestor['resource'],
                key: $ancestor['key'],
                parent: $ancestors[$level + 1] ?? null,
                parent_cte: 'p' . ($level + 2),
                prefix: $name . '_',
                parameters: $parameters,
            );
            $with[] = new CommonTableExpression($body, new Identifier($name));
        }

        return $with;
    }

    /**
     * Тело CTE предка: строка этого запроса как есть, её WHERE и связь с её собственным предком; в списке колонок —
     * только ключ связи (см. withSelect()).
     *
     * @param ResourceNode                         $resource   ресурс строки, которому принадлежит ключ
     * @param string                               $key        колонка модели этого ресурса — ключ связи с ребёнком
     * @param Ancestor|null                        $parent     предок этого запроса или null, если его нет
     * @param string                               $parent_cte  имя CTE предка этого запроса
     * @param string                               $prefix     префикс имён параметров (p1_)
     * @param array<string, int|float|string|bool> $parameters по ссылке: параметры запроса, в котором стоит CTE
     * @throws \LogicException если ресурса нет в строке, колонки нет в модели или оператор фильтра не поддержан
     */
    private function getAncestorSelect(
        ResourceNode $resource,
        string $key,
        ?array $parent,
        string $parent_cte,
        string $prefix,
        array &$parameters,
    ): Select {
        [$resources, , $needed, , $conditions] = $this->getScanParts(keys: [], fields: false, prefix: $prefix, parameters: $parameters);

        $index = array_search($resource, $resources, true);
        if (!\is_int($index)) {
            throw new \LogicException(message: 'Ancestor key resource ' . $resource->resource . ' is not in the row of ' . $this->getRootResourceNode()->resource);
        }
        $needed[$index][$key] = true;
        if ($parent !== null) {
            $needed[0][$parent['column']] = true;
            $conditions[] = $this->getSemiJoin(column: $parent['column'], key: $parent['key'], cte: $parent_cte);
        }

        $select = new Select(new TargetList([
            new TargetElement(ModelTree::of($resource->resource)->getExpression($key, 't' . $index), new Identifier($key)),
        ]));
        $from = 0;
        /** @var list<array<string, true>> $needed */
        $select->from[] = $this->getFrom(node: $this->input, needed: $needed, index: $from)[0];
        foreach ($conditions as $condition) {
            $select->where->and($condition);
        }

        return $select;
    }

    /**
     * Полусоединение с предком: t0.<column> IN (select <key> from <cte>).
     *
     * @param string $column колонка модели ресурса-владельца строки (relation[1])
     * @param string $key    колонка, которую выводит CTE предка (relation[0])
     * @param string $cte    имя CTE предка
     */
    private function getSemiJoin(string $column, string $key, string $cte): InExpression
    {
        $subquery = new Select(new TargetList([new TargetElement(new ColumnReference($key))]));
        $subquery->from[] = new RelationReference(new QualifiedName($cte));

        return new InExpression(ModelTree::of($this->getRootResourceNode()->resource)->getExpression($column, 't0'), $subquery);
    }

    /**
     * Строка запроса: ресурсы слева направо, их колонки и WHERE из фильтров (см. withSelect()). Общая часть
     * запроса владельца и тела CTE, в котором этот запрос — предок.
     *
     * @param list<string>                         $keys       ключи для ресурса-владельца
     * @param bool                                 $fields     нужны ли колонки атрибутов fields и колонки
     *                                                         Resource::getRequiredColumns() (в CTE — нет)
     * @param string                               $prefix     префикс имён параметров ('' или p1_)
     * @param array<string, int|float|string|bool> $parameters по ссылке: параметры запроса
     * @return array{list<ResourceNode>, list<array<string, true>>, list<array<string, true>>, list<list<string>>, list<ScalarExpression>}
     *         ресурсы; колонки списка каждого ресурса; колонки FROM (ещё и колонки фильтров); ключи соединений;
     *         условия WHERE
     * @throws \LogicException если внутри строки другой владелец или оператор фильтра не поддержан
     */
    private function getScanParts(array $keys, bool $fields, string $prefix, array &$parameters): array
    {
        // Ресурсы строки и нужные им колонки — в порядке обхода, номер ресурса — номер его алиаса
        $resources = [];
        $selected = [];
        $join_keys = [];
        $this->gather(node: $this->input, keys: $keys, fields: $fields, resources: $resources, needed: $selected, join_keys: $join_keys);

        $needed = $selected;
        $conditions = [];
        foreach ($resources as $index => $resource) {
            $alias = 't' . $index;

            // FROM нужны и колонки фильтров, которые не выводятся
            foreach ($resource->filters as $filter) {
                $needed[$index][$resource->resource::getFilterColumn($filter['attribute'])] = true;
            }

            $condition = $this->getCondition(resource: $resource, alias: $alias, prefix: $prefix, parameters: $parameters);
            if ($condition !== null) {
                // 1-1 ресурс — в строке через LEFT JOIN: строку без него фильтр не трогает
                $conditions[] = $index === 0 ? $condition : new LogicalExpression(
                    [
                        new IsExpression(
                            ModelTree::of($resource->resource)->getExpression($resource->resource::getPrimaryKeyColumnName(), $alias),
                            IsPredicate::NULL,
                        ),
                        $condition,
                    ],
                    LogicalOperator::OR,
                );
            }
        }

        // Присваивание по индексу не меняет ключи, но Psalm теряет list
        /** @var list<array<string, true>> $needed */
        return [$resources, $selected, $needed, $join_keys, $conditions];
    }

    /**
     * Фильтры одного ресурса строки через AND (см. withSelect()); null — фильтров нет.
     *
     * @param string                               $prefix     префикс имён параметров ('' или p1_ в CTE предка)
     * @param array<string, int|float|string|bool> $parameters по ссылке: параметры запроса, сюда добавляются
     *                                                         значения фильтров ресурса
     * @throws \LogicException если оператор не поддержан
     */
    private function getCondition(ResourceNode $resource, string $alias, string $prefix, array &$parameters): ?ScalarExpression
    {
        $class = $resource->resource;
        $tree = ModelTree::of($class);
        $terms = [];
        foreach ($resource->filters as $filter) {
            $column = $class::getFilterColumn($filter['attribute']);
            $sql = match (Operator::from($filter['operator'])) {
                Operator::Eq => '=',
                // «не равно» — IS DISTINCT FROM, не <>: строка с NULL в колонке тоже «не равна» значению
                Operator::Ne => null,
                Operator::Lt => '<',
                Operator::Gt => '>',
                Operator::Lte => '<=',
                Operator::Gte => '>=',
                // TODO: список значений; через PDO массив одним параметром не передать
                Operator::In => throw new \LogicException("{$class}: filter operator in is not supported yet"),
            };
            $name = "{$prefix}{$alias}_{$column}_" . \count($parameters);
            $parameters[$name] = self::getParameter(value: $filter['value'], type: $class::getAttributeType($filter['attribute']));
            $terms[] = $sql === null
                ? new IsDistinctFromExpression($tree->getExpression($column, $alias), new NamedParameter($name))
                : new OperatorExpression($sql, $tree->getExpression($column, $alias), new NamedParameter($name));
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
    private static function getParameter(mixed $value, ?string $type): int|float|string|bool
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
    public function getSort(): array
    {
        return $this->getRootResourceNode()->sort;
    }

    /**
     * Ресурс, которому принадлежит строка: самый левый ResourceNode. 1-1 ресурсы присоединяются справа (inner),
     * поэтому по outer от верха строки всегда спускаемся к ресурсу-владельцу. У запроса из одного ресурса
     * это сам вход.
     *
     * Используется в getSort() и в SelectPlanPrinter для подписи строки.
     *
     * @throws \LogicException если слева в строке оказался не ResourceNode — значит, ветка владельца
     *                         не была поднята над строкой
     */
    public function getRootResourceNode(): ResourceNode
    {
        // Спуск по outer: каждый EntitySharedJoinNode присоединил к строке ещё один 1-1 ресурс справа
        $node = $this->input;
        while ($node instanceof EntitySharedJoinNode) {
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
        $tree = ModelTree::of($this->getRootResourceNode()->resource);
        foreach ($this->getOrderColumns() as $column => $direction) {
            $select->order[] = new OrderByElement($tree->getExpression($column, 't0'), $direction);
        }
    }

    /**
     * Колонки ORDER BY ресурса-владельца по порядку (см. withSelect()): их же withSelect() передаёт в FROM.
     *
     * @return array<string, OrderByDirection|null> колонка модели → направление (null — по умолчанию, ASC)
     */
    private function getOrderColumns(): array
    {
        $owner = $this->getRootResourceNode();
        $resource = $owner->resource;

        $columns = [];
        if ($this->order !== null) {
            $columns[$this->order] = null;
        }

        $sort = $owner->sort;
        usort($sort, static fn(array $left_sort, array $right_sort): int => $left_sort['position'] <=> $right_sort['position']);
        foreach ($sort as $field) {
            foreach ($resource::getAttributeColumns($field['attribute']) as $column) {
                if (!\array_key_exists($column, $columns)) {
                    $columns[$column] = $field['descending'] ? OrderByDirection::DESC : null;
                }
            }
        }

        $primary = $resource::getPrimaryKeyColumnName();
        if (!\array_key_exists($primary, $columns)) {
            $columns[$primary] = null;
        }

        return $columns;
    }

    /**
     * Ресурсы строки слева направо и колонки, нужные каждому (см. withSelect()). Порядок обхода тот же, что
     * в getFrom(): outer раньше inner.
     *
     * @param ResourceNode|EntityScanNodeDraft|EntityScanNode|ForeignDataScanNode|JoinNode $node      узел внутри строки
     * @param list<string>                                     $keys      ключи для самого левого ресурса узла
     * @param bool                                             $fields    нужны ли колонки атрибутов fields и
     *                                                                    колонки Resource::getRequiredColumns()
     * @param list<ResourceNode>                               $resources по ссылке: ресурсы строки
     * @param list<array<string, true>>            $needed    по ссылке: колонки модели каждого ресурса (ключи,
     *                                                                    в порядке добавления)
     * @param list<list<string>>                               $join_keys  по ссылке: ключи соединений каждого ресурса, без повторов
     * @return int номер самого левого ресурса узла
     * @throws \LogicException если внутри строки встретился другой владелец
     */
    private function gather(
        ResourceNode|EntityScanNodeDraft|EntityScanNode|ForeignDataScanNode|JoinNode $node,
        array $keys,
        bool $fields,
        array &$resources,
        array &$needed,
        array &$join_keys,
    ): int
    {
        if ($node instanceof ResourceNode) {
            $resource = $node->resource;
            $columns = [$resource::getPrimaryKeyColumnName()];
            if ($fields) {
                foreach ($node->fields as $name) {
                    array_push($columns, ...$resource::getAttributeColumns($name));
                }
                // Колонки, которые ресурс просит сам: их нет у запрошенных атрибутов, но они нужны его сериализации
                array_push($columns, ...$resource::getRequiredColumns($node->fields));
            }

            $resources[] = $node;
            $needed[] = array_fill_keys([...$columns, ...$keys], true);
            $join_keys[] = array_values(array_unique($keys));

            return \count($resources) - 1;
        }

        if ($node instanceof EntityScanNodeDraft || $node instanceof EntityScanNode || $node instanceof ForeignDataScanNode) {
            throw new \LogicException(message: 'Shared row inside another shared row: ' . $node->getRootResourceNode()->resource);
        }

        [$parent_column, $child_column] = $node->relation;
        $parent = $this->gather(
            node: $node->outer,
            keys: [...$keys, $parent_column],
            fields: $fields,
            resources: $resources,
            needed: $needed,
            join_keys: $join_keys,
        );

        // inner EntitySharedJoinNode — ещё та же строка; inner ветки — отдельный запрос со своим владельцем
        if ($node instanceof EntitySharedJoinNode) {
            $this->gather(node: $node->inner, keys: [$child_column], fields: $fields, resources: $resources, needed: $needed, join_keys: $join_keys);
        }

        return $parent;
    }

    /**
     * FROM строки: свёртка поддерева из EntitySharedJoinNode, веток 1-1 ресурсов и ResourceNode.
     *
     * ON EntitySharedJoinNode — только условие связи: колонка relation[0] ресурса-родителя (самый левый ресурс
     * outer) = колонка relation[1] ресурса-ребёнка (самый левый ресурс inner), обе как выражения модели
     * (ModelTree::getExpression()). Фильтры клиента в ON не идут.
     *
     * @param ResourceNode|EntityScanNodeDraft|EntityScanNode|ForeignDataScanNode|JoinNode $node   узел внутри строки
     * @param list<array<string, true>>            $needed колонки каждого ресурса строки из gather()
     * @param int                                              $index  по ссылке: номер следующего ресурса строки
     * @return array{FromElement, class-string<Resource>, string} FROM поддерева, его самый левый ресурс
     *                                                                         и алиас этого ресурса — для ON родителя
     * @throws \LogicException если внутри строки встретился другой владелец
     */
    private function getFrom(ResourceNode|EntityScanNodeDraft|EntityScanNode|ForeignDataScanNode|JoinNode $node, array $needed, int &$index): array
    {
        if ($node instanceof ResourceNode) {
            $name = 't' . $index;
            $from = ModelTree::of($node->resource)->getFrom($name, $needed[$index++]);

            return [$from, $node->resource, $name];
        }

        // Владелец внутри владельца бывает только в inner ветки, а туда getFrom() не спускается
        if ($node instanceof EntityScanNodeDraft || $node instanceof EntityScanNode || $node instanceof ForeignDataScanNode) {
            throw new \LogicException(message: 'Shared row inside another shared row: ' . $node->getRootResourceNode()->resource);
        }

        // Ветка внутри строки: в FROM строки — только её outer, inner — отдельный запрос со своим владельцем
        if (!$node instanceof EntitySharedJoinNode) {
            return $this->getFrom(node: $node->outer, needed: $needed, index: $index);
        }

        // outer — строка до этого 1-1, inner — 1-1 ресурс (возможно, со своими 1-1)
        [$left, $parent, $parent_alias] = $this->getFrom(node: $node->outer, needed: $needed, index: $index);
        [$right, $child, $child_alias] = $this->getFrom(node: $node->inner, needed: $needed, index: $index);
        [$parent_column, $child_column] = $node->relation;

        $join = new JoinExpression($left, $right, JoinType::LEFT);
        // В ON — только условие связи; фильтры клиента идут в WHERE
        $join->on = new OperatorExpression(
            '=',
            ModelTree::of($parent)->getExpression($parent_column, $parent_alias),
            ModelTree::of($child)->getExpression($child_column, $child_alias),
        );

        return [$join, $parent, $parent_alias];
    }
}
