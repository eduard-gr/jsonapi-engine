<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor\Select;

use Generator;
use Ggs\JsonApiEngine\Document\ResourceRecord;
use Ggs\JsonApiEngine\Executor\PullExecutorInterface;
use Ggs\JsonApiEngine\Plan\Select\EntityScanNode;
use Ggs\JsonApiEngine\Resource;
use Ggs\JsonApiEngine\Resource\SerializeInterface;

/**
 * Превращает строки источника в ресурсы JSON:API по плану.
 *
 * Поток: вход — строки array (EntityScanReader или EntitySharedWindowReader) → выход — ?ResourceRecord, новый объект
 * на каждую строку. В реестр ресурсы кладёт RegisterReader над этим узлом (его ставит ReaderBuilder).
 *
 * Одна строка — один ResourceRecord (или null, если в строке нет ресурса). Из одного источника можно строить
 * ресурсы разных типов: для этого на общий EntitySharedWindowReader ставится несколько SerializeReader с разными
 * ресурсами.
 *
 * Ресурс собирается по плану: $columns — запись EntityScanNode::columns этого ресурса, читатель сам собирает ресурс
 * по описанию ресурса. Всё, что не зависит от строки, готовится один раз в конструкторе — где в строке лежит каждая
 * колонка и как получить значение; на каждой строке остаётся проход по готовым спискам:
 *  - первичный ключ NULL — ресурса в строке нет (LEFT JOIN), отдаётся null; иначе он — id;
 *  - атрибуты fields в их порядке: единственная колонка атрибута, приведённая по type (int, float, string, bool;
 *    date и datetime — как есть). NULL остаётся null. Своего формата у отдельного атрибута нет, составной атрибут
 *    (несколько колонок) так не собрать — LogicException в конструкторе: такому ресурсу нужен Resource\SerializeInterface;
 *  - связи по внешнему ключу из fields (Resource::getForeignRelationship(), certificate.provider) — из своей
 *    строки: {id, type} цели или data: null при NULL во внешнем ключе;
 *  - keys ResourceRecord — ключи соединений (колонки модели), целыми; NULL пропускается, и getKey() на нём
 *    бросит исключение. Ключ — первичный ключ с именем id не пишется: его даёт getKey('id').
 *
 * Ресурс — экземпляр: его создаёт контейнер зависимостей приложения вместе с зависимостями конструктора
 * (ReaderBuilder берёт его по имени класса), узел получает готовый объект. Этот узел читает только описание
 * (TYPE, ATTRIBUTES, связи, модель), статически; метод экземпляра (`serialize()`) вызывает ManualSerializeReader.
 *
 * Ручная сериализация — отдельный узел-наследник `ManualSerializeReader`: ресурс, реализующий
 * `Resource\SerializeInterface`, собирает себя сам. Какой из двух узлов поставить, решает тот, кто строит дерево:
 * `ReaderBuilder` смотрит, реализует ли класс ресурса интерфейс. Сам этот узел метод не вызывает и такой ресурс не
 * принимает: получив его (в дереве, собранном руками), он бросает LogicException в конструкторе — собрать по
 * описанию ресурс, который собирает себя сам, значило бы молча выдать другой документ.
 *
 * Наследнику узел отдаёт общее: проверку записи колонок, колонку первичного ключа, ключи соединений (`getKeys()`)
 * и поток; наследник заменяет подготовку (`prepare()`) и сборку ресурса из строки (`serialize()`).
 *
 * Деревья, собранные руками без плана (example/document.php, DocumentTreeTest), ставят этот же узел и пишут
 * запись $columns руками: «колонка модели → алиас в своём запросе»; ресурс создают сами (`new DomainResource()`).
 *
 * Реестра узел не знает: один объект на type:id обеспечивает RegisterReader над ним.
 *
 * В explain() узел описывается своим классом (так видно, какой из двух узлов стоит), классом ресурса, алиасом
 * и fields.
 *
 * Узел ленивый: строка читается, только когда потребитель запрашивает следующий ресурс.
 * Это важно при чтении через EntitySharedWindowReader — каждый шаг этого генератора
 * читает ровно одну текущую строку общего источника.
 *
 * @psalm-import-type RowResource from EntityScanNode
 */
class SerializeReader implements PullExecutorInterface
{
    /** Колонка первичного ключа в строке */
    protected string $primary;

    private string $alias;

    /** @var list<string> запрошенные атрибуты и связи по внешнему ключу, в порядке запроса */
    protected array $fields = [];

    /** @var array<string, array{column: string, type: ?string}> атрибут → колонка в строке и type */
    private array $attributes = [];

    /** @var array<string, array{column: string, type: string}> связь по внешнему ключу → колонка в строке, type цели */
    private array $foreign = [];

    /** @var array<string, string> имя ключа (колонка модели) → колонка в строке */
    private array $keys = [];

