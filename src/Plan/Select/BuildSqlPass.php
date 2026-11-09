<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Select;


/**
 * Каждый владелец запроса (SharedScanNode) заменяется на SqlScanNode — тот же вход и order плюс его Select
 * (pg_builder), карта колонок строки и параметры (SharedScanNode::withSelect()). После прохода в дереве нет
 * SharedScanNode: план без этого шага IteratorBuilder и SelectPlanPrinter::queries() отвергают по классу узла. Сам проход обходит дерево и находит владельцев: вне строк — в обеих сторонах
 * соединений, внутри строк — в inner веток 1-1 ресурсов (отдельный запрос со своим владельцем).
 *
 * По пути сверху вниз проход собирает то, что владелец сам не видит, потому что ветки стоят над ним:
 *  - ключи соединений: соединение передаёт в outer пришедшие сверху ключи и свой relation[0] (колонку ресурса,
 *    к которому пишется связь), а в inner — только relation[1]. По outer ключи доходят до владельца строки,
 *    к которой присоединяется ветка (у PreloadNode provider и HashNestJoinNode domains — до строки сертификата);
 *  - цепочку предков: outer получает цепочку соединения как есть, inner соединения не 1-1 (HashNestJoinNode,
 *    MergeNestJoinNode, HashJoinNode) — цепочку с новым ближайшим предком спереди: запрос outer (спуск по outer
 *    до SharedScanNode; у HashJoinNode — запрос строки, в которой он стоит), ресурс с ключом relation[0]
 *    (владелец строки; у HashJoinNode — самый левый ресурс его outer, 1-1 ресурс) и колонка relation[1].
 *    inner PreloadNode цепочки не получает: справочник читается целиком. Из цепочки владелец собирает
 *    WITH p1, p2, … и WHERE … IN (select … from p1) (см. SharedScanNode::withSelect()).
 *
 * Последний шаг: Select появляется в узлах только здесь, когда дерево уже окончательное.
 * Запускается после SharedScanPass: голый ResourceNode вне строки — ошибка порядка шагов, LogicException.
 *
 * @psalm-import-type Ancestor from SharedScanNode
 */
final class BuildSqlPass implements Pass
{
    #[\Override]
    public function __invoke(ResourceNode|SharedScanNode|SqlScanNode|JoinNode $plan): ResourceNode|SharedScanNode|SqlScanNode|JoinNode
    {
        return $this->build(node: $plan, keys: [], ancestors: []);
    }

    /**
     * Вызывается только вне строк.
     *
     * @param ResourceNode|SharedScanNode|SqlScanNode|JoinNode $node      корень поддерева вне строк
     * @param list<string>                                     $keys      колонки, нужные соединениям над узлом, — для ресурса, к
     *                                                                    которому по outer приходит поток узла
     * @param list<Ancestor>                                   $ancestors цепочка предков запроса, к которому по outer приходит
     *                                                                    поток узла, от ближайшего
     * @throws \LogicException если вне строки встретился ResourceNode без владельца
     */
    private function build(ResourceNode|SharedScanNode|SqlScanNode|JoinNode $node, array $keys, array $ancestors): ResourceNode|SharedScanNode|SqlScanNode|JoinNode
    {
        // Владелец: сначала запросы веток внутри его строки, потом его собственный
        if ($node instanceof SharedScanNode) {
            return $node
                ->withInput(input: $this->rowTop(node: $node->input, scan: $node, ancestors: $ancestors))
                ->withSelect(keys: $keys, ancestors: $ancestors);
        }

        if ($node instanceof ResourceNode) {
            throw new \LogicException(message: 'Resource without query owner: ' . $node->resource . ', run SharedScanPass first');
        }

        if ($node instanceof SqlScanNode) {
            throw new \LogicException(message: 'Query owner already has a select: the sql step runs once, row ' . $node->rowOwner()->resource);
        }

        // Ветка: обе стороны — самостоятельные поддеревья со своими владельцами запросов
        [$parent_column, $child_column] = $node->relation;

        // inner повторяет условия запроса outer (кроме справочника PreloadNode)
        $outer = self::owner(node: $node->outer);
        $inner = $node instanceof PreloadNode ? [] : [
            ['node' => $outer, 'resource' => $outer->rowOwner(), 'key' => $parent_column, 'column' => $child_column],
            ...$ancestors,
        ];

        return $node
            ->withOuter(outer: $this->build(node: $node->outer, keys: [...$keys, $parent_column], ancestors: $ancestors))
            ->withInner(inner: $this->build(node: $node->inner, keys: [$child_column], ancestors: $inner));
    }

