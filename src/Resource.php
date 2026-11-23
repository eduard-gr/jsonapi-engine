<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine;

use Ggs\JsonApiEngine\Model\MetadataInterface;
use Ggs\JsonApiEngine\Model\ModelTree;
use Ggs\JsonApiEngine\Model\NamedConstants;
use Ggs\JsonApiEngine\Resource\DeserializeInterface;
use Ggs\JsonApiEngine\Resource\SerializeInterface;
use Ggs\JsonApiEngine\Resource\ManyToOne;
use Ggs\JsonApiEngine\Resource\OneToOne;
use Ggs\JsonApiEngine\Resource\Relationship;

/**
 * Базовый класс описания ресурса JSON:API: тип, атрибуты, связи, модель данных и сериализация.
 *
 * Наследник объявляет константы `TYPE`, `ATTRIBUTES` и имён, `getRelationships()` и `getModel()`. Ресурс из строки запроса
 * собирает `Executor\Select\SerializeReader` по этому описанию.
 *
 * Описание статическое (константы и статические методы): парсер, планировщик и план ссылаются на ресурс по `::class`
 * и объект не создают. Экземпляр нужен исполнителям, которые работают с данными: его создаёт контейнер зависимостей
 * приложения (PSR-11) вместе с зависимостями конструктора ресурса, а берут `ReaderBuilder` (для
 * `SerializeReader`) и `WriterBuilder` (для `InsertResourceWriter`/`UpdateResourceWriter`) по имени класса —
 * всегда, и для ресурса без зависимостей (у него конструктора нет). На экземпляре вызываются только методы двух
 * интерфейсов ниже; им и доступны зависимости. Один объект служит всем строкам и выполнениям: состояние между
 * вызовами он хранить не должен.
 *
 * Свою обработку данных ресурс объявляет интерфейсом — интерфейс и есть признак, что операция поддерживается:
 *  - `Resource\SerializeInterface` (чтение): `serialize(array $tuple): ?ResourceRecord` — ресурс сам собирает себя из
 *    строки запроса. `ReaderBuilder` ставит для такого ресурса `ManualSerializeReader` вместо
 *    `SerializeReader`. Так собирается ресурс, у которого тип или набор атрибутов зависит от строки, и любой свой
 *    формат значения. Колонки, нужные методу сверх колонок запрошенных атрибутов, ресурс называет в
 *    `getRequiredColumns()`;
 *  - `Resource\DeserializeInterface` (запись): `deserialize(array $attributes, array $references): array` — ресурс сам
 *    строит строку «колонка модели → значение» из данных операции. `WriterBuilder` даёт писателям такого ресурса
 *    `ManualResourceDeserializer` вместо `ResourceDeserializer`.
 * Каждый интерфейс выключает общую обработку ресурса целиком (все атрибуты и связи по внешнему ключу — на методе),
 * поэтому реализовывать его «на всякий случай» нельзя. Метод `serialize()` или `deserialize()` без интерфейса никто
 * не вызовет — `validate()` считает это ошибкой. Отдельных методов на атрибут (`serialize<Атрибут>()`,
 * `deserialize<Атрибут>()`) нет — они убраны.
 *
 * Составной атрибут (`columns`) по описанию не обрабатывается: чтобы его читать, ресурс обязан реализовать
 * `SerializeInterface`; чтобы писать — `DeserializeInterface`, иначе атрибут объявляется `'mode' => self::READ` (парсер
 * записи такой атрибут отклоняет). Оба правила проверяет `validate()`.
 */
