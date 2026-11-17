<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Parser\Write;

use Ggs\JsonApiEngine\Parser\InvalidDocumentException;
use Ggs\JsonApiEngine\Parser\ResourceNotFoundException;
use Ggs\JsonApiEngine\Resource;
use Ggs\JsonApiEngine\Resource\Deletable;
use Ggs\JsonApiEngine\Resource\Insertable;
use Ggs\JsonApiEngine\Resource\OneToMany;
use Ggs\JsonApiEngine\Resource\OneToOne;
use Ggs\JsonApiEngine\Resource\Updatable;

/**
 * Один парсер для POST, PATCH и DELETE: документ → дерево операций (`WriteOperation`). POST — частный случай PATCH:
 * корень новый, у его потомков только `lid` (существующих детей у нового ресурса нет), пометок `meta.op` нет, полной
 * замены нет. Все ошибки клиента при разборе бросаются только здесь; дальше дерево операций считается корректным.
 *
 * Формат документа — JSON:API с двумя дополнениями: новые связанные ресурсы лежат в `included` и названы `lid`
 * (sideposting), а операция над элементом связи to-many — в `meta.op` идентификатора (`add`, `update`, `remove`;
 * правила — в докблоке `RelationshipOperation`). Список to-many без единой пометки — стандартная полная замена
 * JSON:API; хоть одна пометка — частичное изменение. Связь to-one по владению — без `meta.op`, по форме: `{lid}` +
 * included — замена, `{id}` + included — изменение, `{id}` — проверка, `null` — удаление текущего.
 *
 * Поток: документ + класс ресурса точки входа (+ id из маршрута у PATCH) → корни дерева операций. Ресурс с данными —
 * `InsertOperation` или `UpdateOperation` (атрибуты, связи по внешнему ключу `references`, связи по владению
 * `relationships`); идентификатор без ресурса — `KeepOperation` или `DeleteOperation`; связь по владению —
 * `RelationshipOperation` (to-one — тот же список из одной операции или пустой), дети уже в порядке выполнения
 * (удаления, оставленные и изменённые, вставки). Объекты документа, `included`, `lid` как ключ поиска и пометка
 * `meta.op` дальше разбора не идут; место в документе (JSON Pointer) разбор знает сам и в дерево не кладёт.
 *
 * Три входа, общее ядро (`included`, `lid`, атрибуты, связи):
 *  - `insert()` — POST: `data` — ресурс или список ресурсов, каждый — новый корень (`InsertOperation`, `Insertable`,
 *    иначе 403): `type` — ресурс точки входа (иначе 409), `id` нет (клиентский id не поддерживается — 403), `lid` не
 *    обязателен; возвращает список корней. Ответ: один ресурс — `201` + `Location` + `{data: {type, id}}`; список —
 *    `201` без `Location`, `data` — идентификаторы в порядке запроса. Вложенный маршрут
 *    (`POST /certificates/1/domains`) — не дело парсера: родитель — `RouteParentNode` плана, id — у исполнителя
 *    (`RouteParentWriter`);
 *  - `update()` — PATCH: `data` — один ресурс, `type` и `id` совпадают с маршрутом (иначе 409); `UpdateOperation`;
 *  - `delete()` — DELETE: документа нет, корень — `DeleteOperation`, ресурс `Deletable` (иначе 403); ответ `204`,
 *    ресурса нет — 404 при выполнении (`WriteNotFoundException`), id не из цифр — `ResourceNotFoundException`.
 *
 * Проверяет весь документ до первого запроса: форма `attributes`/`relationships` (объекты), атрибут известен,
 * не первичный ключ, не `mode` READ, `null` только у `nullable`; связь известна и у неё есть `data`; по внешнему
 * ключу — `{type, id}` нужного типа или null; по владению — to-many (список) и to-one (ресурс или null); тип и
 * `meta.op` элементов; возможности ресурсов (403): вставка — `Insertable`, изменение (корень PATCH, ребёнок `{id}` +
 * included; изменение связи по внешнему ключу — изменение владельца) — `Updatable`, удаление, полная замена to-many
 * и замена to-one (`lid`, `null`) — `Deletable` цели (по форме документа); у нового ресурса — только новые дети;
 * повторы; каждый ресурс `included` использован ровно один раз (дерево, циклов нет). Ошибки —
 * `InvalidDocumentException` 400/403/409 с JSON Pointer.
 *
 * Правило области запроса: создаются только корень и его потомки по владению; связи по внешнему ключу — только на
 * существующие ресурсы (`{type, id}`). Перенос существующего ребёнка к другому родителю не поддерживается.
 *
 * Не проверяет: типы значений атрибутов и обязательные атрибуты при вставке (NOT NULL без значения по умолчанию —
 * ошибка базы), существование цели связи по внешнему ключу (ошибка внешнего ключа базы).
 *
 * Парсер хранит индекс `included` текущего документа: один вызов — один документ, для следующего документа можно
 * вызвать снова (индекс сбрасывается).
 */
