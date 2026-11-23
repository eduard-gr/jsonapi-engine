<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor\Write;

use Ggs\JsonApiEngine\Executor\PushExecutorInterface;
use Ggs\JsonApiEngine\Parser\Write\DeleteOperation;
use Ggs\JsonApiEngine\Parser\Write\KeepOperation;
use Ggs\JsonApiEngine\Parser\Write\UpdateOperation;
use Ggs\JsonApiEngine\Parser\Write\WriteOperation;
use LogicException;

/**
 * Сторож ветви места, у ресурса которого первичный ключ — сама колонка связи с родителем
 * (`delivery.certificate_id`). Строит `WriterBuilder` по `WriteLinkedKeyNode`; стоит между развилкой места
 * (`WriteOperationWriter`) и исполнителем ветви (`UpdateResourceWriter`, `KeepEntityWriter`,
 * `DeleteEntityWriter`).
 *
 * Поток: операция с id (`UpdateOperation`, `KeepOperation`, `DeleteOperation`) + `$link` → id не равен ключу
 * родителя в `$link[key]` — пусто без запроса (развилка превращает это в 404); равен — `input->push(операция,
 * link)` → его ключи.
 *
 * Зачем: исполнитель изменения ищет строку по своему ключу, подставляя id операции на место колонки связи, — без
 * этой проверки значение от родителя потерялось бы, и чужой ресурс с таким id был бы изменён.
 *
 * Операция без id (`InsertOperation`) или `$link` без колонки `key` — LogicException: сторож стоит не на своём
 * месте.
 */
final readonly class WriteLinkedKeyWriter implements PushExecutorInterface
{
    /**
     * @param string $key колонка связи с родителем, она же первичный ключ ресурса
     */
    public function __construct(
        private string $key,
        private PushExecutorInterface $input,
    ) {}

    #[\Override]
    public function push(WriteOperation $operation, array $link = []): array
    {
        if (!$operation instanceof UpdateOperation && !$operation instanceof KeepOperation
                && !$operation instanceof DeleteOperation) {
            throw new LogicException("Linked key {$this->key} needs an operation with id, got " . $operation::class);
        }
        if (!isset($link[$this->key])) {
            throw new LogicException("No link column {$this->key} for the linked key check");
        }
        if ((int) $link[$this->key] !== $operation->identity->id) {
            return [];
        }

        return $this->input->push($operation, $link);
    }

    #[\Override]
    public function explain(): array
    {
        return ['node' => static::class, 'key' => $this->key, 'input' => $this->input->explain()];
    }
}
