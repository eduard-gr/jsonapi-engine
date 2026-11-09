<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine;

use Ggs\JsonApiEngine\Model\Metadata;
use Ggs\JsonApiEngine\Model\ModelTree;
use Ggs\JsonApiEngine\Model\NamedConstants;
use Ggs\JsonApiEngine\Document\ResourceRecord;
use Ggs\JsonApiEngine\Resource\ManyToOne;
use Ggs\JsonApiEngine\Resource\OneToOne;
use Ggs\JsonApiEngine\Resource\Relationship;

/**
 * Базовый класс описания ресурса JSON:API: тип, атрибуты, связи, модель данных и сериализация.
 *
 * Наследник объявляет константы `TYPE`, `ATTRIBUTES` и имён, `relationships()` и `model()`; по желанию —
 * `serialize<Атрибут>()` (свой формат атрибута, у составного обязателен) и ручной `serialize()`. Ресурс из строки
 * запроса плана собирает `Executor\Select\SerializeIterator` по этому описанию. Описание статическое: экземпляров нет, класс
 * передаётся как `::class`.
 */
abstract class Resource implements Metadata
{
    use NamedConstants;

    /** JSON:API type; наследник переопределяет */
    public const string TYPE = '';

    /** @var array<string, array<string, mixed>> атрибуты ресурса; наследник переопределяет */
    public const array ATTRIBUTES = [];

    private const array ATTRIBUTE_KEYS = ['column', 'columns', 'type', 'constraint', 'nullable', 'mode', 'filter', 'sort'];

    /** @var array<class-string, array<string, Relationship>> класс → имя связи → связь */
    private static array $relationship_maps = [];

    /**
     * Связи ресурса: имя связи в API (константа ресурса, `self::DOMAINS`) → связь. Метод, а не константа: PHP не
     * разрешает `new` в константах. Вызывается один раз на класс за процесс (`relationship()` кеширует карту).
     *
     * @return array<string, Relationship>
     */
    public static function relationships(): array
    {
        return [];
    }

    /**
     * Связь с этим именем или null, если её нет.
     */
    public static function relationship(string $name): ?Relationship
    {
        self::$relationship_maps[static::class] ??= static::relationships();

        return self::$relationship_maps[static::class][$name] ?? null;
    }

    /**
     * Связь «к одному» по внешнему ключу в строке ресурса (см. `isForeignKey()`): `ManyToOne` (`certificate.provider`)
     * или `OneToOne`, если её ключ лежит у владельца. Идентификатор такой связи известен из своей строки, без `JOIN`,
     * поэтому она запрашивается в `fields`, как атрибут, и выводится в `relationships` без `include`.
     */
    public static function foreignRelationship(string $name): OneToOne|ManyToOne|null
    {
        $relationship = static::relationship($name);

        return ($relationship instanceof OneToOne || $relationship instanceof ManyToOne)
            && self::isForeignKey($relationship) ? $relationship : null;
    }

    /**
     * Связь по внешнему ключу в строке этого ресурса: колонка ресурса в `relation` — не его первичный ключ,
     * а колонка цели — первичный ключ цели (`certificate.provider`: `[provider_id, id]`).
     */
    private static function isForeignKey(OneToOne|ManyToOne $relationship): bool
    {
        return $relationship->relation[0] !== static::primaryColumn()
            && $relationship->relation[1] === $relationship->resource::primaryColumn();
    }

    /**
     * Связи «к одному» по внешнему ключу ресурса (см. `foreignRelationship()`).
     *
     * @return array<string, OneToOne|ManyToOne> имя → связь
     */
    public static function foreignRelationships(): array
    {
        $result = [];
        foreach (array_keys(static::relationships()) as $name) {
            $foreign = static::foreignRelationship((string) $name);
            if ($foreign !== null) {
                $result[(string) $name] = $foreign;
            }
        }

        return $result;
    }

    public static function type(): string
    {
        return static::TYPE;
    }

    /**
     * Колонка первичного ключа.
     */
    public static function primaryColumn(): string
    {
        $name = static::primaryAttribute();

        return static::ATTRIBUTES[$name]['column'] ?? $name;
    }

    /**
     * Имя атрибута первичного ключа.
     */
    public static function primaryAttribute(): string
    {
        foreach (static::ATTRIBUTES as $name => $attribute) {
            if (($attribute['constraint'] ?? null) === self::PRIMARY) {
                return $name;
            }
        }

        throw new \LogicException(static::class . ' has no PRIMARY attribute');
    }

