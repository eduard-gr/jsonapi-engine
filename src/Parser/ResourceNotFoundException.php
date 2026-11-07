<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Parser;

/**
 * Ресурс, заданный параметрами маршрута, не найден. Превращается в документ ошибки JSON:API со статусом 404.
 */
final class ResourceNotFoundException extends \RuntimeException
{
    /**
     * @param string $parameter параметр маршрута: `id`, `provider_id`
     */
    public function __construct(
        public readonly string $type,
        public readonly string $parameter,
        public readonly string $value,
    ) {
        parent::__construct("Resource {$type} with {$parameter} {$value} not found");
    }

    /**
     * @return array{status: string, title: string, detail: string}
     */
    public function toError(): array
    {
        return [
            'status' => '404',
            'title' => 'Resource not found',
            'detail' => $this->getMessage(),
        ];
    }
}
