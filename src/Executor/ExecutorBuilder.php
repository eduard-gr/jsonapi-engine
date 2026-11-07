<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor;

use Ggs\JsonApiEngine\Document\Document;
use Ggs\JsonApiEngine\Document\ResourceRegistry;
use Ggs\JsonApiEngine\Plan\HashJoinNode;
use Ggs\JsonApiEngine\Plan\HashNestJoinNode;
use Ggs\JsonApiEngine\Plan\JoinNode;
use Ggs\JsonApiEngine\Plan\MergeNestJoinNode;
use Ggs\JsonApiEngine\Plan\PreloadNode;
use Ggs\JsonApiEngine\Plan\ResourceNode;
use Ggs\JsonApiEngine\Plan\SharedRowJoinNode;
use Ggs\JsonApiEngine\Plan\SharedScanNode;
use LogicException;
use PDO;
use sad_spirit\pg_builder\SqlBuilderWalker;

/**
 * Собирает по плану (после шага sql планировщика) дерево итераторов для одного выполнения.
 *
 * Поток: вход — неизменяемое дерево плана (Plan) → выход — дерево итераторов (ExecutorIterator) с состоянием
 * или сразу Document над ним.
 *
 * Узел плана и итератор соответствуют по основе имени. Вне строк:
 *  - SharedScanNode — запрос: SqlScanIterator(PDO, SQL select, parameters). С одним ресурсом (вход —
 *    ResourceNode) над ним сразу SerializeIterator. Со строкой из нескольких ресурсов — SharedDataIterator над
 *    запросом, поток строки (см. ниже) и SharedScanIterator, который один раз на строку сдвигает источник;
 *  - PreloadNode — PreloadIterator(source: outer, preload: inner);
 *  - HashNestJoinNode, MergeNestJoinNode — HashNestJoinIterator, MergeNestJoinIterator с ключами
 *    relation[0] (outer) и relation[1] (inner) и именем связи.
 * Внутри строки (вход SharedScanNode), где все потоки читают один SharedDataIterator:
 *  - ResourceNode — SerializeIterator с записью SharedScanNode::columns этого ресурса. Записи идут в порядке
 *    обхода строки слева направо (outer раньше inner, у ветки — только outer), и сборщик идёт в том же порядке;
 *  - SharedRowJoinNode — SharedRowJoinIterator;
 *  - HashJoinNode — HashJoinIterator (outer — поток строки, inner — отдельный запрос);
 *  - PreloadNode — PreloadIterator (source — поток строки, preload — отдельный запрос).
 *
 * Чего нет в плане, даёт сборщик: PDO — каждому SqlScanIterator; ResourceRegistry — каждому SerializeIterator
 * и Document (один на выполнение).
 *
 * Дерево итераторов одноразовое (см. ExecutorIterator), план — нет: на каждое выполнение нужен новый вызов
 * build() или document(). document() каждый раз создаёт новый реестр; build() получает реестр снаружи.
 * Сам сборщик состояния выполнения не хранит, его можно переиспользовать.
 *
 * Ошибки плана — LogicException при сборке, до выполнения:
 *  - у SharedScanNode нет select или columns (план не прошёл шаг sql), записей columns меньше, чем ресурсов
 *    строки, или запись другого ресурса;
 *  - вне строки — ResourceNode, SharedRowJoinNode или HashJoinNode; внутри строки — SharedScanNode,
 *    HashNestJoinNode или MergeNestJoinNode (SharedScanPass превращает 1:N внутри строки в HashJoinNode);
 *  - неизвестный класс узла.
 */
