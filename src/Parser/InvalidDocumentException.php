<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Parser;

/**
 * Входной документ клиента (тело `POST`) не прошёл валидацию по ресурсу точки входа. Бросается только
 * `DocumentParser`; дальше дерево вставки считается корректным, и несоответствия — `LogicException`. Превращается
 * в документ ошибки JSON:API со статусом 400 и указателем на место ошибки.
 */
final class InvalidDocumentException extends \RuntimeException
{
    /**
     * @param string $pointer JSON Pointer на место ошибки в документе (`/data/relationships/domains/data/1`)
     */
    public function __construct(string $detail, public readonly string $pointer)
    {
        parent::__construct($detail);
    }

    /**
     * @return array{status: string, title: string, detail: string, source: array{pointer: string}}
     */
    public function toError(): array
    {
        return [
            'status' => '400',
            'title' => 'Invalid document',
            'detail' => $this->getMessage(),
            'source' => ['pointer' => $this->pointer],
        ];
    }
}