final class WriteDocumentParser
{
    /** @var array<string, array{resource: array, pointer: string}> "type:id" или "type:lid" → ресурс из included */
    private array $included = [];

    /** @var array<string, true> использованные ресурсы included */
    private array $used = [];

    /**
     * POST: каждый элемент `data` (ресурс или непустой список ресурсов) — новый корень. У корня нет `id` (403),
     * `lid` не обязателен, `type` — ресурс точки входа (409). Колонку связи вложенного маршрута
     * (`POST /certificates/1/domains`) парсер не знает: это `RouteParentNode` плана и id из маршрута у исполнителя.
     *
     * @param class-string<Resource> $resource ресурс точки входа (`POST /certificates` → CertificateResource)
     * @return list<InsertOperation> корни дерева операций в порядке `data`
     * @throws InvalidDocumentException
     */
    public function insert(array $document, string $resource): array
    {
        $data = $document['data'] ?? null;
        if (!is_array($data) || $data === []) {
            throw new InvalidDocumentException(
                status: '400',
                pointer: '/data',
                detail: 'data must be a resource or a non-empty list of resources',
            );
        }
        $items = array_is_list($data) ? $data : [$data];
        $pointers = ['/data'];
        if (array_is_list($data)) {
            $pointers = array_map(static fn(int $index): string => "/data/{$index}", array_keys($data));
        }

        $root_keys = [];
        foreach ($items as $index => $item) {
            $pointer = $pointers[$index];
            self::rootType(item: $item, resource: $resource, pointer: $pointer);
            if (isset($item['id'])) {
                throw new InvalidDocumentException(
                    status: '403',
                    pointer: "{$pointer}/id",
                    detail: 'client-generated ids are not supported',
                );
            }
            if (!isset($item['lid'])) {
                continue;
            }
            if (!is_string($item['lid']) || $item['lid'] === '') {
                throw new InvalidDocumentException(
                    status: '400',
                    pointer: "{$pointer}/lid",
                    detail: 'lid must be a non-empty string',
                );
            }
            $key = "{$resource::type()}:lid:{$item['lid']}";
            if (isset($root_keys[$key])) {
                throw new InvalidDocumentException(
                    status: '400',
                    pointer: $pointer,
                    detail: "duplicate resource {$key}",
                );
            }
            $root_keys[$key] = true;
        }
        $this->index(document: $document, root_keys: $root_keys);

        $roots = array_map(
            fn(int $index): InsertOperation => $this->insertOperation(
                item: $items[$index],
                class: $resource,
                pointer: $pointers[$index],
            ),
            array_keys($items),
        );
        $this->checkUsed();

        return $roots;
    }

    /**
     * DELETE `/certificates/{id}`: тела нет (документ JSON:API у DELETE не передаётся) — только проверка возможности и
     * корень `DeleteOperation`. Ресурс не `Deletable` — 403 без указателя (документа нет); id не из цифр —
     * `ResourceNotFoundException` (такого ресурса быть не может). Что удаляется вместе с ресурсом, решает база
     * (каскад, см. `DeleteResourceWriter`); `Deletable` потомков не проверяется.
     *
     * @param class-string<Resource> $resource ресурс точки входа
     * @param string                 $id       id из маршрута
     * @throws InvalidDocumentException
     * @throws ResourceNotFoundException
     */
    public function delete(string $resource, string $id): DeleteOperation
    {
        if (!ctype_digit($id)) {
            throw new ResourceNotFoundException(
                type: $resource::type(),
                parameter: 'id',
                value: $id,
            );
        }
        if (!is_a($resource, Deletable::class, true)) {
            throw new InvalidDocumentException(
                status: '403',
                pointer: null,
                detail: "resource {$resource::type()} can not be deleted",
            );
        }

        return new DeleteOperation(
            resource: $resource,
            identity: new PersistentIdentity(
                id: (int) $id,
            ),
        );
    }