final readonly class ExecutorBuilder
{
    public function __construct(
        private PDO $pdo,
    ) {}

    /**
     * Документ JSON:API по плану: дерево итераторов с новым реестром. Выполняется при json_encode().
     */
    public function document(ResourceNode|SharedScanNode|JoinNode $plan): Document
    {
        $registry = new ResourceRegistry();

        return new Document(flow: $this->build(plan: $plan, registry: $registry), registry: $registry);
    }

    /**
     * Дерево итераторов по плану.
     *
     * @param ResourceRegistry $registry реестр выполнения: его же должен получить Document
     * @throws LogicException если план не прошёл шаг sql или узел стоит не на своём месте
     */
    public function build(ResourceNode|SharedScanNode|JoinNode $plan, ResourceRegistry $registry): ExecutorIterator
    {
        return $this->node(node: $plan, registry: $registry);
    }

    /**
     * Узел вне строк.
     */
    private function node(ResourceNode|SharedScanNode|JoinNode $node, ResourceRegistry $registry): ExecutorIterator
    {
        return match (true) {
            $node instanceof SharedScanNode => $this->scan(node: $node, registry: $registry),
            $node instanceof PreloadNode => new PreloadIterator(
                source: $this->node(node: $node->outer, registry: $registry),
                preload: $this->node(node: $node->inner, registry: $registry),
            ),
            $node instanceof HashNestJoinNode => new HashNestJoinIterator(
                outer: $this->node(node: $node->outer, registry: $registry),
                inner: $this->node(node: $node->inner, registry: $registry),
                outer_key: $node->relation[0],
                inner_key: $node->relation[1],
                name: $node->name,
            ),
            $node instanceof MergeNestJoinNode => new MergeNestJoinIterator(
                outer: $this->node(node: $node->outer, registry: $registry),
                inner: $this->node(node: $node->inner, registry: $registry),
                outer_key: $node->relation[0],
                inner_key: $node->relation[1],
                name: $node->name,
            ),
            $node instanceof ResourceNode => throw new LogicException('Resource outside of a query owner: ' . $node->resource),
            default => throw new LogicException('Plan node ' . $node::class . " '{$node->name}' outside of a shared row"),
        };
    }

    /**
     * Запрос владельца и поток его строки.
     */
    private function scan(SharedScanNode $node, ResourceRegistry $registry): ExecutorIterator
    {
        $select = $node->select ?? throw new LogicException('Query owner has no select: run the sql step first');
        $columns = $node->columns ?? throw new LogicException('Query owner has no columns: run the sql step first');
        $query = new SqlScanIterator(pdo: $this->pdo, sql: $select->dispatch(new SqlBuilderWalker()), parameters: $node->parameters);

        // Один ресурс: строку никто не делит, SharedDataIterator не нужен
        if ($node->input instanceof ResourceNode) {
            $index = 0;

            return $this->serialize(node: $node->input, source: $query, columns: $columns, index: $index, registry: $registry);
        }

        $data = new SharedDataIterator(source: $query);
        $index = 0;
        $row = $this->row(node: $node->input, data: $data, columns: $columns, index: $index, registry: $registry);
        if ($index !== \count($columns)) {
            throw new LogicException('Query owner columns do not match its row: ' . \count($columns) . " entries, {$index} resources");
        }

        return new SharedScanIterator(data: $data, source: $row);
    }

    /**
     * Поток узла внутри строки. Ресурсы строки берут записи columns по порядку обхода (outer раньше inner).
     *
     * @param list<array<string, mixed>> $columns SharedScanNode::columns владельца строки
     * @param int                        $index   по ссылке: номер следующей записи columns
     */
    private function row(
        ResourceNode|SharedScanNode|JoinNode $node,
        SharedDataIterator $data,
        array $columns,
        int &$index,
        ResourceRegistry $registry,
    ): ExecutorIterator {
        if ($node instanceof ResourceNode) {
            return $this->serialize(node: $node, source: $data, columns: $columns, index: $index, registry: $registry);
        }
        if ($node instanceof SharedScanNode) {
            throw new LogicException('Query owner inside a shared row: ' . $node->rowOwner()->resource);
        }

        // outer — всегда та же строка; читается первым, как в SharedScanNode::withSelect()
        $outer = $this->row(node: $node->outer, data: $data, columns: $columns, index: $index, registry: $registry);

        return match (true) {
            $node instanceof SharedRowJoinNode => new SharedRowJoinIterator(
                outer: $outer,
                inner: $this->row(node: $node->inner, data: $data, columns: $columns, index: $index, registry: $registry),
                name: $node->name,
            ),
            // Ветка 1-1 ресурса: inner — отдельный запрос со своим владельцем
            $node instanceof HashJoinNode => new HashJoinIterator(
                outer: $outer,
                inner: $this->node(node: $node->inner, registry: $registry),
                outer_key: $node->relation[0],
                inner_key: $node->relation[1],
                name: $node->name,
            ),
            $node instanceof PreloadNode => new PreloadIterator(
                source: $outer,
                preload: $this->node(node: $node->inner, registry: $registry),
            ),
            default => throw new LogicException('Plan node ' . $node::class . " '{$node->name}' inside a shared row"),
        };
    }

    /**
     * SerializeIterator ресурса строки со следующей записью columns.
     *
     * @param list<array<string, mixed>> $columns
     * @param int                        $index   по ссылке: номер записи; сдвигается на одну
     */
    private function serialize(
        ResourceNode $node,
        ExecutorIterator $source,
        array $columns,
        int &$index,
        ResourceRegistry $registry,
    ): SerializeIterator {
        $entry = $columns[$index++] ?? throw new LogicException('Query owner has no columns for ' . $node->resource);

        // Запись проверяет и SerializeIterator, но здесь ошибка понятнее: сбился порядок обхода
        if ($entry['resource'] !== $node->resource) {
            throw new LogicException("Columns entry of {$entry['resource']} given to {$node->resource}");
        }

        return new SerializeIterator(source: $source, resource: $node->resource, registry: $registry, columns: $entry);
    }
}
