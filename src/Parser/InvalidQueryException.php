<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Parser;

/**
 * Запрос клиента не прошёл валидацию по ресурсу endpoint'а. Превращается в документ ошибки JSON:API со статусом 400.
 */
final class InvalidQueryException extends \RuntimeException
{
    /**
     * @param string $parameter параметр запроса, в котором ошибка: `include`, `fields[domains]`, `filter[status]`
     */
    public function __construct(string $detail, public readonly string $parameter)
    {
        parent::__construct($detail);
    }

    /**
     * @return array{status: string, title: string, detail: string, source: array{parameter: string}}
     */
    public function toError(): array
    {
        return [
            'status' => '400',
            'title' => 'Invalid query parameter',
            'detail' => $this->getMessage(),
            'source' => ['parameter' => $this->parameter],
        ];
    }
}