    /**
     * Запрос поддерева вне строк: спуск по outer до владельца — к нему приходит поток поддерева.
     *
     * @throws \LogicException если по outer нет владельца (дерево не прошло SharedScanPass)
     */
    private static function owner(ResourceNode|SharedScanNode|SqlScanNode|JoinNode $node): SharedScanNode
    {
        while ($node instanceof JoinNode) {
            $node = $node->outer;
        }
        if ($node instanceof SqlScanNode) {
            throw new \LogicException(message: 'Query owner already has a select: the sql step runs once, row ' . $node->rowOwner()->resource);
        }
        if (!$node instanceof SharedScanNode) {
            throw new \LogicException(message: 'Resource without query owner: ' . $node->resource . ', run SharedScanPass first');
        }

        return $node;
    }

    /**
     * Верх строки — вход SharedScanNode. Отдельный метод с точным типом: обе стороны SharedRowJoinNode —
     * та же строка, а withOuter()/withInner() возвращают static, поэтому результат — снова
     * SharedRowJoinNode|ResourceNode, и его можно передать в SharedScanNode::withInput() без проверки типа.
     *
     * @param SharedRowJoinNode|ResourceNode $node      верх строки
     * @param SharedScanNode                 $scan      владелец строки — предок запросов веток внутри неё
     * @param list<Ancestor>                 $ancestors цепочка предков владельца строки
     */
    private function rowTop(SharedRowJoinNode|ResourceNode $node, SharedScanNode $scan, array $ancestors): SharedRowJoinNode|ResourceNode
    {
        if ($node instanceof ResourceNode) {
            return $node;
        }

        return $node
            ->withOuter(outer: $this->inRow(node: $node->outer, scan: $scan, ancestors: $ancestors))
            ->withInner(inner: $this->inRow(node: $node->inner, scan: $scan, ancestors: $ancestors));
    }

    /**
     * Строка изнутри: у веток 1-1 ресурсов inner — отдельный запрос, ему строится Select через build()
     * с ключом relation[1] и цепочкой, в которой ближайший предок — запрос строки с ключом relation[0] у самого
     * левого ресурса outer ветки (у PreloadNode — без цепочки). Ключ relation[0] ресурса outer владелец строки
     * собирает сам (SharedScanNode::withSelect()). Таблицы самой строки (ResourceNode) Select не получают:
     * они часть FROM владельца. Возвращает узел того же класса, что пришёл (with…() — static).
     *
     * @param ResourceNode|SharedScanNode|SqlScanNode|JoinNode $node      узел внутри строки (SharedScanNode там не бывает)
     * @param SharedScanNode                                   $scan      владелец строки
     * @param list<Ancestor>                                   $ancestors цепочка предков владельца строки
     */
    private function inRow(ResourceNode|SharedScanNode|SqlScanNode|JoinNode $node, SharedScanNode $scan, array $ancestors): ResourceNode|SharedScanNode|SqlScanNode|JoinNode
    {
        if (!$node instanceof JoinNode) {
            return $node;
        }

        $node = $node->withOuter(outer: $this->inRow(node: $node->outer, scan: $scan, ancestors: $ancestors));

        // inner SharedRowJoinNode — ещё та же строка; inner ветки — свой запрос
        if ($node instanceof SharedRowJoinNode) {
            return $node->withInner(inner: $this->inRow(node: $node->inner, scan: $scan, ancestors: $ancestors));
        }

        [$parent_column, $child_column] = $node->relation;
        $inner = $node instanceof PreloadNode ? [] : [
            ['node' => $scan, 'resource' => self::leftmost(node: $node->outer), 'key' => $parent_column, 'column' => $child_column],
            ...$ancestors,
        ];

        return $node->withInner(inner: $this->build(node: $node->inner, keys: [$child_column], ancestors: $inner));
    }

    /**
     * Самый левый ресурс поддерева внутри строки — ему достаются ключи outer (как в SharedScanNode::withSelect()).
     *
     * @throws \LogicException если слева не ResourceNode
     */
    private static function leftmost(ResourceNode|SharedScanNode|SqlScanNode|JoinNode $node): ResourceNode
    {
        while ($node instanceof JoinNode) {
            $node = $node->outer;
        }
        if (!$node instanceof ResourceNode) {
            throw new \LogicException(message: 'Branch inside a row must start with ResourceNode, got ' . $node::class);
        }

        return $node;
    }
}
