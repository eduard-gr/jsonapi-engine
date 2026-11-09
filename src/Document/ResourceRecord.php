<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Document;

use InvalidArgumentException;
use OutOfBoundsException;

/**
 * Resource object JSON:API: один ресурс с id, type, attributes и relationships.
 *
 * Кроме того, хранит служебные ключи плана выполнения (keys) — целые числа, нужные узлам
 * для соединения и сортировки (например, внешний ключ certificate_id домена для HashNestJoinIterator).
 * В JSON они не выводятся. Узлы получают ключ по имени через getKey(); имя 'id' зарезервировано
 * за id самого ресурса, поэтому любой ключ соединения — это просто строка-имя.
 *
 * Объект изменяемый: создаётся в SerializeIterator, затем по ходу потока узлы соединения
 * добавляют ему связи: SharedRowJoinIterator — 1:1, HashNestJoinIterator и MergeNestJoinIterator — 1:N.
 *
 * Связь N:1 по внешнему ключу из своей строки (например, provider сертификата по provider_id)
 * записывается сразу при создании — в $relationships сериализатора, без соединения. Тогда
 * связь есть всегда, даже если связанные ресурсы не загружаются, а NULL во внешнем ключе
 * даёт пустую связь. Чтобы сами ресурсы справочника попали в included, их загружает
 * в реестр PreloadIterator. Связи 1:N так не записать: их внешний ключ лежит в строке связанного
 * ресурса, а не главного.
 *
 * Каждый ресурс существует в одном экземпляре на выполнение: RegisterIterator регистрирует его
 * в ResourceRegistry, и при повторной встрече того же type:id (например, если провайдер
 * выбирается JOIN-ом в строку каждого своего сертификата) возвращается уже зарегистрированный
 * объект. Поэтому связь, записанная ресурсу, видна везде, где он встречается.
 *
 * Связи хранят только ссылки {id, type}, а не сами объекты. В документ ресурсы попадают
 * через Document: data — из потока корня дерева, included — обходом связей (related())
 * с поиском ресурсов по ключу (key()) в реестре.
 */
