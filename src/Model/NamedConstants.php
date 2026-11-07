<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Model;

/**
 * Проверка констант-имён: по строковой константе на каждое имя, значение — само имя.
 *
 * Общая для `Entity` (ключи `COLUMNS`) и описаний поверх сущностей (`Resource`: ключи
 * `ATTRIBUTES` и имена связей). Вызывается только из `validate()`, в тестах.
 */
trait NamedConstants
{
    /**
     * Строковые константы, объявленные в самом классе (кроме `$except`), должны ровно совпадать с именами:
     * константа без имени — опечатка или остаток, имя без константы — на него не сослаться.
     *
     * @param array<string, string> $names  имя → что это (`column`, `attribute`, `relationship`) для сообщений
     * @param list<string>          $except константы класса, которые не являются именами (`TABLE`, `TYPE`)
     * @param string                $source где объявлены имена, для сообщений
     *
     * @return list<string> ошибки
     */
    private static function namedConstantErrors(array $names, array $except, string $source): array
    {
        $errors = [];
        $named = [];
        foreach ((new \ReflectionClass(static::class))->getReflectionConstants() as $constant) {
            $value = $constant->getValue();
            if ($constant->getDeclaringClass()->getName() !== static::class
                || \in_array($constant->getName(), $except, true) || !\is_string($value)) {
                continue;
            }
            $named[$value] = $constant->getName();
            if (!isset($names[$value])) {
                $errors[] = "constant {$constant->getName()}: {$value} is not in {$source}";
            }
        }
        foreach ($names as $name => $kind) {
            if (!isset($named[$name])) {
                $errors[] = "{$kind} {$name}: no constant declares its name";
            }
        }

        return $errors;
    }
}
