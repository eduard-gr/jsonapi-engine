<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor;

use Generator;
use Ggs\JsonApiEngine\Document\ResourceRecord;
use Ggs\JsonApiEngine\Document\ResourceRegistry;
use Ggs\JsonApiEngine\Plan\SharedScanNode;
use Ggs\JsonApiEngine\Resource;

/**
 * Превращает строки источника в ресурсы JSON:API и регистрирует их в реестре.
 *
 * Поток: вход — строки array (SqlScanIterator или SharedDataIterator) → выход — ?ResourceRecord;
 * для повторного type:id — тот же объект, что был отдан раньше.
 *
 * Одна строка — один ResourceRecord (или null, если в строке нет ресурса). Из одного источника можно строить
 * ресурсы разных типов: для этого на общий SharedDataIterator ставится несколько SerializeIterator с разными
 * ресурсами. Режимов два:
 *  - по плану (передан $columns — запись SharedScanNode::columns этого ресурса): итератор сам собирает ресурс
 *    по описанию ресурса. Всё, что не зависит от строки, готовится один раз в конструкторе — где в строке
 *    лежит каждая колонка и как получить значение; на каждой строке остаётся проход по готовым спискам:
 *     - первичный ключ NULL — ресурса в строке нет (LEFT JOIN), отдаётся null; иначе он — id;
 *     - атрибуты fields в их порядке: если ресурс объявил serialize<Атрибут>(array $values) — его результат
 *       ($values — все колонки атрибута по именам модели; так собирается составной атрибут из нескольких
 *       колонок), иначе единственная колонка, приведённая по type (int, float, string, bool; date и datetime —
 *       как есть). NULL остаётся null;
 *     - связи по внешнему ключу из fields (Resource::foreignRelationship(), certificate.provider) — из своей
 *       строки: {id, type} цели или data: null при NULL во внешнем ключе;
 *     - keys ResourceRecord — ключи соединений (колонки модели), целыми; NULL пропускается, и getKey() на нём
 *       бросит исключение. Ключ — первичный ключ с именем id не пишется: его даёт getKey('id');
 *  - ручной (без $columns): `$resource::serialize($tuple)`, который ресурс пишет сам по алиасам своего
 *    запроса (example/document.php, DocumentTreeTest). Уйдёт, когда ручные деревья станут не нужны.
 *
 * Каждый построенный ресурс проходит через ResourceRegistry::intern(): если ресурс с тем же
 * type:id уже встречался, наружу уходит зарегистрированный объект, а только что построенный
 * отбрасывается. Так один ресурс существует в одном экземпляре на всё выполнение.
 * Сериализация при этом выполняется на каждой строке: повторный ресурс сначала строится,
 * а потом отбрасывается — реестр экономит память документа, но не работу сериализации.
 * Реестр один на выполнение — тот же экземпляр получают все SerializeIterator дерева и Document.
 *
 * В explain() узел описывается классом ресурса, а по плану — ещё алиасом и fields. Реестр не описывается:
 * это состояние выполнения, а не топология.
 *
 * Узел ленивый: строка читается, только когда потребитель запрашивает следующий ресурс.
 * Это важно при чтении через SharedDataIterator — каждый шаг этого генератора
 * читает ровно одну текущую строку общего источника.
 *
 * @psalm-import-type RowResource from SharedScanNode
 */
class SerializeIterator implements ExecutorIterator
{
    /** Колонка первичного ключа в строке; null — ручной режим */
    private ?string $primary = null;

    private ?string $alias = null;

    /** @var list<string> */
    private array $fields = [];

    /** @var array<string, array{columns: array<string, string>, method: ?string, type: ?string}> атрибут → как читать */
    private array $attributes = [];

    /** @var array<string, array{column: string, type: string}> связь по внешнему ключу → колонка в строке, type цели */
    private array $foreign = [];

    /** @var array<string, string> имя ключа (колонка модели) → колонка в строке */
    private array $keys = [];

    /**
     * @param ExecutorIterator $source Строки источника (ассоциативные массивы): SqlScanIterator или SharedDataIterator
     * @param class-string<Resource> $resource Класс ресурса
     * @param ResourceRegistry $registry Реестр выполнения — один на всё дерево и Document
     * @param array|null $columns Запись SharedScanNode::columns этого ресурса (алиас, колонки, fields, ключи) —
     *        ресурс собирается по описанию; null — ручной `$resource::serialize()`
     * @psalm-param RowResource|null $columns
     * @throws \LogicException если запись относится к другому ресурсу или в ней нет нужной колонки
     */
    public function __construct(
        private ExecutorIterator $source,
        private string $resource,
        private ResourceRegistry $registry,
        ?array $columns = null,
    ) {
        if ($columns !== null) {
            $this->prepare(columns: $columns);
        }
    }

    #[\Override]
    public function explain(): array
    {
        $explain = ['node' => static::class, 'resource' => $this->resource];
        if ($this->primary !== null) {
            $explain['alias'] = $this->alias;
            $explain['fields'] = $this->fields;
        }
        $explain['source'] = $this->source->explain();

        return $explain;
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
            $record = $this->primary === null
                ? $this->resource::serialize($tuple)
                : $this->serialize(row: $tuple);

            // Дальше идёт зарегистрированный объект: при повторном type:id это не $record
            yield $record === null ? null : $this->registry->intern($record);
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
            foreach ($resource::attributeColumns($name) as $modelColumn) {
                $attribute['columns'][$modelColumn] = $column($modelColumn);
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
        $id = $row[(string) $this->primary];
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
                foreach ($attribute['columns'] as $modelColumn => $rowColumn) {
                    $values[$modelColumn] = $row[$rowColumn];
                }
                $attributes[$name] = $this->resource::{$attribute['method']}($values);
            } else {
                $attributes[$name] = self::cast(value: $row[reset($attribute['columns'])], type: $attribute['type']);
            }
        }

        $keys = [];
        foreach ($this->keys as $key => $rowColumn) {
            if ($row[$rowColumn] !== null) {
                $keys[$key] = (int) $row[$rowColumn];
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
