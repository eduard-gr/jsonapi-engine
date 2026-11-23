<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor\Write;

use Ggs\JsonApiEngine\Resource\InvalidAttributeException;

/**
 * Значение атрибута не принял сам ресурс при выполнении записи: его `deserialize()` (`Resource\DeserializeInterface`)
 * бросил `Resource\InvalidAttributeException`. Бросает `WriteOperationWriter`, добавляя то, чего ресурс не знает, —
 * какой это ресурс документа. Превращается в ошибку JSON:API со статусом 400. Пара к `WriteNotFoundException` (404).
 *
 * Места в документе у ошибки нет: у операций указателя нет. Ресурс назван в `meta` ошибки типом и своим именем
 * (`id` существующего, `lid` нового; у корня POST без `lid` имени нет), атрибут — там же.
 *
 * Ошибка возникает при выполнении, а не при разборе: парсер форму значения не проверяет, а объекта ресурса у него
 * нет. Часть запроса к этому моменту могла выполниться (родитель пишется раньше детей) — откатить её должна
 * транзакция вызывающего.
 */
final class WriteInvalidAttributeException extends \RuntimeException
{
    /**
     * @param string                $type      JSON:API type ресурса
     * @param array<string, string> $identity  имя ресурса для `meta`: `['id' => …]`, `['lid' => …]` или пусто
     * @param string                $attribute имя атрибута в API
     */
    public function __construct(
        public readonly string $type,
        public readonly array $identity,
        public readonly string $attribute,
        string $detail,
        InvalidAttributeException $previous,
    ) {
        parent::__construct($detail, 0, $previous);
    }

    /**
     * @return array{status: string, title: string, detail: string, meta: array<string, string>}
     */
    public function toError(): array
    {
        return [
            'status' => '400',
            'title' => 'Invalid attribute',
            'detail' => $this->getMessage(),
            'meta' => ['type' => $this->type, ...$this->identity, 'attribute' => $this->attribute],
        ];
    }
}
