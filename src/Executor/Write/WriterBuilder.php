<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor\Write;

use Ggs\JsonApiEngine\Executor\Delete\DeleteEntityWriter;
use Ggs\JsonApiEngine\Executor\Insert\InsertOptionalEntityWriter;
use Ggs\JsonApiEngine\Executor\Insert\InsertResourceWriter;
use Ggs\JsonApiEngine\Executor\Insert\InsertEntityJoinWriter;
use Ggs\JsonApiEngine\Executor\Insert\InsertEntityWriter;
use Ggs\JsonApiEngine\Executor\PushExecutorInterface;
use Ggs\JsonApiEngine\Executor\EntityWriterInterface;
use Ggs\JsonApiEngine\Executor\Update\UpdateOptionalEntityWriter;
use Ggs\JsonApiEngine\Executor\Update\UpdateResourceWriter;
use Ggs\JsonApiEngine\Executor\Update\UpdateEntityJoinWriter;
use Ggs\JsonApiEngine\Executor\Update\UpdateEntityWriter;
use Ggs\JsonApiEngine\Parser\Write\WriteOperation;
use Ggs\JsonApiEngine\Plan\Insert\InsertOptionalEntityNode;
use Ggs\JsonApiEngine\Plan\Insert\InsertEntityJoinNode;
use Ggs\JsonApiEngine\Plan\Insert\InsertEntityNode;
use Ggs\JsonApiEngine\Plan\Update\UpdateOptionalEntityNode;
use Ggs\JsonApiEngine\Plan\Update\UpdateEntityJoinNode;
use Ggs\JsonApiEngine\Plan\Update\UpdateEntityNode;
use Ggs\JsonApiEngine\Executor\Delete\DeleteForeignDataWriter;
use Ggs\JsonApiEngine\Executor\Insert\InsertForeignDataWriter;
use Ggs\JsonApiEngine\Executor\Update\UpdateForeignDataWriter;
use Ggs\JsonApiEngine\Model\DeleteForeignDataInterface;
use Ggs\JsonApiEngine\Model\ForeignDataSource;
use Ggs\JsonApiEngine\Model\InsertForeignDataInterface;
use Ggs\JsonApiEngine\Model\UpdateForeignDataInterface;
use Ggs\JsonApiEngine\Plan\Delete\DeleteEntityNode;
use Ggs\JsonApiEngine\Plan\Delete\DeleteForeignDataNode;
use Ggs\JsonApiEngine\Plan\Insert\InsertForeignDataNode;
use Ggs\JsonApiEngine\Plan\Update\UpdateForeignDataNode;
use Ggs\JsonApiEngine\Plan\Write\KeepForeignDataNode;
use Ggs\JsonApiEngine\Plan\Update\UpdateResourceNode;
use Ggs\JsonApiEngine\Plan\Write\KeepEntityNode;
use Ggs\JsonApiEngine\Plan\Write\WriteJoinNode;
use Ggs\JsonApiEngine\Plan\Write\WriteLinkedKeyNode;
use Ggs\JsonApiEngine\Plan\Write\WriteOperationNode;
use Ggs\JsonApiEngine\Plan\Write\EntityReplaceNode;
use Ggs\JsonApiEngine\Plan\Write\WriteRootNode;
use Ggs\JsonApiEngine\Resource;
use Ggs\JsonApiEngine\Resource\DeserializeInterface;
use LogicException;
use PDO;
use Psr\Container\ContainerInterface;

