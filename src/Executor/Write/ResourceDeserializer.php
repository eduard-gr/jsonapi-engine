<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor\Write;

use Ggs\JsonApiEngine\Parser\Write\PersistentIdentity;
use Ggs\JsonApiEngine\Resource;
use Ggs\JsonApiEngine\Resource\DeserializeInterface;
use LogicException;

/**
 * Данные операции → строка ресурса «колонка модели → значение» по описанию ресурса: общий шаг границы уровней
 * у вставки (`InsertResourceWriter`) и изменения (`UpdateResourceWriter`). Зеркало `SerializeReader`: тот собирает
 * ресурс из строки (сериализация), этот — строку из ресурса (десериализация).
 *
 * Документ JSON:API класс не разбирает: это делает `Parser\Write\WriteDocumentParser`, а сюда приходят уже данные
 * операции (`attributes`, `references`). Десериализация здесь — вторая половина пути «документ → строка»: имена
 * API → колонки модели.
 *
 * Поток: присланные атрибуты (по `ATTRIBUTES[...]['column']`) и связи по внешнему ключу (`provider` → `provider_id`:
 * id цели, `null` → NULL) → строка. В строке только присланное: присутствие ключа, а не значение, решает, что колонку
 * трогать. Значение атрибута идёт в его колонку как есть. Остальные аргументы `getRow()` (вставка или изменение,
 * id, колонки связи с родителем) — контекст для ручной десериализации; раскладке по описанию они не нужны.
 *
 * Объект создаётся на ресурс: его строит `WriterBuilder` и отдаёт писателям ресурса. С ресурса читается только
 * описание, методов экземпляра этот класс не вызывает.
 *
 * Ручная десериализация — наследник `ManualResourceDeserializer`: ресурс, реализующий `Resource\DeserializeInterface`,
 * строит строку сам. Какой из двух классов дать писателю, решает тот, кто строит дерево (`WriterBuilder` — по классу
 * ресурса из плана). Сам этот класс метод не вызывает и такой ресурс не принимает: получив его в дереве, собранном
 * руками, он бросает `LogicException` в конструкторе — разложить по описанию ресурс, который строит строку сам,
 * значило бы молча записать не то.
 *
 * Составной атрибут (`columns`) по описанию не разложить — `LogicException`: такому ресурсу нужен
 * `DeserializeInterface` (или `mode` READ у атрибута, тогда его отклонит парсер). Вход проверен парсером; неизвестный
 * атрибут или связь не по внешнему ключу — `LogicException`.
 */
readonly class ResourceDeserializer
{
    /**
     * @throws LogicException если ресурс не подходит этому классу (см. `prepare()`)
     */
    public function __construct(
        protected Resource $resource,
    ) {
        $this->prepare();
    }

    /**
     * Проверка ресурса при создании; наследник заменяет её своей.
     *
     * @throws LogicException если ресурс реализует `DeserializeInterface`: его строку строит
     *                        `ManualResourceDeserializer`
     */
    protected function prepare(): void
    {
        if ($this->resource instanceof DeserializeInterface) {
            throw new LogicException(
                $this->resource::class . ' implements ' . DeserializeInterface::class . ': use '
                . ManualResourceDeserializer::class,
            );
        }
    }

    /**
     * @param array<string, mixed>                   $attributes присланные атрибуты: имя в API → значение
     * @param array<string, PersistentIdentity|null> $references связи по внешнему ключу: имя → цель
     * @param bool                                   $is_insert  ресурс создаётся (true) или изменяется (false)
     * @param int|null                               $id         id изменяемого ресурса; null при вставке
     * @param array<string, mixed>                   $link       колонки связи с родителем → значение
     * @return array<string, mixed> колонка модели → значение
     * @throws LogicException если атрибут неизвестен или составной, связь — не по внешнему ключу
     */
    public function getRow(array $attributes, array $references, bool $is_insert, ?int $id, array $link): array
    {
        $resource = $this->resource;
        $class = $resource::class;
        $row = [];
        foreach ($attributes as $name => $value) {
            /** @var array<string, mixed> $attribute */
            $attribute = $resource::ATTRIBUTES[$name]
                ?? throw new LogicException("{$class}: unknown attribute {$name}");
            if (isset($attribute['columns'])) {
                throw new LogicException(
                    "{$class}: composite attribute {$name} can not be written without " . DeserializeInterface::class,
                );
            }
            $row[(string) ($attribute['column'] ?? $name)] = $value;
        }
        foreach ($references as $name => $target) {
            $foreign = $resource::getForeignRelationship($name)
                ?? throw new LogicException("{$class}: {$name} is not a foreign-key relationship");
            $row[(string) $foreign->relation[0]] = $target?->id;
        }

        return $row;
    }
}
