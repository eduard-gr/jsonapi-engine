<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Select;

use Ggs\JsonApiEngine\Plan\JoinNode;
use Ggs\JsonApiEngine\Plan\Pass;
use Ggs\JsonApiEngine\Plan\ResourceNode;
use Ggs\JsonApiEngine\Plan\SharedRowJoinNode;
use Ggs\JsonApiEngine\Plan\SharedScanNode;

/**
 * Каждому владельцу запроса (SharedScanNode) — его Select (pg_builder) и карта колонок строки
 * (SharedScanNode::withSelect()). Сам проход обходит дерево и находит владельцев: вне строк — в обеих сторонах
 * соединений, внутри строк — в inner веток 1-1 ресурсов (отдельный запрос со своим владельцем).
 *
 * По пути сверху вниз проход собирает ключи соединений, которые владелец сам не видит, потому что ветки стоят
 * над ним: соединение передаёт в outer пришедшие сверху ключи и свой relation[0] (колонку ресурса, к которому
 * пишется связь), а в inner — только relation[1]. По outer ключи доходят до владельца строки, к которой
 * присоединяется ветка (у PreloadNode provider и HashNestJoinNode domains — до строки сертификата).
 *
 * Последний шаг: Select появляется в узлах только здесь, когда дерево уже окончательное.
 * Запускается после SharedScanPass: голый ResourceNode вне строки — ошибка порядка шагов, LogicException.
 */
final class BuildSqlPass implements Pass
{
    #[\Override]
    public function __invoke(ResourceNode|SharedScanNode|JoinNode $plan): ResourceNode|SharedScanNode|JoinNode
    {
        return $this->build(node: $plan, keys: []);
    }

    /**
     * Вызывается только вне строк.
     *
     * @param ResourceNode|SharedScanNode|JoinNode $node корень поддерева вне строк
     * @param list<string>                         $keys колонки, нужные соединениям над узлом, — для ресурса, к
     *                                                   которому по outer приходит поток узла
     * @throws \LogicException если вне строки встретился ResourceNode без владельца
     */
    private function build(ResourceNode|SharedScanNode|JoinNode $node, array $keys): ResourceNode|SharedScanNode|JoinNode
    {
        // Владелец: сначала запросы веток внутри его строки, потом его собственный
        if ($node instanceof SharedScanNode) {
            return $node
                ->withInput(input: $this->rowTop(node: $node->input))
                ->withSelect(keys: $keys);
        }

        if ($node instanceof ResourceNode) {
            throw new \LogicException(message: 'Resource without query owner: ' . $node->resource . ', run SharedScanPass first');
        }

        // Ветка: обе стороны — самостоятельные поддеревья со своими владельцами запросов
        [$parentColumn, $childColumn] = $node->relation;

        return $node
            ->withOuter(outer: $this->build(node: $node->outer, keys: [...$keys, $parentColumn]))
            ->withInner(inner: $this->build(node: $node->inner, keys: [$childColumn]));
    }

    /**
     * Верх строки — вход SharedScanNode. Отдельный метод с точным типом: обе стороны SharedRowJoinNode —
     * та же строка, а withOuter()/withInner() возвращают static, поэтому результат — снова
     * SharedRowJoinNode|ResourceNode, и его можно передать в SharedScanNode::withInput() без проверки типа.
     *
     * @param SharedRowJoinNode|ResourceNode $node верх строки
     */
    private function rowTop(SharedRowJoinNode|ResourceNode $node): SharedRowJoinNode|ResourceNode
    {
        if ($node instanceof ResourceNode) {
            return $node;
        }

        return $node
            ->withOuter(outer: $this->inRow(node: $node->outer))
            ->withInner(inner: $this->inRow(node: $node->inner));
    }

    /**
     * Строка изнутри: у веток 1-1 ресурсов inner — отдельный запрос, ему строится Select через build()
     * с ключом relation[1]. Ключ relation[0] ресурса outer владелец строки собирает сам (SharedScanNode::withSelect()).
     * Таблицы самой строки (ResourceNode) Select не получают: они часть FROM владельца.
     * Возвращает узел того же класса, что пришёл (with…() — static).
     *
     * @param ResourceNode|SharedScanNode|JoinNode $node узел внутри строки (SharedScanNode там не бывает)
     */
    private function inRow(ResourceNode|SharedScanNode|JoinNode $node): ResourceNode|SharedScanNode|JoinNode
    {
        if (!$node instanceof JoinNode) {
            return $node;
        }

        $node = $node->withOuter(outer: $this->inRow(node: $node->outer));

        // inner SharedRowJoinNode — ещё та же строка; inner ветки — свой запрос
        return $node instanceof SharedRowJoinNode
            ? $node->withInner(inner: $this->inRow(node: $node->inner))
            : $node->withInner(inner: $this->build(node: $node->inner, keys: [$node->relation[1]]));
    }
}
