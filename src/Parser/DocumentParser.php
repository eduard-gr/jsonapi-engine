<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Parser;

use Ggs\JsonApiEngine\Resource;
use Ggs\JsonApiEngine\Resource\Insertable;
use Ggs\JsonApiEngine\Resource\OneToMany;

/**
 * Парсер входного документа вставки (тело `POST`): плоские `data` + `included` → список корней, каждый со своим
 * поддеревом новых ресурсов.
 *
 * Поток: документ JSON:API (уже декодированный массив) + класс корневого ресурса точки входа → `list<ресурс>`, где
 * ресурс — объект JSON:API (`type`, `lid`, `attributes`, `relationships`), а в `relationships[name].data` ссылка
 * `{type, lid}` заменена самим ресурсом (рекурсивно), ссылка `{type, id}` на существующий ресурс оставлена как есть
 * (`id` — строкой). Этот вложенный вид читают узлы `Executor\InsertJoinWriter` / `InsertResourceWriter`.
 *
 * Формат входа. Базовый JSON:API создаёт одним запросом только один ресурс, а связи в нём — ссылки `{type, id}`
 * на существующие ресурсы. Здесь новые связанные ресурсы передаются в `included` (нестандартный sideposting), ссылки на
 * них — через локальный идентификатор `lid` (JSON:API 1.1) вместо `id`; `data` может быть списком (несколько корней,
 * тоже расширение). У нового ресурса есть `lid` и нет `id` (его генерирует база).
 *
 * Весь документ проверяется до того, как отдаётся первый корень, поэтому ни одна ошибка клиента не появится после
 * первого `INSERT` (по этой причине парсер не итеративный). Проверки — все `InvalidDocumentException`:
 *  - `data` — ресурс или непустой список ресурсов; у каждого нового ресурса строковый `type`, непустой `lid`,
 *    нет `id`; `type:lid` уникален в документе;
 *  - `type` совпадает с ресурсом места (корень — корневой ресурс, ребёнок — цель связи);
 *  - ресурс можно создать (`Resource\Insertable`);
 *  - атрибут существует и записываемый (не первичный ключ, не `mode` READ);
 *  - связь существует, форма `data` по её типу (список у `OneToMany`, ресурс или null у остальных); связь по
 *    внешнему ключу ресурса (`Resource::foreignRelationship()`) — только существующий ресурс `{type, id}`, остальные —
 *    только новые `{type, lid}` из `included` (привязка существующих — это UPDATE их внешнего ключа);
 *  - каждый ресурс из `included` указан ровно один раз и достижим из `data`. Поэтому результат — дерево (у ресурса
 *    один родитель) и циклов нет: корни в `included` не лежат. Ссылка по `lid` на ресурс из другой ветки (ресурс
 *    с двумя родителями) запрещена.
 *
 * Не проверяет: типы значений атрибутов и обязательные атрибуты (`NOT NULL` без значения по умолчанию упадёт в базе),
 * существование записей по `{type, id}` (упадёт на внешнем ключе в базе).
 */
final class DocumentParser
{
    /** @var array<string, array{resource: array, pointer: string}> type:lid → ресурс из included */
    private array $included = [];

    /** @var array<string, true> type:lid, уже подставленные в дерево */
    private array $used = [];

    /**
     * @param class-string<Resource> $resource корневой ресурс точки входа (`POST /certificates` → CertificateResource)
     * @return list<array> корни с поддеревьями, в порядке `data`
     *
     * @throws InvalidDocumentException
     */
    public function parse(array $document, string $resource): array
    {
        $this->included = [];
        $this->used = [];

        $data = $document['data'] ?? throw new InvalidDocumentException(detail: 'data is required', pointer: '');
        if (!is_array($data) || $data === []) {
            throw new InvalidDocumentException(detail: 'data must be a resource or a list of resources', pointer: '/data');
        }
        $roots = array_is_list($data) ? $data : [$data];
        $pointers = array_is_list($data)
            ? array_map(static fn(int $i): string => "/data/{$i}", array_keys($data))
            : ['/data'];

        $seen = [];
        foreach ($roots as $i => $root) {
            $key = self::identity(item: $root, pointer: $pointers[$i]);
            if (isset($seen[$key])) {
                throw new InvalidDocumentException(detail: "duplicate resource {$key}", pointer: $pointers[$i]);
            }
            $seen[$key] = true;
        }
        $included = $document['included'] ?? [];
        if (!is_array($included) || !array_is_list($included)) {
            throw new InvalidDocumentException(detail: 'included must be a list of resources', pointer: '/included');
        }
        foreach ($included as $i => $item) {
            $pointer = "/included/{$i}";
            $key = self::identity(item: $item, pointer: $pointer);
            if (isset($seen[$key])) {
                throw new InvalidDocumentException(detail: "duplicate resource {$key}", pointer: $pointer);
            }
            $seen[$key] = true;
            /** @var array $item */
            $this->included[$key] = ['resource' => $item, 'pointer' => $pointer];
        }

        $tree = [];
        foreach ($roots as $i => $root) {
            /** @var array $root */
            $tree[] = $this->resource(item: $root, class: $resource, pointer: $pointers[$i]);
        }

        foreach ($this->included as $key => $item) {
            if (!isset($this->used[$key])) {
                throw new InvalidDocumentException(
                    detail: "resource {$key} is not referenced from data",
                    pointer: $item['pointer'],
                );
            }
        }

        return $tree;
    }

