<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor\Write;

use Ggs\JsonApiEngine\Executor\PushExecutor;
use Ggs\JsonApiEngine\Parser\Write\DeleteOperation;
use Ggs\JsonApiEngine\Parser\Write\InsertOperation;
use Ggs\JsonApiEngine\Parser\Write\KeepOperation;
use Ggs\JsonApiEngine\Parser\Write\UpdateOperation;
use Ggs\JsonApiEngine\Parser\Write\WriteOperation;
use Ggs\JsonApiEngine\Resource;
use LogicException;

/**
 * Развилка места по классу операции: `InsertOperation` → вставка (ветвь `add`), `UpdateOperation`/`KeepOperation` →
 * изменение или проверка (ветвь `update`), `DeleteOperation` → удаление (ветвь `remove`). Строит `WriterBuilder` по
 * `WriteOperationNode`; ветви есть только для операций, которые встретились в месте (`WritePlanner`), операция без
 * ветви — LogicException.
 *
 * Поток: операция + `$link` → исполнитель ветви → ключи наверх (их берёт `WriteJoinWriter` для детей). Пустые ключи у
 * изменения, проверки и удаления — ресурса нет или он принадлежит другому родителю: `WriteNotFoundException` (404) с
 * типом и `id` ресурса.
 */
final readonly class WriteOperationWriter implements PushExecutor
{
    /**
     * @param class-string<Resource> $resource
     */
    public function __construct(
        private string $resource,
        private ?PushExecutor $add,
        private ?PushExecutor $update,
        private ?PushExecutor $remove,
    ) {}

    #[\Override]
    public function push(WriteOperation $operation, array $link = []): array
    {
        if ($operation instanceof InsertOperation) {
            $branch = $this->add ?? throw new LogicException("{$this->resource}: no add branch in the plan");

            return $branch->push($operation, $link);
        }
        if ($operation instanceof UpdateOperation || $operation instanceof KeepOperation) {
            $branch = $this->update ?? throw new LogicException("{$this->resource}: no update branch in the plan");
        } elseif ($operation instanceof DeleteOperation) {
            $branch = $this->remove ?? throw new LogicException("{$this->resource}: no remove branch in the plan");
        } else {
            throw new LogicException('Unknown operation ' . $operation::class);
        }

        $keys = $branch->push($operation, $link);
        if ($keys === []) {
            $type = $operation->resource::type();
            throw new WriteNotFoundException(
                type: $type,
                id: $operation->identity->id,
                detail: "{$type} {$operation->identity->id} not found"
                    . ($link === [] ? '' : ' under ' . (string) json_encode($link)),
            );
        }

        return $keys;
    }

    #[\Override]
    public function explain(): array
    {
        return [
            'node' => static::class,
            'resource' => $this->resource,
            ...array_filter([
                'add' => $this->add?->explain(),
                'update' => $this->update?->explain(),
                'remove' => $this->remove?->explain(),
            ]),
        ];
    }
}
