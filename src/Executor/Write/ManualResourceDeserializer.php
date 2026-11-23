<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor\Write;

use Ggs\JsonApiEngine\Model\ModelTree;
use Ggs\JsonApiEngine\Resource;
use Ggs\JsonApiEngine\Resource\DeserializeInterface;
use Ggs\JsonApiEngine\Resource\InvalidAttributeException;
use LogicException;

/**
 * Ручная десериализация: строку ресурса из данных операции собирает метод `deserialize()` самого ресурса. Наследник
 * `ResourceDeserializer`: тот раскладывает атрибуты по описанию (`ATTRIBUTES`), этот отдаёт данные ресурсу.
 * Зеркало `Executor\Select\ManualSerializeReader`.
 *
 * Поток: присланные атрибуты и связи по внешнему ключу + контекст операции (вставка или изменение, id ресурса,
 * колонки связи с родителем) → `deserialize($attributes, $references, $is_insert, $id, $link)` на экземпляре
 * ресурса → строка «колонка модели → значение».
 *
 * Когда ставится: ресурс реализует `Resource\DeserializeInterface`. `WriterBuilder` проверяет это по классу ресурса из
 * плана и даёт писателям ресурса этот класс вместо `ResourceDeserializer`; дерево, собранное руками, выбирает само.
 * Ресурс без интерфейса — LogicException в конструкторе.
 *
 * Общая раскладка для такого ресурса не выполняется вовсе: все присланные атрибуты и связи по внешнему ключу —
 * на методе, в том числе обычные. Так записывается составной атрибут (`columns`).
 *
 * Проверка результата: каждая колонка строки должна быть колонкой модели ресурса, иначе LogicException — писатели
 * таблиц берут из строки только свои колонки, и лишняя пропала бы молча.
 *
 * Ошибка клиента: значение, которое ресурс не смог разобрать, он отклоняет `Resource\InvalidAttributeException` —
 * оно проходит наверх как есть, `WriteOperationWriter` превращает его в 400. Любое другое исключение метода — 500.
 *
 * Тихий отказ: метод, который не положил в строку присланный атрибут, молча его теряет; метод, который положил
 * колонку, которой в документе не было, перезапишет её. Узел этого не видит.
 */
final readonly class ManualResourceDeserializer extends ResourceDeserializer
{
    private DeserializeInterface $manual;

    /**
     * @throws LogicException если ресурс не реализует `DeserializeInterface`
     */
    public function __construct(Resource $resource)
    {
        if (!$resource instanceof DeserializeInterface) {
            throw new LogicException(
                $resource::class . ' does not implement ' . DeserializeInterface::class . ': use ' . ResourceDeserializer::class,
            );
        }
        $this->manual = $resource;
        parent::__construct(resource: $resource);
    }

    /**
     * Запрет родителя на ресурс с `DeserializeInterface` здесь не действует: ресурс проверен в конструкторе.
     */
    #[\Override]
    protected function prepare(): void {}

    /**
     * @throws InvalidAttributeException если ресурс не принял присланное значение (наверху — 400)
     */
    #[\Override]
    public function getRow(array $attributes, array $references, bool $is_insert, ?int $id, array $link): array
    {
        $row = $this->manual->deserialize($attributes, $references, $is_insert, $id, $link);
        $class = $this->resource::class;
        $tree = ModelTree::of($class);
        foreach (array_keys($row) as $column) {
            if (!$tree->hasColumn((string) $column)) {
                throw new LogicException("{$class}: deserialize() returned {$column}, which is not a model column");
            }
        }

        return $row;
    }
}
