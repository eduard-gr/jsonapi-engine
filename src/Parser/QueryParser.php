<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Parser;

use Ggs\JsonApiEngine\Operator;
use Ggs\JsonApiEngine\Resource\ManyToOne;
use Ggs\JsonApiEngine\Resource\OneToMany;
use Ggs\JsonApiEngine\Resource\OneToOne;
use Ggs\JsonApiEngine\Resource\Relationship;
use Ggs\JsonApiEngine\Resource;

/**
 * Этап 1: разбирает параметры запроса JSON:API по корневому ресурсу endpoint'а и строит дерево запроса.
 *
 * Что можно, описывает только сам ресурс: всё, что в нём объявлено, клиенту доступно.
 *  - `include` — любая связь из `relationships()` ресурса-родителя, на любую глубину;
 *  - `fields` — публичные атрибуты ресурса (`publicAttributes()`);
 *  - `filter` — атрибуты, у которых ресурс объявил `filter`, и только эти операторы;
 *  - `sort` — атрибуты, у которых ресурс объявил `sort`.
 * Схемы endpoint'а, которая сужала бы это, пока нет (решение пользователя: сначала всё на ресурсах, ограничения —
 * потом).
 *
 * Дерево — ресурсы и связи между ними, без объектов связей. Узел ресурса: класс ресурса, выбранные
 * атрибуты, фильтры, сортировка и подключённые ресурсы. Подключение: имя связи (ключ в `includes`), вид связи
 * (`getRelationshipType()`), её колонки (`relation`) и узел подключённого ресурса. Тип и путь в дереве
 * не хранятся: тип есть у ресурса, путь вычисляется от корня.
 *
 * Парсер работает только с ресурсами: условия и сортировка — по атрибутам ресурса, колонок в дереве нет. Атрибут
 * в колонки модели (сущностей) переводит планировщик.
 *
 * @psalm-type TreeFilter = array{attribute: string, operator: string, value: mixed}
 * @psalm-type TreeSort = array{attribute: string, descending: bool, position: int}
 * @psalm-type TreeResource = array{
 *     resource: class-string<Resource>,
 *     fields: list<string>,
 *     filters: list<TreeFilter>,
 *     sort: list<TreeSort>,
 *     includes: array<string, array{type: string, relation: array{string, string}, resource: array}>
 * }
 * @psalm-type TreeInclude = array{type: 'one-to-one'|'many-to-one'|'one-to-many', relation: array{string, string}, resource: TreeResource}

 */
final class QueryParser
{
    public const string ONE_TO_ONE = 'one-to-one';
    public const string MANY_TO_ONE = 'many-to-one';
    public const string ONE_TO_MANY = 'one-to-many';

    private const array PARAMETERS = ['include', 'fields', 'filter', 'sort'];

    /**
     * @param class-string<Resource> $resource корневой ресурс endpoint'а
     * @param array<string, mixed>   $query    параметры запроса в виде, в котором их разбирает PHP (`$_GET`)
     *
     * @return TreeResource корень дерева
     *
     * @throws InvalidQueryException
     */
    public function parse(string $resource, array $query): array
    {
        foreach (array_keys($query) as $parameter) {
            if (!\in_array($parameter, self::PARAMETERS, true)) {
                throw new InvalidQueryException("Query parameter {$parameter} is not supported", (string) $parameter);
            }
        }

        $tree = self::resource($resource);
        /** @var array<string, class-string<Resource>> $resources путь узла → его ресурс */
        $resources = ['' => $resource];

        // цикл — ошибка, только если все связи путей существуют: неизвестная связь важнее
        $cycle = null;
        foreach ($this->includePaths($query['include'] ?? null) as $path) {
            $node = &$tree;
            $current = '';
            /** @var array<class-string, true> $onPath ресурсы от корня до узла: класс не может повториться */
            $onPath = [$resource => true];
            foreach (explode('.', $path) as $name) {
                $current = $current === '' ? $name : "{$current}.{$name}";
                // связь описывает ресурс родителя; подключить можно любую его связь
                $relationship = $node['resource']::relationship($name);
                if ($relationship === null) {
                    throw new InvalidQueryException("Relationship {$current} can not be included", 'include');
                }
                if ($cycle === null && isset($onPath[$relationship->resource])) {
                    $cycle = "Include path {$current} contains a cycle: {$relationship->resource::type()} is already on the path";
                }
                $onPath[$relationship->resource] = true;
                if (!isset($node['includes'][$name])) {
                    $node['includes'][$name] = [
                        'type' => $this->getRelationshipType($relationship),
                        'relation' => [$relationship->relation[0], $relationship->relation[1]],
                        'resource' => self::resource($relationship->resource),
                    ];
                    $resources[$current] = $relationship->resource;
                }
                $node = &$node['includes'][$name]['resource'];
            }
            unset($node);
        }
        if ($cycle !== null) {
            throw new InvalidQueryException($cycle, 'include');
        }

        $this->applyFields($tree, $query['fields'] ?? []);
        $this->applyFilters($tree, $resources, $query['filter'] ?? []);
        $this->applySort($tree, $resources, $query['sort'] ?? null);

        return $tree;
    }

