<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor\Select;

use Ggs\JsonApiEngine\Document\Document;
use Ggs\JsonApiEngine\Document\ResourceRegistry;
use Ggs\JsonApiEngine\Executor\PullExecutorInterface;
use Ggs\JsonApiEngine\Plan\Select\HashJoinNode;
use Ggs\JsonApiEngine\Plan\Select\HashNestJoinNode;
use Ggs\JsonApiEngine\Plan\Select\JoinNode;
use Ggs\JsonApiEngine\Plan\Select\MergeNestJoinNode;
use Ggs\JsonApiEngine\Plan\Select\PreloadNode;
use Ggs\JsonApiEngine\Plan\Select\ResourceNode;
use Ggs\JsonApiEngine\Plan\Select\EntitySharedJoinNode;
use Ggs\JsonApiEngine\Plan\Select\EntityScanNodeDraft;
use Ggs\JsonApiEngine\Plan\Select\EntityScanNode;
use Ggs\JsonApiEngine\Plan\Select\ForeignDataScanNode;
use Ggs\JsonApiEngine\Plan\Select\HashOneJoinNode;
use Ggs\JsonApiEngine\Resource;
use Ggs\JsonApiEngine\Resource\SerializeInterface;
use LogicException;
use PDO;
use Psr\Container\ContainerInterface;
use sad_spirit\pg_builder\SqlBuilderWalker;

/**
 * Собирает по плану (после шага sql планировщика) дерево читателей для одного выполнения.
 *
 * Поток: вход — неизменяемое дерево плана (Plan) → выход — дерево читателей (PullExecutorInterface) с состоянием
 * или сразу Document над ним.
 *
 * Узел плана и читатель соответствуют по основе имени. Вне строк:
 *  - EntityScanNode — запрос: EntityScanReader(PDO, SQL select, parameters). С одним ресурсом (вход —
 *    ResourceNode) над ним сразу SerializeReader (под RegisterReader). Со строкой из нескольких ресурсов — EntitySharedWindowReader над
 *    запросом, поток строки (см. ниже) и EntitySharedAdvanceReader, который один раз на строку сдвигает источник;
 *  - ForeignDataScanNode — чтение чужого источника: ForeignDataScanReader(объект источника из контейнера, колонки,
 *    условия и сортировка из узла, аргументы маршрута) под SerializeReader и RegisterReader;
 *  - PreloadNode — PreloadReader(source: outer, preload: inner);
 *  - HashOneJoinNode — HashOneJoinReader (связь 1-1 между потоками, одна из сторон — чужой источник);
 *  - HashNestJoinNode, MergeNestJoinNode — HashNestJoinReader, MergeNestJoinReader с ключами
 *    relation[0] (outer) и relation[1] (inner) и именем связи; у MergeNestJoinReader оба входа обёрнуты
 *    в проверку порядка: outer — UniqueAscendingKeyReader (строго по relation[0]), inner — AscendingKeyReader
 *    (по неубыванию relation[1]).
 * Внутри строки (вход EntityScanNode), где все потоки читают один EntitySharedWindowReader:
 *  - ResourceNode — RegisterReader над SerializeReader с записью EntityScanNode::columns этого ресурса (у ресурса,
 *    реализующего Resource\SerializeInterface, — над ManualSerializeReader: это проверяется по классу ресурса из плана,
 *    здесь и у запроса с одним ресурсом). Записи идут в порядке
 *    обхода строки слева направо (outer раньше inner, у ветки — только outer), и сборщик идёт в том же порядке;
 *  - EntitySharedJoinNode — EntitySharedJoinReader;
 *  - HashJoinNode — HashJoinReader (outer — поток строки, inner — отдельный запрос);
 *  - PreloadNode — PreloadReader (source — поток строки, preload — отдельный запрос).
 *
 * Чего нет в плане, даёт сборщик: PDO — каждому EntityScanReader; ResourceRegistry — каждому RegisterReader
 * и Document (один на выполнение).
 *
 * Контейнер зависимостей (PSR-11) — источник объектов клиентских классов: исполнитель, которому нужен такой объект,
 * получает его от сборщика по имени класса, а создаёт объект со всеми его зависимостями контейнер приложения.
 * Так сборщик берёт ресурсы: в плане ресурс — имя класса (план остаётся описанием без состояния), а каждый
 * SerializeReader получает объект `container->get(класс ресурса)` — всегда, и у ресурса без зависимостей тоже.
 * Ресурса нет в контейнере — исключение контейнера (PSR-11 `NotFoundExceptionInterface`); контейнер отдал объект
 * другого класса — LogicException. Обе ошибки — при сборке, до выполнения. Так же сборщик берёт объекты чужих
 * источников (`Model\ForeignDataSource`) для ForeignDataScanNode: источник — сервис со своими зависимостями.
 *
 * Аргументы маршрута (`$arguments` у build() и getDocument(); `/certificates/3/notes` → `['certificate_id' => 3]`) —
 * значения одного выполнения, в плане их нет: сборщик передаёт их каждому ForeignDataScanReader как есть, что с ними
 * делать, решает источник. Запросы к сущностям их не получают.
 *
 * Дерево читателей одноразовое (см. PullExecutorInterface), план — нет: на каждое выполнение нужен новый вызов
 * build() или getDocument(). getDocument() каждый раз создаёт новый реестр; build() получает реестр снаружи.
 * Сам сборщик состояния выполнения не хранит, его можно переиспользовать.
 *
 * Ошибки плана — LogicException при сборке, до выполнения:
 *  - в плане остался EntityScanNodeDraft (план не прошёл шаг sql: только BuildSqlPass ставит EntityScanNode с запросом),
 *    записей columns меньше, чем ресурсов
 *    строки, или запись другого ресурса;
 *  - вне строки — ResourceNode, EntitySharedJoinNode или HashJoinNode; внутри строки — владелец запроса,
 *    HashNestJoinNode или MergeNestJoinNode (EntityScanNodeDraftPass превращает 1:N внутри строки в HashJoinNode);
 *  - неизвестный класс узла.
 */