    /**
     * Есть ли атрибут или связь по внешнему ключу ресурса с этим именем.
     *
     * Здесь и ниже «атрибут» — то, что можно назвать в `fields`: атрибут из `ATTRIBUTES` или связь по внешнему
     * ключу ресурса (`foreignRelationship()`), её колонка — колонка внешнего ключа, `type` — тип первичного ключа
     * цели.
     */
    public static function hasAttribute(string $name): bool
    {
        return isset(static::ATTRIBUTES[$name]) || static::foreignRelationship($name) !== null;
    }

    /**
     * Имена атрибутов, кроме первичного ключа, и связей по внешнему ключу ресурса.
     *
     * @return list<string>
     */
    public static function publicAttributes(): array
    {
        $names = [];
        foreach (static::ATTRIBUTES as $name => $attribute) {
            if (($attribute['constraint'] ?? null) !== self::PRIMARY) {
                $names[] = $name;
            }
        }
        foreach (array_keys(static::foreignRelationships()) as $name) {
            $names[] = $name;
        }

        return $names;
    }

    /**
     * Колонки атрибута: одна у обычного атрибута и связи по внешнему ключу, все — у составного.
     *
     * @return list<string>
     */
    public static function attributeColumns(string $name): array
    {
        if (!isset(static::ATTRIBUTES[$name])) {
            return [self::foreign($name)->relation[0]];
        }
        $attribute = static::ATTRIBUTES[$name];

        return $attribute['columns'] ?? [$attribute['column'] ?? $name];
    }

    /**
     * `type` атрибута; null у составного без `type`.
     */
    public static function attributeType(string $name): ?string
    {
        if (!isset(static::ATTRIBUTES[$name])) {
            $resource = self::foreign($name)->resource;

            return $resource::attributeType($resource::primaryAttribute());
        }

        return static::ATTRIBUTES[$name]['type'] ?? null;
    }