    /**
     * PATCH: `data` — один существующий ресурс точки входа; `type` и `id` совпадают с маршрутом (409).
     *
     * @param class-string<Resource> $resource ресурс точки входа (`PATCH /certificates/{id}` → CertificateResource)
     * @param string                 $id       id из маршрута
     * @throws InvalidDocumentException
     */
    public function update(array $document, string $resource, string $id): UpdateOperation
    {
        $data = $document['data'] ?? null;
        if (!is_array($data) || $data === [] || array_is_list($data)) {
            throw new InvalidDocumentException(
                status: '400',
                pointer: '/data',
                detail: 'data must be a single resource object',
            );
        }
        self::rootType(item: $data, resource: $resource, pointer: '/data');
        if (($data['id'] ?? null) !== $id) {
            throw new InvalidDocumentException(
                status: '409',
                pointer: '/data/id',
                detail: "id must be {$id}, as in the URL",
            );
        }
        $this->index(document: $document, root_keys: ["{$resource::type()}:id:{$id}" => true]);

        $root = $this->updateOperation(item: $data, class: $resource, pointer: '/data');
        $this->checkUsed();

        return $root;
    }

    /**
     * @param class-string<Resource> $resource
     */
    private static function rootType(mixed $item, string $resource, string $pointer): void
    {
        if (!is_array($item) || ($item['type'] ?? null) !== $resource::type()) {
            throw new InvalidDocumentException(
                status: '409',
                pointer: "{$pointer}/type",
                detail: "type must be {$resource::type()}",
            );
        }
    }

    /**
     * Индекс `included` по "type:id:<id>" / "type:lid:<lid>"; повтор (в том числе с корнем) — 400.
     *
     * @param array<string, true> $root_keys ключи корней
     */
    private function index(array $document, array $root_keys): void
    {
        $this->included = [];
        $this->used = [];
        $included = $document['included'] ?? [];
        if (!is_array($included) || !array_is_list($included)) {
            throw new InvalidDocumentException(
                status: '400',
                pointer: '/included',
                detail: 'included must be a list of resources',
            );
        }
        foreach ($included as $index => $item) {
            $pointer = "/included/{$index}";
            $key = self::identity(item: $item, pointer: $pointer);
            if (isset($this->included[$key]) || isset($root_keys[$key])) {
                throw new InvalidDocumentException(
                    status: '400',
                    pointer: $pointer,
                    detail: "duplicate resource {$key}",
                );
            }
            $this->included[$key] = ['resource' => $item, 'pointer' => $pointer];
        }
    }

    /**
     * Отмечает ресурс `included` использованным. Вторая ссылка на тот же ресурс (из другой связи или от другого
     * родителя) — 400: иначе ресурс получил бы двух родителей и был бы записан дважды.
     *
     * @param string $pointer место ссылки в документе
     */
    private function markUsed(string $key, string $pointer): void
    {
        if (isset($this->used[$key])) {
            throw new InvalidDocumentException(
                status: '400',
                pointer: $pointer,
                detail: "resource {$key} is linked more than once",
            );
        }
        $this->used[$key] = true;
    }

    /**
     * Каждый ресурс `included` использован (достижим из `data`).
     */
    private function checkUsed(): void
    {
        foreach ($this->included as $key => $item) {
            if (!isset($this->used[$key])) {
                throw new InvalidDocumentException(
                    status: '400',
                    pointer: $item['pointer'],
                    detail: "resource {$key} is not referenced from data",
                );
            }
        }
    }