abstract class Resource implements MetadataInterface
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
     * разрешает `new` в константах. Вызывается один раз на класс за процесс (`getRelationship()` кеширует карту).
     *
     * @return array<string, Relationship>
     */
    public static function getRelationships(): array
    {
        return [];
    }

    /**
     * Связь с этим именем или null, если её нет.
     */
    public static function getRelationship(string $name): ?Relationship
    {
        self::$relationship_maps[static::class] ??= static::getRelationships();

        return self::$relationship_maps[static::class][$name] ?? null;
    }

    /**
     * Связь «к одному» по внешнему ключу в строке ресурса (см. `isForeignKey()`): `ManyToOne` (`certificate.provider`)
     * или `OneToOne`, если её ключ лежит у владельца. Идентификатор такой связи известен из своей строки, без `JOIN`,
     * поэтому она запрашивается в `fields`, как атрибут, и выводится в `relationships` без `include`.
     */
    public static function getForeignRelationship(string $name): OneToOne|ManyToOne|null
    {
        $relationship = static::getRelationship($name);

        return ($relationship instanceof OneToOne || $relationship instanceof ManyToOne)
            && self::isForeignKey($relationship) ? $relationship : null;
    }

    /**
     * Связь по внешнему ключу в строке этого ресурса: колонка ресурса в `relation` — не его первичный ключ,
     * а колонка цели — первичный ключ цели (`certificate.provider`: `[provider_id, id]`).
     */
    private static function isForeignKey(OneToOne|ManyToOne $relationship): bool
    {
        return $relationship->relation[0] !== static::getPrimaryKeyColumnName()
            && $relationship->relation[1] === $relationship->resource::getPrimaryKeyColumnName();
    }

    /**
     * Связи «к одному» по внешнему ключу ресурса (см. `getForeignRelationship()`).
     *
     * @return array<string, OneToOne|ManyToOne> имя → связь
     */
    public static function getForeignRelationships(): array
    {
        $result = [];
        foreach (array_keys(static::getRelationships()) as $name) {
            $foreign = static::getForeignRelationship((string) $name);
            if ($foreign !== null) {
                $result[(string) $name] = $foreign;
            }
        }

        return $result;
    }

    public static function getType(): string
    {
        return static::TYPE;
    }

    /**
     * Колонка первичного ключа.
     */
    public static function getPrimaryKeyColumnName(): string
    {
        $name = static::getPrimaryAttribute();

        return static::ATTRIBUTES[$name]['column'] ?? $name;
    }

    /**
     * Имя атрибута первичного ключа.
     */
    public static function getPrimaryAttribute(): string
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
     * ключу ресурса (`getForeignRelationship()`), её колонка — колонка внешнего ключа, `type` — тип первичного ключа
     * цели.
     */
    public static function hasAttribute(string $name): bool
    {
        return isset(static::ATTRIBUTES[$name]) || static::getForeignRelationship($name) !== null;
    }

    /**
     * Имена атрибутов, кроме первичного ключа, и связей по внешнему ключу ресурса.
     *
     * @return list<string>
     */
    public static function getPublicAttributes(): array
    {
        $names = [];
        foreach (static::ATTRIBUTES as $name => $attribute) {
            if (($attribute['constraint'] ?? null) !== self::PRIMARY) {
                $names[] = $name;
            }
        }
        foreach (array_keys(static::getForeignRelationships()) as $name) {
            $names[] = $name;
        }

        return $names;
    }

    /**
     * Колонки атрибута: одна у обычного атрибута и связи по внешнему ключу, все — у составного.
     *
     * @return list<string>
     */
    public static function getAttributeColumns(string $name): array
    {
        if (!isset(static::ATTRIBUTES[$name])) {
            return [self::getForeign($name)->relation[0]];
        }
        $attribute = static::ATTRIBUTES[$name];

        return $attribute['columns'] ?? [$attribute['column'] ?? $name];
    }

    /**
     * `type` атрибута; null у составного без `type`.
     */
    public static function getAttributeType(string $name): ?string
    {
        if (!isset(static::ATTRIBUTES[$name])) {
            $resource = self::getForeign($name)->resource;

            return $resource::getAttributeType($resource::getPrimaryAttribute());
        }

        return static::ATTRIBUTES[$name]['type'] ?? null;
    }

    /**
     * Атрибут, значение которого хранится в колонке (не составной): так параметр маршрута находит свой атрибут.
     */
    public static function getColumnAttribute(string $column): ?string
    {
        foreach (static::ATTRIBUTES as $name => $attribute) {
            if (!isset($attribute['columns']) && ($attribute['column'] ?? $name) === $column) {
                return $name;
            }
        }
        foreach (static::getForeignRelationships() as $name => $relationship) {
            if ($relationship->relation[0] === $column) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Колонка атрибута для фильтра.
     */
    public static function getFilterColumn(string $name): string
    {
        if (isset(static::ATTRIBUTES[$name]['columns'])) {
            throw new \LogicException(static::class . ": composite attribute {$name} can not be filtered");
        }

        return static::getAttributeColumns($name)[0];
    }

    /**
     * Операторы, которыми можно фильтровать атрибут (`filter` атрибута); пусто — фильтровать нельзя. Парсер
     * разрешает клиенту ровно этот список. По связи фильтровать нельзя: связь описывает только отношение ресурсов
     * (`Resource\Relationship`), своего фильтра у неё нет — и у связи по внешнему ключу тоже, хотя в `fields` она
     * называется наравне с атрибутом.
     *
     * @return list<Operator>
     */
    public static function getFilterOperators(string $name): array
    {
        return static::ATTRIBUTES[$name]['filter'] ?? [];
    }

    /**
     * Можно ли сортировать по атрибуту (`sort` атрибута). По связи сортировать нельзя (см. `getFilterOperators()`).
     */
    public static function isSortable(string $name): bool
    {
        return static::ATTRIBUTES[$name]['sort'] ?? false;
    }

    /**
     * Колонка внешнего ключа связи по внешнему ключу ресурса; null, если такой связи нет.
     */
    public static function getForeignColumn(string $name): ?string
    {
        return static::getForeignRelationship($name)?->relation[0];
    }

    private static function getForeign(string $name): OneToOne|ManyToOne
    {
        return static::getForeignRelationship($name)
            ?? throw new \InvalidArgumentException(static::class . ": unknown attribute {$name}");
    }

    /**
     * Колонки модели, которые ресурсу нужны в строке запроса сверх колонок атрибутов `fields`. Запрос плана выбирает
     * только первичный ключ, колонки атрибутов `fields` и ключи соединений; колонку, которой нет ни у одного
     * запрошенного атрибута, он не выберет, а `LEFT JOIN` её таблицы отбросит. Ресурс, чьей сериализации такая
     * колонка нужна (например, внутренняя колонка, по которой выбирается вид ресурса), переопределяет этот метод.
     *
     * Вызывает `Plan\Select\EntityScanNodeDraft::withSelect()` на шаге `sql` — один раз на ресурс запроса, с `fields`
     * узла плана: ресурс сам решает по запрошенным полям, что ему нужно. Метод — код, а не константа, поэтому
     * список колонок известен только после вызова; он должен зависеть только от `$fields` (план строится один раз
     * и выполняется много раз).
     *
     * Что получает колонка из результата: место в списке колонок запроса (`<алиас>_<колонка>`), в карте
     * `EntityScanNode::columns` ресурса (`columns`) и подключённую таблицу в FROM. Атрибутом она не становится:
     * сам `SerializeReader` её не выводит, в `fields`, фильтрах и сортировке клиента её нет. Читает её свой
     * `serialize()` ресурса (ручная сериализация): он получает её в `$tuple` под именем колонки модели. В теле CTE
     * цепочки предков её нет.
     *
     * Не проверяется заранее: колонка, которой нет в модели ресурса, — `LogicException` при построении запроса
     * (а не в `validate()`: метод зависит от `$fields`). Повторы и колонки, уже выбранные по `fields`, не мешают.
     *
     * @param list<string> $fields запрошенные атрибуты и связи по внешнему ключу (имена в API), как в узле плана
     * @return list<string> колонки модели ресурса
     */
    public static function getRequiredColumns(array $fields): array
    {
        return [];
    }

    /**
     * Колонки ресурса, которые нужны от модели данных: первичный ключ, колонки атрибутов `fields`, колонки
     * `getRequiredColumns()` и `extra`.
     *
     * @param list<string> $fields атрибуты и связи по внешнему ключу (имена в API)
     * @param list<string> $extra  другие колонки ресурса
     * @return list<string>
     */
    public static function getColumnsOf(array $fields, array $extra = []): array
    {
        $columns = [static::getPrimaryKeyColumnName()];
        foreach ($fields as $name) {
            array_push($columns, ...static::getAttributeColumns($name));
        }

        return array_values(array_unique([...$columns, ...static::getRequiredColumns($fields), ...$extra]));
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
            // Режим атрибута — маска READ и WRITE; FIXED бывает только у колонки сущности. Атрибут без READ
            // (только запись) пока не поддержан: его всё равно отдали бы клиенту
            $mode = $attribute['mode'] ?? self::READ_WRITE;
            if (!\is_int($mode) || $mode <= 0 || ($mode & ~self::READ_WRITE) !== 0) {
                $errors[] = "{$location}: mode must be a mask of READ and WRITE";
                $mode = self::READ_WRITE;
            } elseif (($mode & self::READ) === 0) {
                $errors[] = "{$location}: an attribute without READ is not supported yet";
            }
            // Постоянную колонку сущности (FIXED) пишет движок, клиент её не меняет
            if (($mode & self::WRITE) !== 0 && isset($attribute['column']) && \is_string($attribute['column'])
                    && (self::getModelTree()?->isFixedColumn($attribute['column']) ?? false)) {
                $errors[] = "{$location}: column {$attribute['column']} is FIXED, declare 'mode' => self::READ";
            }
            if (isset($attribute['nullable']) && !\is_bool($attribute['nullable'])) {
                $errors[] = "{$location}: nullable must be bool";
            }
            array_push($errors, ...self::getSearchErrors($location, $attribute['filter'] ?? null, $attribute['sort'] ?? null, $composite));

            $constraint = $attribute['constraint'] ?? null;
            // JSON:API: type и id — члены идентификатора ресурса, атрибут или связь так называться не могут
            if ($name === 'type' || ($name === 'id' && $constraint !== self::PRIMARY)) {
                $errors[] = "{$location}: name {$name} is reserved by JSON:API";
            }
            if ($constraint === self::FOREIGN) {
                $errors[] = "{$location}: FOREIGN is not an attribute constraint, declare a ManyToOne in getRelationships()";
            } elseif ($constraint !== null && $constraint !== self::PRIMARY) {
                $errors[] = "{$location}: unknown constraint {$constraint}";
            }
            if ($constraint === self::PRIMARY) {
                $primary[] = $name;
                if ($composite) {
                    $errors[] = "{$location}: PRIMARY can not be composite";
                }
            }
            // По описанию составной атрибут не собрать и не записать: это делают только методы самого ресурса
            if ($composite && !$class->implementsInterface(SerializeInterface::class)) {
                $errors[] = "{$location}: composite attribute requires " . SerializeInterface::class;
            }
            if ($composite && ($mode & self::WRITE) !== 0
                    && !$class->implementsInterface(DeserializeInterface::class)) {
                $errors[] = "{$location}: composite attribute can not be written, declare 'mode' => self::READ or "
                    . 'implement ' . DeserializeInterface::class;
            }
        }

        if (\count($primary) !== 1) {
            $errors[] = 'exactly one PRIMARY attribute is required, found ' . \count($primary);
        }

        // Методы ручной обработки вызываются только через свой интерфейс: без него метод молча не сработает
        foreach (['serialize' => SerializeInterface::class, 'deserialize' => DeserializeInterface::class] as $method => $interface) {
            if ($class->hasMethod($method) && !$class->implementsInterface($interface)) {
                $errors[] = "method {$method}() is never called: implement {$interface}";
            }
        }

        $relationships = self::validateRelationships($errors);

        // строковые константы самого класса, кроме TYPE, — имена атрибутов и связей
        array_push($errors, ...self::getNamedConstantErrors(
            array_fill_keys(array_keys(static::ATTRIBUTES), 'attribute') + array_fill_keys($relationships, 'relationship'),
            ['TYPE'],
            'ATTRIBUTES or getRelationships()',
        ));

        if (self::hasModel()) {
            array_push($errors, ...self::validateModel());
        }

        if ($errors !== []) {
            throw new \LogicException(static::class . ":\n- " . implode("\n- ", $errors));
        }
    }

    /**
     * Проверяет `filter` и `sort` атрибута.
     *
     * @return list<string>
     */
    private static function getSearchErrors(string $location, mixed $filter, mixed $sort, bool $composite): array
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
     * Проверяет `getRelationships()`: имена, цели, колонки `relation` (обе `INT`), уникальность цели у `OneToOne`.
     *
     * @param list<string> $errors
     * @return list<string> имена связей
     */
    private static function validateRelationships(array &$errors): array
    {
        $names = [];
        // описание пишет человек: проверяется и то, что обещает тип возврата
        /** @var array<array-key, mixed> $relationships */
        $relationships = static::getRelationships();
        foreach ($relationships as $name => $relationship) {
            if (!\is_string($name)) {
                $errors[] = "getRelationships()[{$name}]: key must be the relationship name";
                continue;
            }
            if (!$relationship instanceof Relationship) {
                $errors[] = "getRelationships()[{$name}]: expected " . Relationship::class . ', got '
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
            $own_column_type = self::getColumnType(static::class, $own_column, $location, $errors);
            if ($own_column_type !== null && $own_column_type !== self::INT) {
                $errors[] = "{$location}: column {$own_column} must be INT, got {$own_column_type}";
            }
            $target_column_type = self::getColumnType($target, $target_column, $location, $errors);
            if ($target_column_type !== null && $target_column_type !== self::INT) {
                $errors[] = "{$location}: column {$target_column} of {$target} must be INT, got {$target_column_type}";
            }
            // Постоянная колонка (FIXED) одинакова у всех строк сущности: связь на неё опираться не может
            if (self::getModelTree()?->isFixedColumn($own_column) ?? false) {
                $errors[] = "{$location}: column {$own_column} is FIXED and can not be a relationship key";
            }
            if (is_subclass_of($target, self::class) && ($target::getModelTree()?->isFixedColumn($target_column) ?? false)) {
                $errors[] = "{$location}: column {$target_column} of {$target} is FIXED and can not be a relationship key";
            }
            if ($relationship instanceof OneToOne && !self::isUniqueColumn($target, $target_column)) {
                $errors[] = "{$location}: column {$target_column} must be the primary key or a unique column of {$target}"
                    . ' (OneToOne)';
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
    private static function getColumnType(string $resource, string $column, string $location, array &$errors): ?string
    {
        $tree = $resource::getModelTree();
        if ($tree !== null && !$tree->hasColumn($column)) {
            $errors[] = "{$location}: column {$column} is missing in the model of {$resource}";

            return null;
        }
        foreach ($resource::ATTRIBUTES as $name => $attribute) {
            if (!isset($attribute['columns']) && ($attribute['column'] ?? $name) === $column) {
                return $attribute['type'] ?? null;
            }
        }

        return $tree?->getColumnType($column);
    }

    /**
     * Уникальна ли колонка в строках ресурса: его первичный ключ или уникальная колонка основной таблицы модели.
     * Ресурс без `getModel()` проверяется только по первичному ключу.
     *
     * @param class-string<self> $resource
     */
    private static function isUniqueColumn(string $resource, string $column): bool
    {
        try {
            if ($column === $resource::getPrimaryKeyColumnName()) {
                return true;
            }
        } catch (\LogicException) {
            return true; // отсутствие PRIMARY — ошибка самого ресурса
        }

        return $resource::getModelTree()?->isUnique($column) ?? false;
    }

    /**
     * Разобранная модель ресурса (без кеша, ошибки — в `getErrors()`); null у ресурса без `getModel()`.
     */
    private static function getModelTree(): ?ModelTree
    {
        if (!self::hasModel()) {
            return null;
        }

        return ModelTree::build(static::class);
    }

    /**
     * Ресурс описан моделью данных: объявляет `static getModel(): Join|string`.
     * Тогда `validate()` проверяет модель, а колонки и уникальность берутся из неё.
     */
    private static function hasModel(): bool
    {
        return method_exists(static::class, 'getModel');
    }

    /**
     * @return list<string>
     */
    private static function validateModel(): array
    {
        $tree = ModelTree::build(static::class);
        $errors = $tree->getErrors();

        foreach (array_keys(static::ATTRIBUTES) as $name) {
            foreach (static::getAttributeColumns($name) as $column) {
                if (!$tree->hasColumn($column)) {
                    $errors[] = "model: column {$column} of attribute {$name} is missing";
                }
            }
        }
        $root = $tree->getRootDataSource();
        try {
            $primary = static::getPrimaryKeyColumnName();
            if ($root !== null && ($tree->getColumnTable($primary) !== 0 || !$root::isUnique($primary))) {
                $errors[] = 'model: primary key must be a PRIMARY or unique column of the main table';
            }
        } catch (\LogicException) {
            // отсутствие PRIMARY уже в списке ошибок
        }

        return $errors;
    }

    /**
     * Все колонки модели ресурса; null — у ресурса нет `getModel()`, колонки неизвестны.
     *
     * @return list<string>|null
     */
    public static function getKnownColumns(): ?array
    {
        if (!self::hasModel()) {
            return null;
        }

        return ModelTree::of(static::class)->getColumns();
    }
}
