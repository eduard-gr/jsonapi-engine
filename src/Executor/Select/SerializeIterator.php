<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor\Select;

use Generator;
use Ggs\JsonApiEngine\Document\ResourceRecord;
use Ggs\JsonApiEngine\Executor\PullExecutor;
use Ggs\JsonApiEngine\Plan\Select\SqlScanNode;
use Ggs\JsonApiEngine\Resource;

/**
 * Превращает строки источника в ресурсы JSON:API по плану.
 *
 * Поток: вход — строки array (SqlScanIterator или SharedDataIterator) → выход — ?ResourceRecord, новый объект
 * на каждую строку. В реестр ресурсы кладёт RegisterIterator над этим узлом (его ставит IteratorBuilder).
 *
 * Одна строка — один ResourceRecord (или null, если в строке нет ресурса). Из одного источника можно строить
 * ресурсы разных типов: для этого на общий SharedDataIterator ставится несколько SerializeIterator с разными
 * ресурсами.
 *
 * Ресурс собирается по плану: $columns — запись SqlScanNode::columns этого ресурса, итератор сам собирает ресурс
 * по описанию ресурса. Всё, что не зависит от строки, готовится один раз в конструкторе — где в строке лежит каждая
 * колонка и как получить значение; на каждой строке остаётся проход по готовым спискам:
 *  - первичный ключ NULL — ресурса в строке нет (LEFT JOIN), отдаётся null; иначе он — id;
 *  - атрибуты fields в их порядке: если ресурс объявил serialize<Атрибут>(array $values) — его результат
 *    ($values — все колонки атрибута по именам модели; так собирается составной атрибут из нескольких
 *    колонок), иначе единственная колонка, приведённая по type (int, float, string, bool; date и datetime —
 *    как есть). NULL остаётся null;
 *  - связи по внешнему ключу из fields (Resource::foreignRelationship(), certificate.provider) — из своей
 *    строки: {id, type} цели или data: null при NULL во внешнем ключе;
 *  - keys ResourceRecord — ключи соединений (колонки модели), целыми; NULL пропускается, и getKey() на нём
 *    бросит исключение. Ключ — первичный ключ с именем id не пишется: его даёт getKey('id').
 *
 * Деревья, собранные руками без плана (example/document.php, DocumentTreeTest), используют ManualSerializeIterator:
 * тот вызывает написанный ресурсом `$resource::serialize($tuple)`.
 *
 * Реестра узел не знает: один объект на type:id обеспечивает RegisterIterator над ним.
 *
 * В explain() узел описывается классом ресурса, алиасом и fields.
 *
 * Узел ленивый: строка читается, только когда потребитель запрашивает следующий ресурс.
 * Это важно при чтении через SharedDataIterator — каждый шаг этого генератора
 * читает ровно одну текущую строку общего источника.
 *
 * @psalm-import-type RowResource from SqlScanNode
 */
class SerializeIterator implements PullExecutor
{
    /** Колонка первичного ключа в строке */
    private string $primary;

    private string $alias;

    /** @var list<string> */
    private array $fields = [];

    /** @var array<string, array{columns: array<string, string>, method: ?string, type: ?string}> атрибут → как читать */
    private array $attributes = [];

    /** @var array<string, array{column: string, type: string}> связь по внешнему ключу → колонка в строке, type цели */
    private array $foreign = [];

    /** @var array<string, string> имя ключа (колонка модели) → колонка в строке */
    private array $keys = [];

    /**
     * @param PullExecutor $source Строки источника (ассоциативные массивы): SqlScanIterator или SharedDataIterator
     * @param class-string<Resource> $resource Класс ресурса
     * @param array $columns Запись SqlScanNode::columns этого ресурса (алиас, колонки, fields, ключи)
     * @psalm-param RowResource $columns
     * @throws \LogicException если запись относится к другому ресурсу или в ней нет нужной колонки
     */
    public function __construct(
        private PullExecutor $source,
        private string $resource,
        array $columns,
    ) {
        $this->prepare(columns: $columns);
    }

    #[\Override]
    public function explain(): array
    {
        return [
            'node' => static::class,
            'resource' => $this->resource,
            'alias' => $this->alias,
            'fields' => $this->fields,
            'source' => $this->source->explain(),
        ];
    }

