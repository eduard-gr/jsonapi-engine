<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Write;

use Ggs\JsonApiEngine\Plan\Delete\DeleteEntityNode;
use Ggs\JsonApiEngine\Plan\Delete\DeleteForeignDataNode;
use Ggs\JsonApiEngine\Plan\Insert\InsertResourceNode;
use Ggs\JsonApiEngine\Plan\Update\UpdateResourceNode;
use Ggs\JsonApiEngine\Resource;

/**
 * Узел плана записи: место дерева операций — ресурс и ветви по операциям, которые встретились у ресурсов этого места.
 * Исполнитель — `Executor\Write\WriteOperationWriter`.
 *
 * Ветви нет (`null`) — такой операции в месте нет: `add` — вставка (`Plan\Insert\InsertResourceNode`) для
 * `InsertOperation`, `update` — изменение (`Plan\Update\UpdateResourceNode`) для `UpdateOperation`, `keep` —
 * поиск без изменения (`KeepEntityNode`) для `KeepOperation`, `remove` — удаление
 * (`Plan\Delete\DeleteEntityNode`) для `DeleteOperation`. Какую ветвь выполнять для конкретного элемента, решает
 * класс его операции при выполнении.
 *
 * Ветви `update`, `keep` и `remove` могут быть обёрнуты в `WriteLinkedKeyNode`: первичный ключ ресурса места —
 * колонка связи с родителем, и id операции сверяется с ключом родителя до запроса.
 *
 * У ресурса над чужим источником (`Model\ForeignDataSource`) ветви те же, узлы свои: под `add` и `update` —
 * `InsertForeignDataNode` и `UpdateForeignDataNode` (входы узлов ресурса), `keep` — `KeepForeignDataNode`, `remove` —
 * `Plan\Delete\DeleteForeignDataNode`. Вид источника решает `WritePlanner`, сборщик только отображает узлы.
 *
 * Неизменяемый, без состояния.
 */
final readonly class WriteOperationNode
{
    /**
     * @param class-string<Resource> $resource
     */
    public function __construct(
        public string $resource,
        public ?InsertResourceNode $add,
        public UpdateResourceNode|WriteLinkedKeyNode|null $update,
        public KeepEntityNode|KeepForeignDataNode|WriteLinkedKeyNode|null $keep,
        public DeleteEntityNode|DeleteForeignDataNode|WriteLinkedKeyNode|null $remove,
    ) {}
}