    /**
     * Вид связи в дереве: `OneToOne` — `one-to-one`, `ManyToOne` — `many-to-one`, `OneToMany` — `one-to-many`.
     * По виду планировщик выбирает, как выполнить связь (см. `Resource\Relationship`).
     *
     * @return self::ONE_TO_ONE|self::MANY_TO_ONE|self::ONE_TO_MANY
     */
    private function getRelationshipType(Relationship $relationship): string
    {
        return match (true) {
            $relationship instanceof OneToOne => self::ONE_TO_ONE,
            $relationship instanceof ManyToOne => self::MANY_TO_ONE,
            $relationship instanceof OneToMany => self::ONE_TO_MANY,
            default => throw new \LogicException('Unknown relationship kind ' . $relationship::class),
        };
    }

    /**
     * Узел ресурса до разбора `fields`, `filter` и `sort`: все публичные атрибуты ресурса.
     *
     * @param class-string<Resource> $resource
     * @return TreeResource
     */
    private static function resource(string $resource): array
    {
        return [
            'resource' => $resource,
            'fields' => $resource::publicAttributes(),
            'filters' => [],
            'sort' => [],
            'includes' => [],
        ];
    }

    /**
     * @return list<string>
     */
    private function includePaths(mixed $include): array
    {
        if ($include === null) {
            return [];
        }
        if (!\is_string($include)) {
            throw new InvalidQueryException('include must be a comma-separated list of relationship paths', 'include');
        }

        $paths = [];
        foreach (explode(',', $include) as $path) {
            $path = trim($path);
            if ($path === '') {
                continue;
            }
            if (\in_array('', explode('.', $path), true)) {
                throw new InvalidQueryException("Invalid relationship path {$path}", 'include');
            }
            $paths[] = $path;
        }

        return $paths;
    }

    /**
     * `fields[<тип>]=<атрибуты>` — атрибуты каждого узла этого типа; разрешены публичные атрибуты ресурса.
     *
     * @param TreeResource $tree
     */
    private function applyFields(array &$tree, mixed $fields): void
    {
        if (!\is_array($fields)) {
            throw new InvalidQueryException('fields must be given per type: fields[<type>]=<names>', 'fields');
        }

        foreach ($fields as $type => $list) {
            $parameter = "fields[{$type}]";
            if (!\is_string($list)) {
                throw new InvalidQueryException("{$parameter} must be a comma-separated list", $parameter);
            }
            $names = array_values(array_filter(array_map('trim', explode(',', $list)), fn (string $n) => $n !== ''));

            $found = false;
            $this->walk($tree, '', function (array &$node) use ($type, $names, $parameter, &$found): void {
                if ($node['resource']::type() !== (string) $type) {
                    return;
                }
                $found = true;
                $allowed = $node['resource']::publicAttributes();
                foreach ($names as $name) {
                    if (!\in_array($name, $allowed, true)) {
                        throw new InvalidQueryException("Field {$name} is not available for {$type}", $parameter);
                    }
                }
                $node['fields'] = $names;
            });
            if (!$found) {
                throw new InvalidQueryException("Resource {$type} is not included", $parameter);
            }
        }
    }

    /**
     * `filter[<фильтр>]` — фильтр корня, `filter[<путь>.<фильтр>]` — фильтр подключённого ресурса.
     * Разрешены атрибуты, у которых ресурс объявил `filter`, и только объявленные операторы.
     *
     * @param TreeResource                          $tree
     * @param array<string, class-string<Resource>> $resources путь узла → его ресурс
     */
    private function applyFilters(array &$tree, array $resources, mixed $filters): void
    {
        if (!\is_array($filters)) {
            throw new InvalidQueryException('filter must be given per name: filter[<name>]=<value>', 'filter');
        }

        foreach ($filters as $key => $value) {
            $key = (string) $key;
            $parameter = "filter[{$key}]";
            [$path, $name] = self::split($key);

            $resource = $resources[$path]
                ?? throw new InvalidQueryException("Resource {$path} is not included", $parameter);
            $operators = $resource::hasAttribute($name) ? $resource::filterOperators($name) : [];
            if ($operators === []) {
                throw new InvalidQueryException("Unknown filter {$name}", $parameter);
            }

            $conditions = \is_array($value) ? $value : ['eq' => $value];
            if ($conditions === [] || array_is_list($conditions)) {
                throw new InvalidQueryException(
                    "{$parameter} expects a value or operators: {$parameter}[<operator>]=<value>",
                    $parameter,
                );
            }

            $type = $resource::attributeType($name);

            $node = &self::at($tree, $path);
            foreach ($conditions as $operator => $operand) {
                $operator = (string) $operator;
                $allowed = Operator::tryFrom($operator);
                if ($allowed === null || !\in_array($allowed, $operators, true)) {
                    throw new InvalidQueryException("Operator {$operator} is not allowed for filter {$name}", $parameter);
                }
                $node['filters'][] = [
                    'attribute' => $name,
                    'operator' => $operator,
                    'value' => $this->operand($operator, $operand, $type, "{$parameter}[{$operator}]"),
                ];
            }
            unset($node);
        }
    }