final readonly class ReaderBuilder
{
    public function __construct(
        private PDO $pdo,
        private ContainerInterface $container,
    ) {}

    /**
     * Документ JSON:API по плану: дерево читателей с новым реестром. Выполняется при json_encode().
     *
     * @param array<string, mixed> $arguments аргументы маршрута — их получает каждый чужой источник
     */
    public function getDocument(ResourceNode|EntityScanNodeDraft|EntityScanNode|ForeignDataScanNode|JoinNode $plan, array $arguments = []): Document
    {
        $registry = new ResourceRegistry();

        return new Document(flow: $this->build(plan: $plan, registry: $registry, arguments: $arguments), registry: $registry);
    }

    /**
     * Дерево читателей по плану.
     *
     * @param ResourceRegistry     $registry  реестр выполнения: его же должен получить Document
     * @param array<string, mixed> $arguments аргументы маршрута — их получает каждый чужой источник
     * @throws LogicException если план не прошёл шаг sql или узел стоит не на своём месте
     */
    public function build(
        ResourceNode|EntityScanNodeDraft|EntityScanNode|ForeignDataScanNode|JoinNode $plan,
        ResourceRegistry $registry,
        array $arguments = [],
    ): PullExecutorInterface {
        return $this->getNode(node: $plan, registry: $registry, arguments: $arguments);
    }

    /**
     * Узел вне строк.
     *
     * @param array<string, mixed> $arguments аргументы маршрута
     */
    private function getNode(
        ResourceNode|EntityScanNodeDraft|EntityScanNode|ForeignDataScanNode|JoinNode $node,
        ResourceRegistry $registry,
        array $arguments,
    ): PullExecutorInterface
    {
        return match (true) {
            $node instanceof EntityScanNode => $this->getEntityScanReader(node: $node, registry: $registry, arguments: $arguments),
            $node instanceof ForeignDataScanNode => $this->getForeignDataScanReader(node: $node, registry: $registry, arguments: $arguments),
            $node instanceof EntityScanNodeDraft => throw new LogicException(
                'Query owner has no select: run the sql step first, row ' . $node->getRootResourceNode()->resource,
            ),
            $node instanceof PreloadNode => new PreloadReader(
                source: $this->getNode(node: $node->outer, registry: $registry, arguments: $arguments),
                preload: $this->getNode(node: $node->inner, registry: $registry, arguments: $arguments),
            ),
            $node instanceof HashOneJoinNode => new HashOneJoinReader(
                outer: $this->getNode(node: $node->outer, registry: $registry, arguments: $arguments),
                inner: $this->getNode(node: $node->inner, registry: $registry, arguments: $arguments),
                outer_key: $node->relation[0],
                inner_key: $node->relation[1],
                name: $node->name,
            ),
            $node instanceof HashNestJoinNode => new HashNestJoinReader(
                outer: $this->getNode(node: $node->outer, registry: $registry, arguments: $arguments),
                inner: $this->getNode(node: $node->inner, registry: $registry, arguments: $arguments),
                outer_key: $node->relation[0],
                inner_key: $node->relation[1],
                name: $node->name,
            ),
            // Слиянию нужен порядок ключей: на outer ключ уникален (строгая проверка), на inner повторы допустимы
            $node instanceof MergeNestJoinNode => new MergeNestJoinReader(
                outer: new UniqueAscendingKeyReader(
                    source: $this->getNode(node: $node->outer, registry: $registry, arguments: $arguments),
                    key: $node->relation[0],
                ),
                inner: new AscendingKeyReader(
                    source: $this->getNode(node: $node->inner, registry: $registry, arguments: $arguments),
                    key: $node->relation[1],
                ),
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
    private function getEntityScanReader(EntityScanNode $node, ResourceRegistry $registry, array $arguments): PullExecutorInterface
    {
        $columns = $node->columns;
        $query = new EntityScanReader(
            pdo: $this->pdo,
            sql: $node->select->dispatch(new SqlBuilderWalker()),
            parameters: $node->parameters,
        );

        // Один ресурс: строку никто не делит, EntitySharedWindowReader не нужен
        if ($node->input instanceof ResourceNode) {
            $index = 0;

            return $this->getSerializer(node: $node->input, source: $query, columns: $columns, index: $index, registry: $registry);
        }

        $data = new EntitySharedWindowReader(source: $query);
        $index = 0;
        $row = $this->getEntitySharedReader(node: $node->input, data: $data, columns: $columns, index: $index, registry: $registry, arguments: $arguments);
        if ($index !== \count($columns)) {
            throw new LogicException('Query owner columns do not match its row: ' . \count($columns) . " entries, {$index} resources");
        }

        return new EntitySharedAdvanceReader(data: $data, source: $row);
    }

    /**
     * Чтение чужого источника: объект источника из контейнера → ForeignDataScanReader → сериализация ресурса.
     * Всё, что передаётся источнику (колонки, условия, сортировка), посчитал план; сборщик добавляет только
     * значения выполнения — аргументы маршрута.
     *
     * @param array<string, mixed> $arguments аргументы маршрута
     * @throws LogicException если контейнер отдал не этот источник
     */
    private function getForeignDataScanReader(ForeignDataScanNode $node, ResourceRegistry $registry, array $arguments): RegisterReader
    {
        $class = $node->getDataSource();
        $source = $this->container->get($class);
        if (!$source instanceof $class) {
            throw new LogicException('Container returned ' . get_debug_type($source) . " for data source {$class}");
        }
        $index = 0;

        return $this->getSerializer(
            node: $node->input,
            source: new ForeignDataScanReader(
                source: $source,
                columns: $node->getColumns(),
                conditions: $node->getConditions(),
                order: $node->getSortOrder(),
                arguments: $arguments,
            ),
            columns: [$node->getSerializeColumns()],
            index: $index,
            registry: $registry,
        );
    }

    /**
     * Поток узла внутри строки. Ресурсы строки берут записи columns по порядку обхода (outer раньше inner).
     *
     * @param list<array<string, mixed>> $columns   EntityScanNode::columns владельца строки
     * @param int                        $index     по ссылке: номер следующей записи columns
     * @param array<string, mixed>       $arguments аргументы маршрута — для запросов веток внутри строки
     */
    private function getEntitySharedReader(
        ResourceNode|EntityScanNodeDraft|EntityScanNode|ForeignDataScanNode|JoinNode $node,
        EntitySharedWindowReader $data,
        array $columns,
        int &$index,
        ResourceRegistry $registry,
        array $arguments,
    ): PullExecutorInterface {
        if ($node instanceof ResourceNode) {
            return $this->getSerializer(node: $node, source: $data, columns: $columns, index: $index, registry: $registry);
        }
        if ($node instanceof EntityScanNodeDraft || $node instanceof EntityScanNode || $node instanceof ForeignDataScanNode) {
            throw new LogicException('Query owner inside a shared row: ' . $node->getRootResourceNode()->resource);
        }

        // outer — всегда та же строка; читается первым, как в EntityScanNodeDraft::withSelect()
        $outer = $this->getEntitySharedReader(node: $node->outer, data: $data, columns: $columns, index: $index, registry: $registry, arguments: $arguments);

        return match (true) {
            $node instanceof EntitySharedJoinNode => new EntitySharedJoinReader(
                outer: $outer,
                inner: $this->getEntitySharedReader(node: $node->inner, data: $data, columns: $columns, index: $index, registry: $registry, arguments: $arguments),
                name: $node->name,
            ),
            // Ветка 1-1 ресурса: inner — отдельный запрос со своим владельцем
            $node instanceof HashJoinNode => new HashJoinReader(
                outer: $outer,
                inner: $this->getNode(node: $node->inner, registry: $registry, arguments: $arguments),
                outer_key: $node->relation[0],
                inner_key: $node->relation[1],
                name: $node->name,
            ),
            $node instanceof PreloadNode => new PreloadReader(
                source: $outer,
                preload: $this->getNode(node: $node->inner, registry: $registry, arguments: $arguments),
            ),
            default => throw new LogicException('Plan node ' . $node::class . " '{$node->name}' inside a shared row"),
        };
    }

    /**
     * Поток ресурса строки: SerializeReader со следующей записью columns под RegisterReader; у ресурса,
     * реализующего SerializeInterface, — ManualSerializeReader.
     *
     * @param list<array<string, mixed>> $columns
     * @param int                        $index   по ссылке: номер записи; сдвигается на одну
     */
    private function getSerializer(
        ResourceNode $node,
        PullExecutorInterface $source,
        array $columns,
        int &$index,
        ResourceRegistry $registry,
    ): RegisterReader {
        $entry = $columns[$index++] ?? throw new LogicException('Query owner has no columns for ' . $node->resource);

        // Запись проверяет и SerializeReader, но здесь ошибка понятнее: сбился порядок обхода
        if ($entry['resource'] !== $node->resource) {
            throw new LogicException("Columns entry of {$entry['resource']} given to {$node->resource}");
        }

        // Ресурс реализует SerializeInterface — ресурс из строки собирает его serialize(): ручная сериализация
        if (is_a($node->resource, SerializeInterface::class, true)) {
            return new RegisterReader(
                source: new ManualSerializeReader(
                    source: $source,
                    resource: $this->getResource(resource: $node->resource),
                    columns: $entry,
                ),
                registry: $registry,
            );
        }

        return new RegisterReader(
            source: new SerializeReader(
                source: $source,
                resource: $this->getResource(resource: $node->resource),
                columns: $entry,
            ),
            registry: $registry,
        );
    }

    /**
     * Объект ресурса из контейнера зависимостей.
     *
     * @param class-string<Resource> $resource
     * @throws LogicException если контейнер отдал не этот ресурс
     */
    private function getResource(string $resource): Resource
    {
        $object = $this->container->get($resource);
        if (!$object instanceof $resource) {
            throw new LogicException("Container returned " . get_debug_type($object) . " for resource {$resource}");
        }

        return $object;
    }
}