    /**
     * Отдаёт по одному элементу на каждую строку источника, в том числе null —
     * чтобы потоки, построенные из одного источника, оставались синхронными.
     *
     * @return Generator<int, ?ResourceRecord>
     */
    #[\Override]
    public function getIterator(): Generator
    {
        // При чтении через SharedDataIterator шаг foreach не сдвигает общий источник (окно отдаёт
        // текущую строку), поэтому каждый шаг отдаёт ресурс из строки, на которой он сейчас стоит
        foreach ($this->source as $tuple) {
            yield $this->serialize(row: $tuple);
        }
    }

    /**
     * Один раз на выполнение: где в строке лежит каждая колонка ресурса и как получить каждое значение.
     *
     * @psalm-param RowResource $columns
     * @throws \LogicException если запись относится к другому ресурсу или в ней нет нужной колонки
     */
    private function prepare(array $columns): void
    {
        $resource = $this->resource;
        if ($columns['resource'] !== $resource) {
            throw new \LogicException("Plan columns of {$columns['resource']} given to serializer of {$resource}");
        }
        $map = $columns['columns'];
        $column = static function (string $name) use ($map, $resource): string {
            return $map[$name] ?? throw new \LogicException("{$resource}: column {$name} is missing in the query row");
        };

        $this->alias = $columns['alias'];
        $this->fields = $columns['fields'];
        $primary = $resource::primaryColumn();
        $this->primary = $column($primary);

        foreach ($columns['fields'] as $name) {
            $foreign = $resource::foreignRelationship($name);
            if ($foreign !== null) {
                $this->foreign[$name] = ['column' => $column($foreign->relation[0]), 'type' => $foreign->resource::TYPE];
                continue;
            }
            $attribute = ['columns' => [], 'method' => $resource::attributeSerializer($name), 'type' => $resource::attributeType($name)];
            foreach ($resource::attributeColumns($name) as $model_column) {
                $attribute['columns'][$model_column] = $column($model_column);
            }
            // Без метода атрибут — одна колонка (составной без метода не пройдёт Resource::validate())
            if ($attribute['method'] === null && \count($attribute['columns']) !== 1) {
                throw new \LogicException("{$resource}: composite attribute {$name} requires a serialize method");
            }
            $this->attributes[$name] = $attribute;
        }

        foreach ($columns['keys'] as $key) {
            if ($key === 'id') {
                // getKey('id') — id ресурса; ключ с этим именем допустим, только если это и есть первичный ключ
                if ($primary !== 'id') {
                    throw new \LogicException("{$resource}: join key column 'id' is not the primary key");
                }
                continue;
            }
            $this->keys[$key] = $column($key);
        }
    }

    /**
     * Ресурс из строки по подготовленным спискам; null — первичный ключ NULL, ресурса в строке нет.
     *
     * @param array<string, mixed> $row
     */
    private function serialize(array $row): ?ResourceRecord
    {
        $id = $row[$this->primary];
        if ($id === null) {
            return null;
        }

        $attributes = [];
        $relationships = [];
        foreach ($this->fields as $name) {
            if (isset($this->foreign[$name])) {
                $foreign = $this->foreign[$name];
                $value = $row[$foreign['column']];
                // Формат — как у ResourceRecord::identifier(): id строкой, затем type
                $relationships[$name] = [
                    'data' => $value === null ? null : ['id' => (string) (int) $value, 'type' => $foreign['type']],
                ];
                continue;
            }

            $attribute = $this->attributes[$name];
            if ($attribute['method'] !== null) {
                $values = [];
                foreach ($attribute['columns'] as $model_column => $row_column) {
                    $values[$model_column] = $row[$row_column];
                }
                $attributes[$name] = $this->resource::{$attribute['method']}($values);
            } else {
                $attributes[$name] = self::cast(value: $row[reset($attribute['columns'])], type: $attribute['type']);
            }
        }

        $keys = [];
        foreach ($this->keys as $key => $row_column) {
            if ($row[$row_column] !== null) {
                $keys[$key] = (int) $row[$row_column];
            }
        }

        return new ResourceRecord(
            id: (int) $id,
            type: $this->resource::TYPE,
            attributes: $attributes,
            relationships: $relationships,
            keys: $keys,
        );
    }

    /**
     * Значение колонки по type атрибута; NULL остаётся null, date и datetime — как пришли из базы.
     */
    private static function cast(mixed $value, ?string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            Resource::INT => (int) $value,
            Resource::FLOAT => (float) $value,
            Resource::STRING => (string) $value,
            Resource::BOOL => (bool) $value,
            default => $value,
        };
    }
}
