<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Resource;

/**
 * Значение атрибута, присланное клиентом, не подходит ресурсу: бросает метод `deserialize()` ресурса
 * (`DeserializeInterface`), когда не может разобрать значение (у составного `price` нет `amount`, значение не той
 * формы). Это ошибка клиента, а не описания: исключением ресурс отличает её от своей поломки.
 *
 * Ресурс называет только атрибут и причину: какой это ресурс документа, он не знает. `Executor\Write\
 * WriteOperationWriter` ловит исключение и бросает `Executor\Write\WriteInvalidAttributeException` с типом и именем
 * ресурса — оно превращается в ошибку JSON:API со статусом 400. Любое другое исключение из `deserialize()`
 * (в том числе ошибка PHP на значении неожиданной формы) до клиента дойдёт как 500.
 *
 * Форму значения парсер не проверяет (только то, что атрибут известен, не только для чтения и не `null` без
 * `nullable`), поэтому проверять её — дело метода.
 */
final class InvalidAttributeException extends \RuntimeException
{
    /**
     * @param string $attribute имя атрибута в API
     * @param string $detail    что не так со значением — текст для клиента
     */
    public function __construct(
        public readonly string $attribute,
        string $detail,
    ) {
        parent::__construct($detail);
    }
}