    /**
     * `sort=-expires_at,domains.name`: поля через запятую, `-` — по убыванию, `<путь>.<атрибут>` — атрибут
     * подключённого ресурса. Разрешены атрибуты с `sort` в ресурсе. Поле попадает в `sort` своего ресурса;
     * `position` — его место в параметре запроса, общий порядок полей разных ресурсов.
     *
     * @param TreeResource                          $tree
     * @param array<string, class-string<Resource>> $resources путь узла → его ресурс
     */
    private function applySort(array &$tree, array $resources, mixed $sort): void
    {
        if ($sort === null) {
            return;
        }
        if (!\is_string($sort)) {
            throw new InvalidQueryException('sort must be a comma-separated list of fields', 'sort');
        }

        $seen = [];
        foreach (explode(',', $sort) as $position => $field) {
            $field = trim($field);
            $descending = str_starts_with($field, '-');
            $name = $descending ? substr($field, 1) : $field;
            if ($name === '') {
                throw new InvalidQueryException('sort contains an empty field', 'sort');
            }
            [$path, $attribute] = self::split($name);

            $resource = $resources[$path]
                ?? throw new InvalidQueryException("Resource {$path} is not included", 'sort');
            if (!$resource::hasAttribute($attribute) || !$resource::sortable($attribute)) {
                throw new InvalidQueryException("Sorting by {$name} is not allowed", 'sort');
            }
            if (isset($seen[$name])) {
                throw new InvalidQueryException("sort contains {$name} more than once", 'sort');
            }
            $seen[$name] = true;

            $node = &self::at($tree, $path);
            $node['sort'][] = [
                'attribute' => $attribute,
                'descending' => $descending,
                'position' => $position,
            ];
            unset($node);
        }
    }

    /**
     * `domains.validation.status` → `['domains.validation', 'status']`, `status` → `['', 'status']`.
     *
     * @return array{string, string}
     */
    private static function split(string $name): array
    {
        $dot = strrpos($name, '.');

        return $dot === false ? ['', $name] : [substr($name, 0, $dot), substr($name, $dot + 1)];
    }

    /**
     * Узел ресурса по пути от корня (`domains.validation`); путь уже проверен по подключённым связям.
     *
     * @param TreeResource $tree
     * @return TreeResource
     */
    private static function &at(array &$tree, string $path): array
    {
        $node = &$tree;
        if ($path !== '') {
            foreach (explode('.', $path) as $name) {
                $node = &$node['includes'][$name]['resource'];
            }
        }

        return $node;
    }

    private function operand(string $operator, mixed $operand, ?string $type, string $parameter): mixed
    {
        if ($operator === 'in') {
            $values = \is_string($operand) ? explode(',', $operand) : $operand;
            if (!\is_array($values) || !array_is_list($values) || $values === []) {
                throw new InvalidQueryException("{$parameter} expects a non-empty list of values", $parameter);
            }

            return array_map(fn (mixed $v): mixed => $this->value($v, $type, $parameter), $values);
        }

        return $this->value($operand, $type, $parameter);
    }

    private function value(mixed $value, ?string $type, string $parameter): mixed
    {
        if (!\is_string($value)) {
            throw new InvalidQueryException("{$parameter} expects a single value", $parameter);
        }
        // libpq обрезает параметр на нулевом байте: "active\0..." превратилось бы в "active"
        if (str_contains($value, "\0") || preg_match('//u', $value) !== 1) {
            throw new InvalidQueryException("{$parameter}: value must be a valid UTF-8 string without NUL bytes", $parameter);
        }

        $converted = match ($type) {
            Resource::INT => preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : null,
            Resource::FLOAT => is_numeric($value) ? (float) $value : null,
            Resource::BOOL => match ($value) {
                'true', '1' => true,
                'false', '0' => false,
                default => null,
            },
            Resource::STRING => $value,
            Resource::DATE => $this->date($value),
            Resource::DATETIME => $this->dateTime($value),
        };
        if ($converted === null) {
            throw new InvalidQueryException("{$parameter}: value {$value} is not a valid {$type}", $parameter);
        }

        return $converted;
    }

    private function date(string $value): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }

    private function dateTime(string $value): ?\DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?)?(Z|[+-]\d{2}:?\d{2})?$/', $value) !== 1) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Обходит узлы ресурсов сверху вниз.
     *
     * @param TreeResource $node
     * @param \Closure $callback получает узел ресурса по ссылке и его путь
     */
    private function walk(array &$node, string $path, \Closure $callback): void
    {
        $callback($node, $path);
        foreach (array_keys($node['includes']) as $name) {
            $this->walk($node['includes'][$name]['resource'], $path === '' ? $name : "{$path}.{$name}", $callback);
        }
    }
}
