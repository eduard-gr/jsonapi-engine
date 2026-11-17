<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor\Write;

/**
 * Ресурс, который запрос на запись изменяет, проверяет или удаляет, не найден при выполнении: его нет, он принадлежит
 * другому родителю (колонка связи — условие поиска) или не найден родитель из вложенного маршрута. Бросают
 * `WriteOperationWriter` и `RouteParentWriter`. Превращается в ошибку JSON:API со статусом 404.
 *
 * Места в документе у ошибки нет: у операций указателя нет (а родителя маршрута нет в документе). Ресурс назван типом
 * и `id` в `meta` ошибки. Часть запроса к этому моменту могла выполниться (родитель пишется раньше детей) — откатить
 * её должна транзакция вызывающего.
 */
final class WriteNotFoundException extends \RuntimeException
{
    public function __construct(
        public readonly string $type,
        public readonly int $id,
        string $detail,
    ) {
        parent::__construct($detail);
    }

    /**
     * @return array{status: string, title: string, detail: string, meta: array{type: string, id: string}}
     */
    public function toError(): array
    {
        return [
            'status' => '404',
            'title' => 'Resource not found',
            'detail' => $this->getMessage(),
            'meta' => ['type' => $this->type, 'id' => (string) $this->id],
        ];
    }
}
