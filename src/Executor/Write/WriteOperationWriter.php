<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor\Write;

use Ggs\JsonApiEngine\Executor\PushExecutorInterface;
use Ggs\JsonApiEngine\Parser\Write\DeleteOperation;
use Ggs\JsonApiEngine\Parser\Write\InsertOperation;
use Ggs\JsonApiEngine\Parser\Write\KeepOperation;
use Ggs\JsonApiEngine\Parser\Write\UpdateOperation;
use Ggs\JsonApiEngine\Parser\Write\WriteOperation;
use Ggs\JsonApiEngine\Resource;
use Ggs\JsonApiEngine\Resource\InvalidAttributeException;
use LogicException;

/**
 * Развилка места по классу операции: `InsertOperation` → вставка (ветвь `add`), `UpdateOperation` → изменение
 * (ветвь `update`), `KeepOperation` → поиск без изменения (ветвь `keep`), `DeleteOperation` → удаление (ветвь
 * `remove`). Строит `WriterBuilder` по
 * `WriteOperationNode`; ветви есть только для операций, которые встретились в месте (`WritePlanner`), операция без
 * ветви — LogicException.
 *
 * Поток: операция + `$link` → исполнитель ветви → ключи наверх (их берёт `WriteJoinWriter` для детей). Пустые ключи у
 * изменения, поиска и удаления — ресурса нет или он принадлежит другому родителю: `WriteNotFoundException` (404) с
 * типом и `id` ресурса.
 *
 * Ошибка значения: `deserialize()` ресурса (`Resource\DeserializeInterface`) бросил
 * `Resource\InvalidAttributeException` — развилка превращает его в `WriteInvalidAttributeException` (400) с типом и
 * именем ресурса (`id` или `lid`): ресурс знает только атрибут, а место — единственное, где известна и операция,
 * и то, что ветвь пишет именно её ресурс (детей пишет `WriteJoinWriter` выше).
 */
final readonly class WriteOperationWriter implements PushExecutorInterface
{
    /**
     * @param class-string<Resource> $resource
     */
    public function __construct(
        private string $resource,
        private ?PushExecutorInterface $add,
        private ?PushExecutorInterface $update,
        private ?PushExecutorInterface $keep,
        private ?PushExecutorInterface $remove,
    ) {}

    #[\Override]
    public function push(WriteOperation $operation, array $link = []): array
    {
        try {
            return $this->getKeys(operation: $operation, link: $link);
        } catch (InvalidAttributeException $exception) {
            // Значение не принял deserialize() ресурса: ресурс знает только атрибут, какой это ресурс документа —
            // известно здесь
            // Строку из операции строят только вставка и изменение
            $identity = [];
            if ($operation instanceof InsertOperation || $operation instanceof UpdateOperation) {
                $identity = $operation->identity?->toMeta() ?? [];
            }

            throw new WriteInvalidAttributeException(
                type: $operation->resource::getType(),
                identity: $identity,
                attribute: $exception->attribute,
                detail: $exception->getMessage(),
                previous: $exception,
            );
        }
    }

    /**
     * Ключи от ветви операции; пусто у изменения, поиска и удаления — 404.
     *
     * @param array<string, mixed> $link
     * @return array<string, mixed>
     * @throws WriteNotFoundException
     */
    private function getKeys(WriteOperation $operation, array $link): array
    {
        if ($operation instanceof InsertOperation) {
            $branch = $this->add ?? throw new LogicException("{$this->resource}: no add branch in the plan");

            return $branch->push($operation, $link);
        }
        if ($operation instanceof UpdateOperation) {
            $branch = $this->update ?? throw new LogicException("{$this->resource}: no update branch in the plan");
        } elseif ($operation instanceof KeepOperation) {
            $branch = $this->keep ?? throw new LogicException("{$this->resource}: no keep branch in the plan");
        } elseif ($operation instanceof DeleteOperation) {
            $branch = $this->remove ?? throw new LogicException("{$this->resource}: no remove branch in the plan");
        } else {
            throw new LogicException('Unknown operation ' . $operation::class);
        }

        $keys = $branch->push($operation, $link);
        if ($keys === []) {
            $type = $operation->resource::getType();
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
                'keep' => $this->keep?->explain(),
                'remove' => $this->remove?->explain(),
            ]),
        ];
    }
}