    /**
     * Новый ресурс места `$class` (корень POST или ресурс `included` с `lid`) → `InsertOperation`. Ресурс не
     * `Insertable` — 403.
     *
     * @param class-string<Resource> $class
     */
    private function insertOperation(array $item, string $class, string $pointer): InsertOperation
    {
        self::type(item: $item, class: $class, pointer: $pointer);
        if (!is_a($class, Insertable::class, true)) {
            throw new InvalidDocumentException(
                status: '403',
                pointer: $pointer,
                detail: "resource {$class::type()} can not be created",
            );
        }
        $content = $this->content(item: $item, class: $class, pointer: $pointer, owner_is_new: true);

        return new InsertOperation(
            resource: $class,
            identity: isset($item['lid'])
                ? new LocalIdentity(
                    lid: (string) $item['lid'],
                )
                : null,
            attributes: $content['attributes'],
            references: $content['references'],
            relationships: $content['relationships'],
        );
    }

    /**
     * Существующий ресурс места `$class` (корень PATCH или ресурс `included` с `id`) → `UpdateOperation`. Ресурс не
     * `Updatable` — 403, даже если меняются только связи.
     *
     * @param class-string<Resource> $class
     */
    private function updateOperation(array $item, string $class, string $pointer): UpdateOperation
    {
        self::type(item: $item, class: $class, pointer: $pointer);
        if (!is_a($class, Updatable::class, true)) {
            throw new InvalidDocumentException(
                status: '403',
                pointer: $pointer,
                detail: "resource {$class::type()} can not be changed",
            );
        }
        $content = $this->content(item: $item, class: $class, pointer: $pointer, owner_is_new: false);

        return new UpdateOperation(
            resource: $class,
            identity: new PersistentIdentity(
                id: (int) $item['id'],
            ),
            attributes: $content['attributes'],
            references: $content['references'],
            relationships: $content['relationships'],
        );
    }

    /**
     * @param class-string<Resource> $class
     */
    private static function type(array $item, string $class, string $pointer): void
    {
        if (($item['type'] ?? null) !== $class::type()) {
            throw new InvalidDocumentException(
                status: '400',
                pointer: "{$pointer}/type",
                detail: "type must be {$class::type()}",
            );
        }
    }

    /**
     * Данные ресурса места `$class`: присланные атрибуты, связи по внешнему ключу и разобранные связи по владению.
     *
     * @param class-string<Resource> $class
     * @param bool                   $owner_is_new ресурс вставляется: у его связей только новые дети и нет замены
     * @return array{
     *     attributes: array<string, mixed>,
     *     references: array<string, PersistentIdentity|null>,
     *     relationships: array<string, RelationshipOperation>,
     * }
     */
    private function content(array $item, string $class, string $pointer, bool $owner_is_new): array
    {
        foreach (['attributes', 'relationships'] as $member) {
            $value = $item[$member] ?? [];
            if (!is_array($value) || ($value !== [] && array_is_list($value))) {
                throw new InvalidDocumentException(
                    status: '400',
                    pointer: "{$pointer}/{$member}",
                    detail: "{$member} must be an object",
                );
            }
        }

        /** @var array<string, mixed> $attributes */
        $attributes = $item['attributes'] ?? [];
        foreach (array_keys($attributes) as $name) {
            if (!isset($class::ATTRIBUTES[$name])) {
                throw new InvalidDocumentException(
                    status: '400',
                    pointer: "{$pointer}/attributes/{$name}",
                    detail: "unknown attribute {$name} of {$class::type()}",
                );
            }
            $read_only = ($class::ATTRIBUTES[$name]['mode'] ?? null) === Resource::READ;
            if ($name === $class::primaryAttribute() || $read_only) {
                throw new InvalidDocumentException(
                    status: '400',
                    pointer: "{$pointer}/attributes/{$name}",
                    detail: "attribute {$name} is read-only",
                );
            }
            if ($attributes[$name] === null && !($class::ATTRIBUTES[$name]['nullable'] ?? false)) {
                throw new InvalidDocumentException(
                    status: '400',
                    pointer: "{$pointer}/attributes/{$name}",
                    detail: "{$name} can not be null",
                );
            }
        }

        $references = [];
        $relationships = [];
        /** @var array<string, mixed> $sent */
        $sent = $item['relationships'] ?? [];
        foreach ($sent as $name => $value) {
            $name = (string) $name;
            $relationship_pointer = "{$pointer}/relationships/{$name}";
            $relationship = $class::relationship($name);
            if ($relationship === null) {
                throw new InvalidDocumentException(
                    status: '400',
                    pointer: $relationship_pointer,
                    detail: "unknown relationship {$name} of {$class::type()}",
                );
            }
            if (!is_array($value) || !array_key_exists('data', $value)) {
                throw new InvalidDocumentException(
                    status: '400',
                    pointer: $relationship_pointer,
                    detail: 'data is required',
                );
            }
            $owner_side = $relationship->relation[0] === $class::primaryColumn();
            if ($class::foreignRelationship($name) !== null) {
                $references[$name] = self::reference(
                    name: $name,
                    target: $relationship->resource,
                    value: $value['data'],
                    pointer: $relationship_pointer,
                );
            } elseif ($relationship instanceof OneToMany && $owner_side) {
                $relationships[$name] = $this->children(
                    target: $relationship->resource,
                    value: $value,
                    pointer: $relationship_pointer,
                    parent_is_new: $owner_is_new,
                );
            } elseif ($relationship instanceof OneToOne && $owner_side) {
                $relationships[$name] = $this->child(
                    target: $relationship->resource,
                    value: $value,
                    pointer: $relationship_pointer,
                    parent_is_new: $owner_is_new,
                );
            } else {
                throw new InvalidDocumentException(
                    status: '400',
                    pointer: $relationship_pointer,
                    detail: "{$name} can not be changed here",
                );
            }
        }

        return ['attributes' => $attributes, 'references' => $references, 'relationships' => $relationships];
    }