class ResourceRecord implements \JsonSerializable
{
    /**
     * @param int $id Идентификатор из источника, при сериализации приводится к строке
     * @param string $type Тип ресурса JSON:API, например 'certificate'
     * @param array $attributes Поля ресурса, кроме id и type
     * @param array $relationships Связи в формате JSON:API: имя связи => ['data' => ...].
     *        Ссылки — в том же виде, что даёт identifier(): ['id' => (string) id, 'type' => ...],
     *        иначе в одном документе окажутся ссылки разного вида
     * @param array<string, int> $keys Служебные ключи плана выполнения (например, внешний ключ
     *        certificate_id для узлов соединения). В JSON не выводятся. Значения — только целые числа.
     *        Имя 'id' зарезервировано: getKey('id') возвращает id ресурса
     * @throws InvalidArgumentException если значение ключа не целое число или ключ назван 'id'
     */
    public function __construct(
        private int $id,
        private string $type,
        private array $attributes,
        private array $relationships,
        private array $keys = [],
    ) {
        // Иначе getKey('id') вернул бы id ресурса, а служебный ключ с этим именем молча потерялся бы
        if (array_key_exists('id', $this->keys)) {
            throw new InvalidArgumentException("Key name 'id' of resource {$this->type}:{$this->id} is reserved for resource id");
        }

        foreach ($this->keys as $name => $value) {
            if (!is_int($value)) {
                throw new InvalidArgumentException(
                    "Key '{$name}' of resource {$this->type}:{$this->id} must be int, " . get_debug_type($value) . ' given'
                );
            }
        }
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Ключ ресурса "type:id", например "certificate:1": по нему ресурс ищется в ResourceRegistry.
     *
     * Однозначен: id — целое число, поэтому двоеточие в нём встретиться не может
     */
    public function key(): string
    {
        return $this->type . ':' . $this->id;
    }

    /**
     * Ключи "type:id" всех ресурсов, на которые ссылаются связи этого ресурса, в порядке связей.
     *
     * to-one связь даёт один ключ или ничего (data = null — связанного ресурса нет);
     * to-many — по ключу на каждую ссылку (data = [] — ничего).
     * По ним Document обходит связи при сборке included
     *
     * @return list<string>
     */
    public function related(): array
    {
        $keys = [];

        foreach ($this->relationships as $relationship) {
            $data = $relationship['data'] ?? null;

            if ($data === null) {
                continue;
            }

            // to-one — один resource identifier object (у него есть 'type'), to-many — их массив
            foreach (isset($data['type']) ? [$data] : $data as $identifier) {
                $keys[] = $identifier['type'] . ':' . $identifier['id'];
            }
        }

        return $keys;
    }

    /**
     * Значение служебного ключа ресурса по имени — всегда целое число.
     * Имя 'id' зарезервировано и даёт id самого ресурса.
     *
     * Ключи нужны только плану выполнения (например, как ключ соединения в HashNestJoinIterator
     * или MergeNestJoinIterator, или ключ сортировки SortIterator) и в JSON не попадают: так внешние
     * ключи вроде certificate_id не оказываются в attributes, чего JSON:API не рекомендует.
     *
     * Через этот метод узлы получают любой ключ по имени — и id главного ресурса, и внешний ключ
     * связанного. Поэтому ключ соединения задаётся данными (строкой), а не кодом: дерево может
     * собирать планировщик, который замыканий не пишет.
     *
     * Отсутствующий ключ — ошибка, а не null: иначе опечатка в имени молча дала бы null,
     * и ресурсы ни с чем бы не соединились.
     *
     * @throws OutOfBoundsException если такого ключа у ресурса нет
     */
    public function getKey(string $name): int
    {
        if ($name === 'id') {
            return $this->id;
        }

        if (!array_key_exists($name, $this->keys)) {
            throw new OutOfBoundsException("Resource {$this->type}:{$this->id} has no key '{$name}'");
        }

        return $this->keys[$name];
    }

    /**
     * Resource identifier object для ссылок в relationships
     */
    public function identifier(): array
    {
        return [
            'id' => (string) $this->id, // По спецификации JSON:API id должен быть строкой
            'type' => $this->type,
        ];
    }

    /**
     * Связь to-one: в 'data' лежит один resource identifier object,
     * или null, если связанного ресурса нет (так требует JSON:API для пустой to-one связи).
     * Повторный вызов с тем же $name заменяет связь
     */
    public function setRelationship(
        string $name,
        ?ResourceRecord $related,
    ): void {
        $this->relationships[$name] = [
            'data' => $related?->identifier()
        ];
    }

    /**
     * Связь to-many: в 'data' лежит массив resource identifier objects.
     * Каждый вызов добавляет ещё одну ссылку, дубли не отбрасываются
     */
    public function addRelationship(
        string $name,
        ResourceRecord $related,
    ): void {
        $this->relationships[$name]['data'][] = $related->identifier();
    }

    /**
     * Связь to-many целиком: в 'data' лежит массив resource identifier objects.
     * Пустой массив — связанных ресурсов нет (так требует JSON:API для пустой to-many связи).
     * Повторный вызов с тем же $name заменяет связь
     *
     * @param ResourceRecord[] $related
     */
    public function setRelationships(
        string $name,
        array $related,
    ): void {
        $this->relationships[$name] = [
            'data' => array_map(
                static fn(ResourceRecord $record): array => $record->identifier(),
                array_values($related)
            )
        ];
    }

    /**
     * Сериализация одного ресурса в формате JSON:API
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        $resource = $this->identifier();

        // attributes необязателен по спецификации, добавляем только если они есть
        if (!empty($this->attributes)) {
            $resource['attributes'] = $this->attributes;
        }

        // Добавляем relationships, если они заданы
        if (!empty($this->relationships)) {
            $resource['relationships'] = $this->relationships;
        }

        return $resource;
    }
}