    /**
     * Атрибут, значение которого хранится в колонке (не составной): так параметр маршрута находит свой атрибут.
     */
    public static function columnAttribute(string $column): ?string
    {
        foreach (static::ATTRIBUTES as $name => $attribute) {
            if (!isset($attribute['columns']) && ($attribute['column'] ?? $name) === $column) {
                return $name;
            }
        }
        foreach (static::foreignRelationships() as $name => $relationship) {
            if ($relationship->relation[0] === $column) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Колонка атрибута для фильтра.
     */
    public static function filterColumn(string $name): string
    {
        if (isset(static::ATTRIBUTES[$name]['columns'])) {
            throw new \LogicException(static::class . ": composite attribute {$name} can not be filtered");
        }

        return static::attributeColumns($name)[0];
    }

    /**
     * Операторы, которыми можно фильтровать атрибут (`filter` атрибута или связи по внешнему ключу); пусто —
     * фильтровать нельзя. Парсер разрешает клиенту ровно этот список.
     *
     * @return list<Operator>
     */
    public static function filterOperators(string $name): array
    {
        return isset(static::ATTRIBUTES[$name])
            ? static::ATTRIBUTES[$name]['filter'] ?? []
            : static::foreignRelationship($name)->filter ?? [];
    }

    /**
     * Можно ли сортировать по атрибуту (`sort` атрибута или связи по внешнему ключу).
     */
    public static function sortable(string $name): bool
    {
        return isset(static::ATTRIBUTES[$name])
            ? static::ATTRIBUTES[$name]['sort'] ?? false
            : static::foreignRelationship($name)->sort ?? false;
    }

    /**
     * Колонка внешнего ключа связи по внешнему ключу ресурса; null, если такой связи нет.
     */
    public static function foreignColumn(string $name): ?string
    {
        return static::foreignRelationship($name)?->relation[0];
    }

    private static function foreign(string $name): OneToOne|ManyToOne
    {
        return static::foreignRelationship($name)
            ?? throw new \InvalidArgumentException(static::class . ": unknown attribute {$name}");
    }

    /**
     * Колонки ресурса, которые нужны от модели данных: первичный ключ, колонки атрибутов `fields` и `extra`.
     *
     * @param list<string> $fields атрибуты и связи по внешнему ключу (имена в API)
     * @param list<string> $extra  другие колонки ресурса
     * @return list<string>
     */
    public static function columnsOf(array $fields, array $extra = []): array
    {
        $columns = [static::primaryColumn()];
        foreach ($fields as $name) {
            array_push($columns, ...static::attributeColumns($name));
        }

        return array_values(array_unique([...$columns, ...$extra]));
    }

    /**
     * Ручная сериализация: ресурс из одной строки источника; `null` — ресурса в строке нет (например, `LEFT JOIN`
     * не нашёл запись).
     *
     * Нужна только `Executor\Select\ManualSerializeIterator` (дерево без плана): так собраны `example/document.php`
     * и `DocumentTreeTest` по алиасам `QUERY1`–`QUERY4`. Ресурс из строки запроса плана собирает
     * `Executor\Select\SerializeIterator` по `ATTRIBUTES` (свой формат атрибута — `serialize<Атрибут>()`), этот метод
     * ему не нужен. По умолчанию бросает `LogicException`.
     *
     * Строка — ассоциативный массив `колонка или алиас запроса → значение` целиком, в том числе с колонками
     * других ресурсов той же строки; ресурс читает только свои. Связь по внешнему ключу ресурса пишется здесь же,
     * из его строки (`['data' => {id, type}]` или `['data' => null]`), а ключи соединения для узлов плана кладутся
     * в служебные `keys` `ResourceRecord`.
     *
     * @param array<string, mixed> $tuple строка источника
     * @throws \LogicException если ресурс не объявил ручную сериализацию
     */
    public static function serialize(array $tuple): ?ResourceRecord
    {
        throw new \LogicException(static::class . ' has no hand-written serialize(), use SerializeIterator with plan columns instead of ManualSerializeIterator');
    }

    /**
     * Метод ресурса, который даёт значение атрибута из `ATTRIBUTES` (`serialize<Атрибут>(array $values)`, `$values` —
     * колонка модели → значение, все колонки атрибута); null — метода нет, значение — колонка, приведённая по `type`.
     * У составного атрибута метод есть всегда (проверяет `validate()`). У связи по внешнему ключу метода нет.
     *
     * @return non-empty-string|null имя статического метода
     */
    public static function attributeSerializer(string $name): ?string
    {
        if (!isset(static::ATTRIBUTES[$name])) {
            return null;
        }
        $method = self::serializerName($name);

        return method_exists(static::class, $method) ? $method : null;
    }

    /**
     * @return non-empty-string
     */
    private static function serializerName(string $attribute): string
    {
        return 'serialize' . str_replace('_', '', ucwords($attribute, '_'));
    }

    /**
     * Проверяет константы класса ресурса. Вызывается в тестах, а не в запросах.
     *
     * @throws \LogicException со списком всех найденных ошибок
     */
    public static function validate(): void
    {
        $errors = [];
        $class = new \ReflectionClass(static::class);

        if (static::TYPE === '') {
            $errors[] = 'TYPE must be a non-empty string';
        }

        $primary = [];
        $serializable = [];
        foreach (static::ATTRIBUTES as $name => $attribute) {
            $location = "attribute {$name}";
            if (!\is_array($attribute)) {
                $errors[] = "{$location}: description must be an array";
                continue;
            }
            foreach (array_diff(array_keys($attribute), self::ATTRIBUTE_KEYS) as $key) {
                $errors[] = "{$location}: unknown key {$key}";
            }
            $composite = isset($attribute['columns']);
            if ($composite && isset($attribute['column'])) {
                $errors[] = "{$location}: column and columns are exclusive";
            }
            if ($composite && (!\is_array($attribute['columns']) || $attribute['columns'] === []
                    || !array_is_list($attribute['columns']))) {
                $errors[] = "{$location}: columns must be a non-empty list";
            }
            if (isset($attribute['column']) && !\is_string($attribute['column'])) {
                $errors[] = "{$location}: column must be a string";
            }
            if (!isset($attribute['type']) && !$composite) {
                $errors[] = "{$location}: type is required";
            }
            if (isset($attribute['type']) && !\in_array($attribute['type'], self::TYPES, true)) {
                $errors[] = "{$location}: unknown type {$attribute['type']}";
            }
            if (isset($attribute['mode']) && !\in_array($attribute['mode'], [self::READ, self::READ_WRITE], true)) {
                $errors[] = "{$location}: unknown mode {$attribute['mode']}";
            }
            if (isset($attribute['nullable']) && !\is_bool($attribute['nullable'])) {
                $errors[] = "{$location}: nullable must be bool";
            }
            array_push($errors, ...self::searchErrors($location, $attribute['filter'] ?? null, $attribute['sort'] ?? null, $composite));

            $constraint = $attribute['constraint'] ?? null;
            // JSON:API: type и id — члены идентификатора ресурса, атрибут или связь так называться не могут
            if ($name === 'type' || ($name === 'id' && $constraint !== self::PRIMARY)) {
                $errors[] = "{$location}: name {$name} is reserved by JSON:API";
            }
            if ($constraint === self::FOREIGN) {
                $errors[] = "{$location}: FOREIGN is not an attribute constraint, declare a ManyToOne in relationships()";
            } elseif ($constraint !== null && $constraint !== self::PRIMARY) {
                $errors[] = "{$location}: unknown constraint {$constraint}";
            }
            if ($constraint === self::PRIMARY) {
                $primary[] = $name;
                if ($composite) {
                    $errors[] = "{$location}: PRIMARY can not be composite";
                }
            }
            if ($constraint === null) {
                $serializable[self::serializerName($name)] = $name;
            }

            if ($composite && !self::declaresSerializer($class, self::serializerName($name))) {
                $errors[] = "{$location}: composite attribute requires method " . self::serializerName($name) . '()';
            }
        }

        if (\count($primary) !== 1) {
            $errors[] = 'exactly one PRIMARY attribute is required, found ' . \count($primary);
        }

        foreach ($class->getMethods() as $method) {
            $name = $method->getName();
            if ($method->getDeclaringClass()->getName() === self::class || $name === 'serialize'
                || !str_starts_with($name, 'serialize')) {
                continue;
            }
            if (!$method->isStatic()) {
                $errors[] = "method {$name}() must be static";
            }
            if (!isset($serializable[$name])) {
                $errors[] = "method {$name}() does not match any attribute";
            }
        }

        $relationships = self::validateRelationships($errors);

        // строковые константы самого класса, кроме TYPE, — имена атрибутов и связей
        array_push($errors, ...self::namedConstantErrors(
            array_fill_keys(array_keys(static::ATTRIBUTES), 'attribute') + array_fill_keys($relationships, 'relationship'),
            ['TYPE'],
            'ATTRIBUTES or relationships()',
        ));

        if (self::hasModel()) {
            array_push($errors, ...self::validateModel());
        }

        if ($errors !== []) {
            throw new \LogicException(static::class . ":\n- " . implode("\n- ", $errors));
        }
    }

    /**
     * Проверяет `filter` и `sort` атрибута или связи по внешнему ключу.
     *
     * @return list<string>
     */
    private static function searchErrors(string $location, mixed $filter, mixed $sort, bool $composite): array
    {
        $errors = [];
        if ($filter !== null && (!\is_array($filter) || $filter === [] || !array_is_list($filter)
                || array_filter($filter, fn ($filter_operator) => !$filter_operator instanceof Operator) !== [])) {
            $errors[] = "{$location}: filter must be a non-empty list of " . Operator::class . ' cases';
        }
        if ($sort !== null && !\is_bool($sort)) {
            $errors[] = "{$location}: sort must be bool";
        }
        if ($composite && ($filter !== null || $sort !== null)) {
            $errors[] = "{$location}: composite attribute can not be filtered or sorted";
        }

        return $errors;
    }

    /**
     * Проверяет `relationships()`: имена, цели, колонки `relation` (обе `INT`), уникальность цели у `OneToOne`.
     *
     * @param list<string> $errors
     * @return list<string> имена связей
     */
    private static function validateRelationships(array &$errors): array
    {
        $names = [];
        // описание пишет человек: проверяется и то, что обещает тип возврата
        /** @var array<array-key, mixed> $relationships */
        $relationships = static::relationships();
        foreach ($relationships as $name => $relationship) {
            if (!\is_string($name)) {
                $errors[] = "relationships()[{$name}]: key must be the relationship name";
                continue;
            }
            if (!$relationship instanceof Relationship) {
                $errors[] = "relationships()[{$name}]: expected " . Relationship::class . ', got '
                    . get_debug_type($relationship);
                continue;
            }
            $location = "relationship {$name}";
            if ($name === '' || $name === 'type' || $name === 'id') {
                $errors[] = "{$location}: name must be non-empty and not reserved by JSON:API";
            }
            if (isset(static::ATTRIBUTES[$name])) {
                $errors[] = "{$location}: an attribute has the same name";
            }
            $names[] = $name;

            $target = $relationship->resource;
            if (!is_subclass_of($target, self::class)) {
                $errors[] = "{$location}: resource {$target} is not a subclass of " . self::class;
                continue;
            }
            $relation = $relationship->relation;
            if (\count($relation) !== 2) {
                $errors[] = "{$location}: relation must be [own column, column of {$target}]";
                continue;
            }
            [$own_column, $target_column] = $relation;
            // ключи связей сравнивает PHP: только у int порядок и равенство те же, что в PostgreSQL
            $own_column_type = self::columnType(static::class, $own_column, $location, $errors);
            if ($own_column_type !== null && $own_column_type !== self::INT) {
                $errors[] = "{$location}: column {$own_column} must be INT, got {$own_column_type}";
            }
            $target_column_type = self::columnType($target, $target_column, $location, $errors);
            if ($target_column_type !== null && $target_column_type !== self::INT) {
                $errors[] = "{$location}: column {$target_column} of {$target} must be INT, got {$target_column_type}";
            }
            if ($relationship instanceof OneToOne && !self::isUniqueColumn($target, $target_column)) {
                $errors[] = "{$location}: column {$target_column} must be the primary key or a unique column of {$target}"
                    . ' (OneToOne)';
            }
            if ($relationship instanceof OneToOne && ($relationship->filter !== [] || $relationship->sort)) {
                if (!self::isForeignKey($relationship)) {
                    $errors[] = "{$location}: only a foreign-key relationship can be filtered or sorted";
                }
                array_push($errors, ...self::searchErrors(
                    $location,
                    $relationship->filter === [] ? null : $relationship->filter,
                    null,
                    false,
                ));
            }
        }

        return $names;
    }

    /**
     * `type` колонки ресурса: из атрибута с этой колонкой или из сущности модели. Отсутствующую в модели колонку
     * записывает в ошибки.
     *
     * @param class-string<self> $resource
     * @param list<string>       $errors
     */
    private static function columnType(string $resource, string $column, string $location, array &$errors): ?string
    {
        $tree = $resource::modelTree();
        if ($tree !== null && !$tree->hasColumn($column)) {
            $errors[] = "{$location}: column {$column} is missing in the model of {$resource}";

            return null;
        }
        foreach ($resource::ATTRIBUTES as $name => $attribute) {
            if (!isset($attribute['columns']) && ($attribute['column'] ?? $name) === $column) {
                return $attribute['type'] ?? null;
            }
        }

        return $tree?->columnType($column);
    }

    /**
     * Уникальна ли колонка в строках ресурса: его первичный ключ или уникальная колонка основной таблицы модели.
     * Ресурс без `model()` проверяется только по первичному ключу.
     *
     * @param class-string<self> $resource
     */
    private static function isUniqueColumn(string $resource, string $column): bool
    {
        try {
            if ($column === $resource::primaryColumn()) {
                return true;
            }
        } catch (\LogicException) {
            return true; // отсутствие PRIMARY — ошибка самого ресурса
        }

        return $resource::modelTree()?->isUnique($column) ?? false;
    }

    /**
     * Разобранная модель ресурса (без кеша, ошибки — в `errors()`); null у ресурса без `model()`.
     */
    private static function modelTree(): ?ModelTree
    {
        if (!self::hasModel()) {
            return null;
        }

        return ModelTree::build(static::class);
    }

    /**
     * @param \ReflectionClass<object> $class
     */
    private static function declaresSerializer(\ReflectionClass $class, string $method): bool
    {
        return $class->hasMethod($method) && $class->getMethod($method)->getDeclaringClass()->getName() !== self::class;
    }

    /**
     * Ресурс описан моделью данных: объявляет `static model(): Join|string`.
     * Тогда `validate()` проверяет модель, а колонки и уникальность берутся из неё.
     */
    private static function hasModel(): bool
    {
        return method_exists(static::class, 'model');
    }

    /**
     * @return list<string>
     */
    private static function validateModel(): array
    {
        $tree = ModelTree::build(static::class);
        $errors = $tree->errors();

        foreach (array_keys(static::ATTRIBUTES) as $name) {
            foreach (static::attributeColumns($name) as $column) {
                if (!$tree->hasColumn($column)) {
                    $errors[] = "model: column {$column} of attribute {$name} is missing";
                }
            }
        }
        $root = $tree->rootEntity();
        try {
            $primary = static::primaryColumn();
            if ($root !== null && ($tree->columnTable($primary) !== 0 || !$root::isUnique($primary))) {
                $errors[] = 'model: primary key must be a PRIMARY or unique column of the main table';
            }
        } catch (\LogicException) {
            // отсутствие PRIMARY уже в списке ошибок
        }

        return $errors;
    }

    /**
     * Все колонки модели ресурса; null — у ресурса нет `model()`, колонки неизвестны.
     *
     * @return list<string>|null
     */
    public static function knownColumns(): ?array
    {
        if (!self::hasModel()) {
            return null;
        }

        return ModelTree::of(static::class)->columns();
    }
}