    /**
     * Новый ресурс места `$class` → он же с подставленными детьми.
     *
     * @param class-string<Resource> $class
     */
    private function resource(array $item, string $class, string $pointer): array
    {
        if ($item['type'] !== $class::type()) {
            throw new InvalidDocumentException(
                detail: "expected type {$class::type()}, got {$item['type']}",
                pointer: "{$pointer}/type",
            );
        }
        if (!is_a($class, Insertable::class, true)) {
            throw new InvalidDocumentException(detail: "resource {$class::type()} can not be created", pointer: $pointer);
        }

        $attributes = $item['attributes'] ?? [];
        if (!is_array($attributes) || ($attributes !== [] && array_is_list($attributes))) {
            throw new InvalidDocumentException(detail: 'attributes must be an object', pointer: "{$pointer}/attributes");
        }
        foreach (array_keys($attributes) as $name) {
            $at = "{$pointer}/attributes/{$name}";
            $attribute = $class::ATTRIBUTES[$name]
                ?? throw new InvalidDocumentException(detail: "unknown attribute {$name}", pointer: $at);
            if ($name === $class::primaryAttribute() || ($attribute['mode'] ?? null) === Resource::READ) {
                throw new InvalidDocumentException(detail: "attribute {$name} is read-only", pointer: $at);
            }
        }

        $input = $item['relationships'] ?? [];
        if (!is_array($input) || ($input !== [] && array_is_list($input))) {
            throw new InvalidDocumentException(detail: 'relationships must be an object', pointer: "{$pointer}/relationships");
        }
        $relationships = [];
        foreach ($input as $name => $relationship) {
            $name = (string) $name;
            $at = "{$pointer}/relationships/{$name}/data";
            $definition = $class::relationship($name) ?? throw new InvalidDocumentException(
                detail: "unknown relationship {$name}",
                pointer: "{$pointer}/relationships/{$name}",
            );
            if (!is_array($relationship) || !array_key_exists('data', $relationship)) {
                throw new InvalidDocumentException(detail: 'data is required', pointer: $at);
            }
            $data = $relationship['data'];
            $toMany = $definition instanceof OneToMany;
            if (($data !== null && !is_array($data)) || $toMany !== (is_array($data) && array_is_list($data))) {
                throw new InvalidDocumentException(
                    detail: $toMany ? 'expected a list' : 'expected a resource identifier or null',
                    pointer: $at,
                );
            }

            if ($class::foreignRelationship($name) !== null) {
                $relationships[$name] = [
                    'data' => $data === null
                        ? null
                        : $this->reference(identifier: $data, class: $definition->resource, pointer: $at),
                ];
                continue;
            }
            $relationships[$name] = ['data' => match (true) {
                $data === null => null,
                $toMany => array_map(
                    fn(int $i): array => $this->child(identifier: $data[$i], class: $definition->resource, pointer: "{$at}/{$i}"),
                    array_keys($data),
                ),
                default => $this->child(identifier: $data, class: $definition->resource, pointer: $at),
            }];
        }

        return [...$item, 'relationships' => $relationships];
    }

    /**
     * Ссылка по внешнему ключу: только существующий ресурс `{type, id}`.
     *
     * @param class-string<Resource> $class
     */
    private function reference(array $identifier, string $class, string $pointer): array
    {
        if (($identifier['type'] ?? null) !== $class::type()) {
            throw new InvalidDocumentException(detail: "expected type {$class::type()}", pointer: "{$pointer}/type");
        }
        if (!isset($identifier['id']) || isset($identifier['lid'])) {
            throw new InvalidDocumentException(
                detail: is_a($class, Insertable::class, true)
                    ? 'foreign key relationship needs an existing resource {type, id}'
                    : "resource {$class::type()} can not be created: only an existing resource {type, id} can be linked",
                pointer: $pointer,
            );
        }
        if (!is_string($identifier['id']) && !is_int($identifier['id'])) {
            throw new InvalidDocumentException(detail: 'id must be a string', pointer: "{$pointer}/id");
        }

        return ['type' => $identifier['type'], 'id' => (string) $identifier['id']];
    }

    /**
     * Ссылка `{type, lid}` на новый ресурс из `included` → сам ресурс с поддеревом; каждый — ровно один раз.
     *
     * @param class-string<Resource> $class
     */
    private function child(mixed $identifier, string $class, string $pointer): array
    {
        if (!is_array($identifier) || isset($identifier['id']) || !isset($identifier['lid'])) {
            throw new InvalidDocumentException(detail: 'only new resources {type, lid} can be linked here', pointer: $pointer);
        }
        $key = sprintf('%s:%s', (string) ($identifier['type'] ?? ''), (string) $identifier['lid']);
        $item = $this->included[$key]
            ?? throw new InvalidDocumentException(detail: "unknown resource {$key}", pointer: $pointer);
        if (isset($this->used[$key])) {
            throw new InvalidDocumentException(detail: "resource {$key} is linked more than once", pointer: $pointer);
        }
        $this->used[$key] = true;

        return $this->resource(item: $item['resource'], class: $class, pointer: $item['pointer']);
    }

    private static function identity(mixed $item, string $pointer): string
    {
        if (!is_array($item) || !is_string($item['type'] ?? null)) {
            throw new InvalidDocumentException(detail: 'resource object with type is required', pointer: $pointer);
        }
        if (isset($item['id'])) {
            throw new InvalidDocumentException(detail: 'new resource must not have id', pointer: "{$pointer}/id");
        }
        if (!is_string($item['lid'] ?? null) || $item['lid'] === '') {
            throw new InvalidDocumentException(detail: 'new resource needs lid', pointer: "{$pointer}/lid");
        }

        return "{$item['type']}:{$item['lid']}";
    }
}
