<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor\Write;

use Ggs\JsonApiEngine\Parser\Write\PersistentIdentity;
use Ggs\JsonApiEngine\Resource;
use LogicException;

/**
 * Данные операции → строка ресурса «колонка модели → значение»: общий шаг границы уровней у вставки
 * (`InsertResourceWriter`) и изменения (`UpdateResourceWriter`). Зеркало `SerializeIterator`: тот собирает ресурс из
 * строки, этот — строку из ресурса.
 *
 * Поток: присланные атрибуты (по `ATTRIBUTES[...]['column']`) и связи по внешнему ключу (`provider` → `provider_id`:
 * id цели, `null` → NULL) → строка. В строке только присланное: присутствие ключа, а не значение, решает, что колонку
 * трогать.
 *
 * Составной атрибут требует статического `deserialize<Attribute>($value): array<column, value>` у ресурса (обратного
 * `serialize<Attribute>`), иначе `LogicException`. Вход проверен парсером; неизвестный атрибут или связь не по
 * внешнему ключу — `LogicException`.
 */
final class ResourceRow
{
    /**
     * @param class-string<Resource>                 $resource
     * @param array<string, mixed>                   $attributes присланные атрибуты: имя в API → значение
     * @param array<string, PersistentIdentity|null> $references связи по внешнему ключу: имя → цель
     * @return array<string, mixed> колонка модели → значение
     */
    public static function columns(string $resource, array $attributes, array $references): array
    {
        $row = [];
        foreach ($attributes as $name => $value) {
            /** @var array<string, mixed> $attribute */
            $attribute = $resource::ATTRIBUTES[$name]
                ?? throw new LogicException("{$resource}: unknown attribute {$name}");
            if (isset($attribute['columns'])) {
                $method = 'deserialize' . str_replace('_', '', ucwords($name, '_'));
                if (!method_exists($resource, $method)) {
                    throw new LogicException("{$resource}: composite attribute {$name} needs {$method}()");
                }
                /** @var array<string, mixed> $columns */
                $columns = $resource::$method($value);
                $row = [...$row, ...$columns];
                continue;
            }
            $row[(string) ($attribute['column'] ?? $name)] = $value;
        }
        foreach ($references as $name => $target) {
            $foreign = $resource::foreignRelationship($name)
                ?? throw new LogicException("{$resource}: {$name} is not a foreign-key relationship");
            $row[(string) $foreign->relation[0]] = $target?->id;
        }

        return $row;
    }
}