/**
 * Собирает по плану записи (`Plan\Write\WritePlanner`) дерево исполнителей (`…Writer`). Пара к ReaderBuilder: тот
 * строит читателей по плану SELECT.
 *
 * Поток: неизменяемое дерево плана → `WriteRootWriter` (`getRoot()`) над деревом `PushExecutorInterface` на одно выполнение (план
 * переиспользуемый). Чего нет в плане, даёт вызывающий: корни дерева операций и id родителя из вложенного маршрута.
 *
 * Узел плана и исполнитель соответствуют по основе имени: `WriteRootNode` → `WriteRootWriter`, `EntityRouteParentNode` →
 * `EntityRouteParentWriter` (с id из маршрута), `WriteJoinNode` → `WriteJoinWriter`, `EntityReplaceNode` →
 * `EntityReplaceWriter`, `WriteOperationNode` → `WriteOperationWriter`; ветви места — `InsertResourceNode` →
 * `InsertResourceWriter`, `UpdateResourceNode` → `UpdateResourceWriter`, `KeepEntityNode` →
 * `KeepEntityWriter`, `WriteLinkedKeyNode` (сторож над ветвью) → `WriteLinkedKeyWriter`, `DeleteEntityNode` →
 * `DeleteEntityWriter`; уровень таблиц — `InsertEntityJoinNode` → `InsertEntityJoinWriter`,
 * `InsertOptionalEntityNode` → `InsertOptionalEntityWriter`, `InsertEntityNode` → `InsertEntityWriter`
 * (`getInsertEntities()`), `UpdateEntityJoinNode` → `UpdateEntityJoinWriter`, `UpdateOptionalEntityNode` →
 * `UpdateOptionalEntityWriter`, `UpdateEntityNode` → `UpdateEntityWriter` (`getUpdateEntities()`).
 *
 * Сам сборщик состояния выполнения не хранит; исполнители, которые выполняют SQL, получают его PDO, проверки
 * существования — флаг `lock`.
 *
 * Контейнер зависимостей (PSR-11) — источник объектов клиентских классов, как у `ReaderBuilder`: объект по имени
 * класса даёт сборщик, создаёт его контейнер приложения. Так сборщик берёт ресурсы: в плане ресурс — имя класса,
 * а `InsertResourceWriter` и `UpdateResourceWriter` (они превращают данные операции в строку) получают объект
 * `container->get(класс ресурса)` — всегда, и у ресурса без зависимостей тоже. Им же сборщик даёт десериализатор
 * (строка из данных операции): `ResourceDeserializer` — по описанию ресурса, а для ресурса, реализующего
 * `Resource\DeserializeInterface`, — `ManualResourceDeserializer` (метод `deserialize()` ресурса); выбор — по классу
 * ресурса из плана. Ресурса нет в контейнере —
 * исключение контейнера; контейнер отдал объект другого класса — LogicException; обе — при сборке. Остальным
 * исполнителям хватает имени класса: они читают только описание.
 *
 * Чужие источники (`Model\ForeignDataSource`): узлы `InsertForeignDataNode`, `UpdateForeignDataNode` (входы узлов
 * ресурса), `KeepForeignDataNode`, `DeleteForeignDataNode` отображаются в `InsertForeignDataWriter`,
 * `UpdateForeignDataWriter`, `KeepForeignDataWriter`, `DeleteForeignDataWriter`. Объект источника сборщик берёт из
 * контейнера по классу из плана; аргументы маршрута (`$arguments` у `getRoot()` и `build()`) — значения одного
 * выполнения, в плане их нет — передаёт каждому такому исполнителю как есть. Вид источника сборщик не выясняет:
 * это сделал `WritePlanner`.
 */
