<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor;

use Ggs\JsonApiEngine\Resource;
use Ggs\JsonApiEngine\Resource\Insertable;
use LogicException;

/**
 * Граница уровней дерева вставки: ресурс JSON:API → строка «колонка модели → значение» → поддерево таблиц (`input`,
 * обычно из `TableTreeBuilder::build()`). Зеркало SerializeIterator: тот собирает ресурс из строки, этот — строку
 * из ресурса.
 *
 * Поток: ресурс из дерева `DocumentParser` + колонки связи → строка: атрибуты из `attributes` (по
 * `ATTRIBUTES[...]['column']`), связи по внешнему ключу ресурса (`provider` → `provider_id` из `{type, id}`,
 * `null` → NULL) → `input->push(строка, link)` → ключи.
 *
 * Остальные связи (`domains`, `detail`) не трогает: их детей вставляет InsertJoinWriter. Составной атрибут требует
 * статического `deserialize<Attribute>($value): array<column, value>` у ресурса (обратного `serialize<Attribute>`),
 * иначе `LogicException`. Вход проверен парсером; несоответствие (другой `type`, неизвестный атрибут, FK-связь без
 * `id`) — `LogicException`. Ресурс без `Insertable` — `LogicException` в конструкторе.
 */
final readonly class InsertResourceWriter implements ExecutorWriter
{
    /**
     * @param class-string<Resource> $resource
     */
    public function __construct(
        private string $resource,
        private TableWriter $input,
    ) {
        if (!is_a($resource, Insertable::class, true)) {
            throw new LogicException("{$resource} is not Insertable");
        }
    }

    #[\Override]
    public function push(array $resource, array $link = []): array
    {
        $class = $this->resource;
        if (($resource['type'] ?? null) !== $class::type()) {
            throw new LogicException("Expected {$class::type()}, got " . (string) ($resource['type'] ?? ''));
        }
        /** @var array<string, mixed> $attributes */
        $attributes = $resource['attributes'] ?? [];
        /** @var array<string, array{data: mixed}> $relationships */
        $relationships = $resource['relationships'] ?? [];

        return $this->input->push([...$this->attributes($attributes), ...$this->foreignKeys($relationships)], $link);
    }

    /**
     * @param array<string, mixed> $attributes имя в API → значение
     * @return array<string, mixed> колонка модели → значение
     */
    private function attributes(array $attributes): array
    {
        $class = $this->resource;
        $row = [];
        foreach ($attributes as $name => $value) {
            /** @var array<string, mixed> $attribute */
            $attribute = $class::ATTRIBUTES[$name] ?? throw new LogicException("{$class}: unknown attribute {$name}");
            if (isset($attribute['columns'])) {
                $method = 'deserialize' . str_replace('_', '', ucwords($name, '_'));
                if (!method_exists($class, $method)) {
                    throw new LogicException("{$class}: composite attribute {$name} needs {$method}()");
                }
                /** @var array<string, mixed> $columns */
                $columns = $class::$method($value);
                $row = [...$row, ...$columns];
                continue;
            }
            $row[(string) ($attribute['column'] ?? $name)] = $value;
        }

        return $row;
    }

    /**
     * Колонки связей по внешнему ключу ресурса: `{type, id}` → int, `null` → NULL.
     *
     * @param array<string, array{data: mixed}> $relationships
     * @return array<string, int|null> колонка модели → значение
     */
    private function foreignKeys(array $relationships): array
    {
        $class = $this->resource;
        $row = [];
        foreach ($relationships as $name => $relationship) {
            $foreign = $class::foreignRelationship($name);
            if ($foreign === null) {
                continue;
            }
            $data = $relationship['data'];
            if ($data !== null && !is_array($data)) {
                throw new LogicException("{$class}: {$name} needs a resource identifier");
            }
            $row[$foreign->relation[0]] = $data === null
                ? null
                : (int) ($data['id'] ?? throw new LogicException("{$class}: {$name} needs an existing id"));
        }

        return $row;
    }

    #[\Override]
    public function explain(): array
    {
        return ['node' => static::class, 'resource' => $this->resource, 'input' => $this->input->explain()];
    }
}
