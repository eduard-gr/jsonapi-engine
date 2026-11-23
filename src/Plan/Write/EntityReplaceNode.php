<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Write;

use Ggs\JsonApiEngine\Resource;

/**
 * Узел плана записи: полная замена связи по владению `name` — удаление детей владельца, не названных в документе.
 * Исполнитель — `Executor\Write\EntityReplaceWriter`. Один вход — владелец (`input`); стоит под `WriteJoinNode` той
 * же связи как его `parent`, а при замене пустым списком (`[]`, `null` у to-one) — без узла связи над собой.
 *
 * Узел есть, если связь заменяет хотя бы один владелец этого места; какой именно — говорит
 * `RelationshipOperation::$replace` в дереве операций (как класс операции для ветвей `WriteOperationNode`). У POST
 * его не бывает.
 *
 * Неизменяемый, без состояния.
 */
final readonly class EntityReplaceNode
{
    /**
     * @param array{0: string, 1: string} $relation       [колонка владельца, колонка ребёнка]
     * @param class-string<Resource>      $child_resource
     */
    public function __construct(
        public string $name,
        public array $relation,
        public string $child_resource,
        public WriteJoinNode|EntityReplaceNode|WriteOperationNode $input,
    ) {}
}