    /**
     * Связь по внешнему ключу: `{type, id}` нужного типа → цель, `null` → null (без связи). `lid` недопустим: цель —
     * только существующий ресурс.
     *
     * @param class-string<Resource> $target
     */
    private static function reference(string $name, string $target, mixed $value, string $pointer): ?PersistentIdentity
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || ($value['type'] ?? null) !== $target::type()) {
            throw new InvalidDocumentException(
                status: '400',
                pointer: "{$pointer}/data/type",
                detail: "type must be {$target::type()}",
            );
        }
        $id = $value['id'] ?? null;
        if (isset($value['lid']) || !is_string($id) || !ctype_digit($id)) {
            throw new InvalidDocumentException(
                status: '400',
                pointer: "{$pointer}/data",
                detail: "{$name} refers to an existing resource: {type, id} or null",
            );
        }

        return new PersistentIdentity(
            id: (int) $id,
        );
    }

    /**
     * Список связи по владению → операции над детьми в порядке выполнения и признак полной замены. Правила — в
     * докблоке `RelationshipOperation`.
     *
     * @param class-string<Resource> $target
     */
    private function children(string $target, mixed $value, string $pointer, bool $parent_is_new): RelationshipOperation
    {
        $identifiers = is_array($value) ? ($value['data'] ?? null) : null;
        if (!is_array($identifiers) || !array_is_list($identifiers)) {
            throw new InvalidDocumentException(
                status: '400',
                pointer: "{$pointer}/data",
                detail: 'expected a list of resource identifiers',
            );
        }
        $marked = array_filter($identifiers, static fn(mixed $identifier): bool => isset($identifier['meta']['op']));
        if ($marked === [] && !$parent_is_new) {
            self::deletable(
                target: $target,
                pointer: "{$pointer}/data",
                detail: "a list without meta.op replaces the relationship and deletes unlisted {$target::type()}, "
                    . 'which can not be deleted: mark elements with meta.op for a partial change',
            );
        }

        $children = [];
        $seen = [];
        foreach ($identifiers as $position => $identifier) {
            $identifier_pointer = "{$pointer}/data/{$position}";
            $key = self::identity(item: $identifier, pointer: $identifier_pointer);
            if (($identifier['type'] ?? null) !== $target::type()) {
                throw new InvalidDocumentException(
                    status: '400',
                    pointer: "{$identifier_pointer}/type",
                    detail: "type must be {$target::type()}",
                );
            }
            if (isset($seen[$key])) {
                throw new InvalidDocumentException(
                    status: '400',
                    pointer: $identifier_pointer,
                    detail: "{$key} is listed twice",
                );
            }
            $seen[$key] = true;
            $marker = $identifier['meta']['op'] ?? null;
            if ($marker !== null && !in_array($marker, ['add', 'update', 'remove'], true)) {
                throw new InvalidDocumentException(
                    status: '400',
                    pointer: "{$identifier_pointer}/meta/op",
                    detail: 'op must be add, update or remove',
                );
            }
            $included = $this->included[$key] ?? null;

            // Новый ребёнок: только с ресурсом в included
            if (isset($identifier['lid'])) {
                if ($marker !== null && $marker !== 'add') {
                    throw new InvalidDocumentException(
                        status: '400',
                        pointer: "{$identifier_pointer}/meta/op",
                        detail: 'a resource with lid can only be added',
                    );
                }
                if ($included === null) {
                    throw new InvalidDocumentException(
                        status: '400',
                        pointer: $identifier_pointer,
                        detail: "{$key} is not in included",
                    );
                }
                $this->markUsed(key: $key, pointer: $identifier_pointer);
                $children[] = $this->insertOperation(
                    item: $included['resource'],
                    class: $target,
                    pointer: $included['pointer'],
                );
                continue;
            }

            // Существующий ребёнок: у нового родителя их нет
            if ($parent_is_new) {
                throw new InvalidDocumentException(
                    status: '400',
                    pointer: $identifier_pointer,
                    detail: 'a new resource has no existing children: use lid',
                );
            }
            if ($marker === 'add') {
                throw new InvalidDocumentException(
                    status: '400',
                    pointer: "{$identifier_pointer}/meta/op",
                    detail: 'moving an existing resource to another parent is not supported',
                );
            }
            $identity = new PersistentIdentity(
                id: (int) $identifier['id'],
            );
            if ($marker === 'remove') {
                self::deletable(
                    target: $target,
                    pointer: $identifier_pointer,
                    detail: "{$target::type()} can not be deleted",
                );
                if ($included !== null) {
                    throw new InvalidDocumentException(
                        status: '400',
                        pointer: $included['pointer'],
                        detail: "{$key} is removed, it can not be in included",
                    );
                }
                $children[] = new DeleteOperation(
                    resource: $target,
                    identity: $identity,
                );
                continue;
            }
            if ($included !== null) {
                $this->markUsed(key: $key, pointer: $identifier_pointer);
                $children[] = $this->updateOperation(
                    item: $included['resource'],
                    class: $target,
                    pointer: $included['pointer'],
                );
                continue;
            }
            if ($marker === 'update') {
                throw new InvalidDocumentException(
                    status: '400',
                    pointer: $identifier_pointer,
                    detail: "update needs {$key} in included",
                );
            }
            $children[] = new KeepOperation(
                resource: $target,
                identity: $identity,
            );
        }

        usort(
            $children,
            static fn(WriteOperation $left, WriteOperation $right): int => self::order(operation: $left)
                <=> self::order(operation: $right),
        );

        return new RelationshipOperation(
            replace: $marked === [] && !$parent_is_new,
            children: $children,
        );
    }

    /**
     * Место операции в порядке выполнения внутри связи: удаления, затем оставленные и изменённые, затем вставки.
     */
    private static function order(WriteOperation $operation): int
    {
        if ($operation instanceof DeleteOperation) {
            return 0;
        }
        if ($operation instanceof InsertOperation) {
            return 2;
        }

        return 1;
    }

    /**
     * Связь to-one по владению (`delivery`) → операция над ребёнком (список из одной или пустой) и признак замены.
     * `meta.op` не нужен, операцию даёт форма:
     *
     *     {type, lid} + ресурс в included   замена: текущий ребёнок удаляется, новый вставляется (InsertOperation)
     *     {type, id}  + ресурс в included   UpdateOperation текущего ребёнка (чужой — 404)
     *     {type, id}                        KeepOperation: только проверка, что это текущий ребёнок
     *     null                              замена без нового: текущий ребёнок удаляется (его нет — не ошибка)
     *
     * У нового родителя: `{lid}` — вставка, `null` — ничего, `{id}` — 400. Замена исполняется тем же
     * `WriteReplaceWriter`, что и полная замена to-many: `DELETE … WHERE <колонка связи> = родитель` без названных id,
     * потом вставка (`WriteJoinWriter`).
     *
     * @param class-string<Resource> $target
     */
    private function child(string $target, mixed $value, string $pointer, bool $parent_is_new): RelationshipOperation
    {
        $is_list = is_array($value) && is_array($value['data'] ?? null) && array_is_list($value['data']);
        if (!is_array($value) || !array_key_exists('data', $value) || ($is_list && $value['data'] !== [])) {
            throw new InvalidDocumentException(
                status: '400',
                pointer: "{$pointer}/data",
                detail: 'expected a resource identifier or null',
            );
        }
        $identifier = $value['data'];
        $identifier_pointer = "{$pointer}/data";
        if ($identifier === null || $identifier === []) {
            if (!$parent_is_new) {
                self::deletable(
                    target: $target,
                    pointer: $identifier_pointer,
                    detail: "null deletes the current {$target::type()}, which can not be deleted",
                );
            }

            return new RelationshipOperation(
                replace: !$parent_is_new,
                children: [],
            );
        }
        $key = self::identity(item: $identifier, pointer: $identifier_pointer);
        if (($identifier['type'] ?? null) !== $target::type()) {
            throw new InvalidDocumentException(
                status: '400',
                pointer: "{$identifier_pointer}/type",
                detail: "type must be {$target::type()}",
            );
        }
        if (isset($identifier['meta']['op'])) {
            throw new InvalidDocumentException(
                status: '400',
                pointer: "{$identifier_pointer}/meta/op",
                detail: 'a to-one relationship takes no op: lid replaces, id keeps or updates, null removes',
            );
        }
        $included = $this->included[$key] ?? null;

        if (isset($identifier['lid'])) {
            if ($included === null) {
                throw new InvalidDocumentException(
                    status: '400',
                    pointer: $identifier_pointer,
                    detail: "{$key} is not in included",
                );
            }
            $this->markUsed(key: $key, pointer: $identifier_pointer);
            if (!$parent_is_new) {
                self::deletable(
                    target: $target,
                    pointer: $identifier_pointer,
                    detail: "lid replaces the current {$target::type()}, which can not be deleted",
                );
            }

            return new RelationshipOperation(
                replace: !$parent_is_new,
                children: [
                    $this->insertOperation(
                        item: $included['resource'],
                        class: $target,
                        pointer: $included['pointer'],
                    ),
                ],
            );
        }

        if ($parent_is_new) {
            throw new InvalidDocumentException(
                status: '400',
                pointer: $identifier_pointer,
                detail: 'a new resource has no existing children: use lid',
            );
        }
        if ($included !== null) {
            $this->markUsed(key: $key, pointer: $identifier_pointer);

            return new RelationshipOperation(
                replace: false,
                children: [
                    $this->updateOperation(
                        item: $included['resource'],
                        class: $target,
                        pointer: $included['pointer'],
                    ),
                ],
            );
        }

        return new RelationshipOperation(
            replace: false,
            children: [
                new KeepOperation(
                    resource: $target,
                    identity: new PersistentIdentity(
                        id: (int) $identifier['id'],
                    ),
                ),
            ],
        );
    }

    /**
     * Документ может удалить ресурсы цели связи — цель обязана быть `Deletable`, иначе 403.
     *
     * @param class-string<Resource> $target
     */
    private static function deletable(string $target, string $pointer, string $detail): void
    {
        if (!is_a($target, Deletable::class, true)) {
            throw new InvalidDocumentException(
                status: '403',
                pointer: $pointer,
                detail: $detail,
            );
        }
    }

    /**
     * "type:id:<id>" или "type:lid:<lid>"; ровно одно из `id`/`lid`.
     */
    private static function identity(mixed $item, string $pointer): string
    {
        if (!is_array($item) || !is_string($item['type'] ?? null) || isset($item['id']) === isset($item['lid'])) {
            throw new InvalidDocumentException(
                status: '400',
                pointer: $pointer,
                detail: 'expected type and exactly one of id, lid',
            );
        }
        if (isset($item['id']) && !(is_string($item['id']) && ctype_digit($item['id']))) {
            throw new InvalidDocumentException(
                status: '400',
                pointer: "{$pointer}/id",
                detail: 'id must be a string of digits',
            );
        }

        return isset($item['id']) ? "{$item['type']}:id:{$item['id']}" : "{$item['type']}:lid:{$item['lid']}";
    }
}