    /**
     * @param PullExecutorInterface $source Строки источника (ассоциативные массивы): EntityScanReader или EntitySharedWindowReader
     * @param Resource $resource Ресурс: объект из контейнера зависимостей (в ручных деревьях — созданный руками)
     * @param array $columns Запись EntityScanNode::columns этого ресурса (алиас, колонки, fields, ключи)
     * @psalm-param RowResource $columns
     * @throws \LogicException если запись относится к другому ресурсу или в ней нет нужной колонки
     */
    public function __construct(
        private PullExecutorInterface $source,
        protected Resource $resource,
        array $columns,
    ) {
        $this->prepareKeys(columns: $columns);
        $this->prepare(columns: $columns);
    }

    #[\Override]
    public function explain(): array
    {
        return [
            'node' => static::class,
            'resource' => $this->resource::class,
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
        // При чтении через EntitySharedWindowReader шаг foreach не сдвигает общий источник (окно отдаёт
        // текущую строку), поэтому каждый шаг отдаёт ресурс из строки, на которой он сейчас стоит
        foreach ($this->source as $tuple) {
            yield $this->serialize(row: $tuple);
        }
    }

    /**
     * Общая подготовка, один раз на выполнение: запись колонок — этого ресурса, алиас и fields, где в строке
     * первичный ключ и ключи соединений.
     *
     * @psalm-param RowResource $columns
     * @throws \LogicException если запись относится к другому ресурсу или в ней нет нужной колонки
     */
    private function prepareKeys(array $columns): void
    {
        $resource = $this->resource::class;
        if ($columns['resource'] !== $resource) {
            throw new \LogicException("Plan columns of {$columns['resource']} given to serializer of {$resource}");
        }

        $this->alias = $columns['alias'];
        $this->fields = $columns['fields'];
        $primary = $resource::getPrimaryKeyColumnName();
        $this->primary = $this->getRowColumn(columns: $columns, name: $primary);

        foreach ($columns['keys'] as $key) {
            if ($key === 'id') {
                // getKey('id') — id ресурса; ключ с этим именем допустим, только если это и есть первичный ключ
                if ($primary !== 'id') {
                    throw new \LogicException("{$resource}: join key column 'id' is not the primary key");
                }
                continue;
            }
            $this->keys[$key] = $this->getRowColumn(columns: $columns, name: $key);
        }
    }

    /**
     * Подготовка сборки ресурса, один раз на выполнение: как получить каждый атрибут и связь по внешнему ключу из
     * fields. Наследник заменяет её своей.
     *
     * @psalm-param RowResource $columns
     * @throws \LogicException если ресурс реализует SerializeInterface, в записи нет нужной колонки или атрибут
     *                         составной
     */
    protected function prepare(array $columns): void
    {
        $resource = $this->resource::class;
        if ($this->resource instanceof SerializeInterface) {
            throw new \LogicException(
                "{$resource} implements " . SerializeInterface::class . ': use ' . ManualSerializeReader::class,
            );
        }
        foreach ($columns['fields'] as $name) {
            $foreign = $resource::getForeignRelationship($name);
            if ($foreign !== null) {
                $this->foreign[$name] = [
                    'column' => $this->getRowColumn(columns: $columns, name: $foreign->relation[0]),
                    'type' => $foreign->resource::TYPE,
                ];
                continue;
            }
            $model_columns = $resource::getAttributeColumns($name);
            // По описанию собирается только атрибут из одной колонки (составной без SerializeInterface не пройдёт
            // Resource::validate())
            if (\count($model_columns) !== 1) {
                throw new \LogicException("{$resource}: composite attribute {$name} requires " . SerializeInterface::class);
            }
            $this->attributes[$name] = [
                'column' => $this->getRowColumn(columns: $columns, name: $model_columns[0]),
                'type' => $resource::getAttributeType($name),
            ];
        }
    }

    /**
     * Колонка строки, в которой лежит колонка модели ресурса.
     *
     * @psalm-param RowResource $columns
     * @throws \LogicException если колонки нет в записи
     */
    protected function getRowColumn(array $columns, string $name): string
    {
        return $columns['columns'][$name]
            ?? throw new \LogicException($this->resource::class . ": column {$name} is missing in the query row");
    }

    /**
     * Ключи соединений ресурса из строки, целыми; NULL пропускается.
     *
     * @param array<string, mixed> $row
     * @return array<string, int> имя ключа (колонка модели) → значение
     */
    protected function getKeys(array $row): array
    {
        $keys = [];
        foreach ($this->keys as $key => $row_column) {
            if ($row[$row_column] !== null) {
                $keys[$key] = (int) $row[$row_column];
            }
        }

        return $keys;
    }

    /**
     * Ресурс из строки по подготовленным спискам; null — первичный ключ NULL (ресурса в строке нет). Наследник
     * заменяет сборку своей.
     *
     * @param array<string, mixed> $row
     */
    protected function serialize(array $row): ?ResourceRecord
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
                // Формат — как у ResourceRecord::getIdentifier(): id строкой, затем type
                $relationships[$name] = [
                    'data' => $value === null ? null : ['id' => (string) (int) $value, 'type' => $foreign['type']],
                ];
                continue;
            }

            $attribute = $this->attributes[$name];
            $attributes[$name] = self::cast(value: $row[$attribute['column']], type: $attribute['type']);
        }

        return new ResourceRecord(
            id: (int) $id,
            type: $this->resource::TYPE,
            attributes: $attributes,
            relationships: $relationships,
            keys: $this->getKeys(row: $row),
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