final readonly class WriterBuilder
{
    /**
     * @param bool $lock `FOR KEY SHARE` в проверках существования (PostgreSQL); false — SQLite
     */
    public function __construct(
        private PDO $pdo,
        private bool $lock,
        private ContainerInterface $container,
    ) {}

    /**
     * Корень записи. Значения, которых нет в плане, даёт вызывающий: корни дерева операций и id родителя из маршрута.
     *
     * @param list<WriteOperation> $data      корни дерева операций
     * @param int|null             $route_id  id родителя из вложенного маршрута; обязателен, если в плане есть `route`
     * @param array<string, mixed> $arguments аргументы маршрута — их получает каждый чужой источник, как есть
     */
    public function getRoot(WriteRootNode $plan, array $data, ?int $route_id = null, array $arguments = []): WriteRootWriter
    {
        if (($plan->route === null) !== ($route_id === null)) {
            throw new LogicException('route id must be given exactly when the plan has a route parent');
        }

        return new WriteRootWriter(
            root: $this->build(plan: $plan->input, arguments: $arguments),
            data: $data,
            route: $plan->route === null ? null : new EntityRouteParentWriter(
                pdo: $this->pdo,
                parent: $plan->route->parent,
                relation: $plan->route->relation,
                id: (int) $route_id,
                lock: $this->lock,
            ),
        );
    }

    /**
     * Дерево уровня ресурсов: связи, замены и развилки мест.
     *
     * @param array<string, mixed> $arguments аргументы маршрута для чужих источников
     */
    public function build(WriteJoinNode|EntityReplaceNode|WriteOperationNode $plan, array $arguments = []): PushExecutorInterface
    {
        if ($plan instanceof WriteJoinNode) {
            return new WriteJoinWriter(
                name: $plan->name,
                relation: $plan->relation,
                parent: $this->build(plan: $plan->parent, arguments: $arguments),
                child: $this->build(plan: $plan->child, arguments: $arguments),
            );
        }
        if ($plan instanceof EntityReplaceNode) {
            return new EntityReplaceWriter(
                pdo: $this->pdo,
                name: $plan->name,
                relation: $plan->relation,
                child_resource: $plan->child_resource,
                input: $this->build(plan: $plan->input, arguments: $arguments),
            );
        }

        return new WriteOperationWriter(
            resource: $plan->resource,
            add: $plan->add === null ? null : new InsertResourceWriter(
                resource: $this->getResource(resource: $plan->add->resource),
                deserializer: $this->getDeserializer(resource: $plan->add->resource),
                input: $plan->add->input instanceof InsertForeignDataNode
                    ? new InsertForeignDataWriter(
                        source: $this->getForeignDataSource(source: $plan->add->input->source, capability: InsertForeignDataInterface::class),
                        arguments: $arguments,
                    )
                    : $this->getInsertEntities(plan: $plan->add->input),
            ),
            update: $plan->update === null ? null : $this->getBranchWriter(plan: $plan->update, arguments: $arguments),
            keep: $plan->keep === null ? null : $this->getBranchWriter(plan: $plan->keep, arguments: $arguments),
            remove: $plan->remove === null ? null : $this->getBranchWriter(plan: $plan->remove, arguments: $arguments),
        );
    }

    /**
     * Исполнитель ветви места, у которой есть id ресурса: изменение, поиск, удаление — или сторож над ней.
     * Узел сущности — исполнитель с SQL, узел чужого источника — исполнитель, который вызывает метод источника.
     *
     * @param array<string, mixed> $arguments аргументы маршрута для чужих источников
     */
    private function getBranchWriter(
        UpdateResourceNode|KeepEntityNode|KeepForeignDataNode|DeleteEntityNode|DeleteForeignDataNode|WriteLinkedKeyNode $plan,
        array $arguments,
    ): PushExecutorInterface {
        if ($plan instanceof WriteLinkedKeyNode) {
            return new WriteLinkedKeyWriter(
                key: $plan->key,
                input: $this->getBranchWriter(plan: $plan->input, arguments: $arguments),
            );
        }
        if ($plan instanceof UpdateResourceNode) {
            return new UpdateResourceWriter(
                resource: $this->getResource(resource: $plan->resource),
                deserializer: $this->getDeserializer(resource: $plan->resource),
                input: $plan->input instanceof UpdateForeignDataNode
                    ? new UpdateForeignDataWriter(
                        source: $this->getForeignDataSource(source: $plan->input->source, capability: UpdateForeignDataInterface::class),
                        arguments: $arguments,
                    )
                    : $this->getUpdateEntities(plan: $plan->input),
            );
        }
        if ($plan instanceof KeepForeignDataNode) {
            return new KeepForeignDataWriter(
                resource: $plan->resource,
                source: $this->getForeignDataSource(source: $plan->source, capability: UpdateForeignDataInterface::class),
                arguments: $arguments,
            );
        }
        if ($plan instanceof DeleteForeignDataNode) {
            return new DeleteForeignDataWriter(
                resource: $plan->resource,
                source: $this->getForeignDataSource(source: $plan->source, capability: DeleteForeignDataInterface::class),
                arguments: $arguments,
            );
        }
        if ($plan instanceof KeepEntityNode) {
            return new KeepEntityWriter(
                pdo: $this->pdo,
                resource: $plan->resource,
            );
        }

        return new DeleteEntityWriter(
            pdo: $this->pdo,
            resource: $plan->resource,
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

    /**
     * Объект чужого источника из контейнера зависимостей.
     *
     * @template TCapability of object
     * @param class-string<ForeignDataSource>     $source     класс источника из плана
     * @param class-string<TCapability>           $capability интерфейс операции
     * @return ForeignDataSource&TCapability
     * @throws LogicException если контейнер отдал не этот источник
     */
    private function getForeignDataSource(string $source, string $capability): ForeignDataSource
    {
        $object = $this->container->get($source);
        if (!$object instanceof $source || !$object instanceof $capability) {
            throw new LogicException('Container returned ' . get_debug_type($object) . " for data source {$source}");
        }

        return $object;
    }

    /**
     * Чем писатель ресурса строит строку из операции: ресурс реализует `DeserializeInterface` — его методом
     * `deserialize()` (`ManualResourceDeserializer`), иначе по описанию (`ResourceDeserializer`).
     *
     * @param class-string<Resource> $resource
     */
    private function getDeserializer(string $resource): ResourceDeserializer
    {
        if (is_a($resource, DeserializeInterface::class, true)) {
            return new ManualResourceDeserializer(
                resource: $this->getResource(resource: $resource),
            );
        }

        return new ResourceDeserializer(
            resource: $this->getResource(resource: $resource),
        );
    }

    /**
     * Поддерево таблиц вставки по плану `InsertPlanner::getEntities()`.
     */
    public function getInsertEntities(InsertEntityJoinNode|InsertEntityNode|InsertOptionalEntityNode $plan): EntityWriterInterface
    {
        if ($plan instanceof InsertEntityJoinNode) {
            return new InsertEntityJoinWriter(
                relation: $plan->relation,
                parent: $this->getInsertEntities(plan: $plan->parent),
                child: $this->getInsertEntities(plan: $plan->child),
            );
        }
        if ($plan instanceof InsertOptionalEntityNode) {
            return new InsertOptionalEntityWriter(
                input: $this->getInsertEntities(plan: $plan->input),
            );
        }

        return new InsertEntityWriter(
            pdo: $this->pdo,
            entity: $plan->entity,
            columns: $plan->columns,
        );
    }

    /**
     * Поддерево таблиц изменения по плану `UpdatePlanner::getEntities()`.
     */
    public function getUpdateEntities(UpdateEntityJoinNode|UpdateEntityNode|UpdateOptionalEntityNode $plan): EntityWriterInterface
    {
        if ($plan instanceof UpdateEntityJoinNode) {
            return new UpdateEntityJoinWriter(
                relation: $plan->relation,
                main: $this->getUpdateEntities(plan: $plan->main),
                joined: $this->getUpdateEntities(plan: $plan->joined),
            );
        }
        if ($plan instanceof UpdateOptionalEntityNode) {
            return new UpdateOptionalEntityWriter(
                update: $this->getUpdateEntity(plan: $plan->update),
                insert: new InsertEntityWriter(
                    pdo: $this->pdo,
                    entity: $plan->insert->entity,
                    columns: $plan->insert->columns,
                ),
            );
        }

        return $this->getUpdateEntity(plan: $plan);
    }

    private function getUpdateEntity(UpdateEntityNode $plan): UpdateEntityWriter
    {
        return new UpdateEntityWriter(
            pdo: $this->pdo,
            entity: $plan->entity,
            columns: $plan->columns,
            key: $plan->key,
            returning: $plan->returning,
        );
    }
}
