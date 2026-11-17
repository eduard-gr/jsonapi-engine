<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Parser;

/**
 * Запрос на запись (`POST`, `PATCH`, `DELETE`) не прошёл разбор по ресурсу точки входа. Бросается только
 * `Parser\Write\WriteDocumentParser`, до первого запроса в базу; дальше дерево операций считается корректным, и
 * несоответствия — `LogicException`. Превращается в ошибку JSON:API с указателем на место в документе.
 *
 * Статусы: 400 — форма документа (неизвестный атрибут или связь, `null` у атрибута без `nullable`, лишний или дважды
 * использованный ресурс `included`…); 403 — у ресурса нет возможности для запрошенной операции (`Insertable`,
 * `Updatable`, `Deletable`) или прислан клиентский `id`; 409 — `type` или `id` корня не совпали с маршрутом.
 *
 * Ошибки выполнения (ресурса нет, ребёнок чужой) — `Executor\Write\WriteNotFoundException`: у операций указателя нет.
 */
final class InvalidDocumentException extends \RuntimeException
{
    /**
     * @param string|null $pointer JSON Pointer на место ошибки в документе (`/data/relationships/domains/data/1`);
     *                             null — документа нет (`DELETE`)
     * @param string      $status  `400`, `403` или `409`
     */
    public function __construct(
        string $detail,
        public readonly ?string $pointer,
        public readonly string $status = '400',
    ) {
        parent::__construct($detail);
    }

    /**
     * @return array{status: string, title: string, detail: string, source?: array{pointer: string}}
     */
    public function toError(): array
    {
        $title = 'Invalid document';
        if ($this->status === '403') {
            $title = 'Forbidden';
        }
        if ($this->status === '409') {
            $title = 'Conflict';
        }
        $error = ['status' => $this->status, 'title' => $title, 'detail' => $this->getMessage()];
        if ($this->pointer !== null) {
            $error['source'] = ['pointer' => $this->pointer];
        }

        return $error;
    }
}
