<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Write;

use Ggs\JsonApiEngine\Plan\Delete\DeleteEntityNode;
use Ggs\JsonApiEngine\Plan\Delete\DeleteForeignDataNode;
use Ggs\JsonApiEngine\Plan\Update\UpdateResourceNode;

/**
 * Узел плана записи: сторож ветви места, у ресурса которого первичный ключ — сама колонка связи с родителем
 * (`delivery.certificate_id`: доставка сертификата 1 имеет id 1). Исполнитель —
 * `Executor\Write\WriteLinkedKeyWriter`: id ресурса из документа обязан совпасть с ключом родителя, иначе ресурса
 * под этим родителем нет — без запроса в базу.
 *
 * Ставит `WritePlanner` над ветвями `update`, `keep` и `remove` места — тех операций, у которых есть id; над `add`
 * не ставит: у нового ресурса id ещё нет. Что первичный ключ и есть колонка связи, видно по описанию ресурса и связи,
 * поэтому это решает план, а не проверка в исполнителях на каждой операции.
 *
 * Неизменяемый, без состояния.
 */
final readonly class WriteLinkedKeyNode
{
    /**
     * @param string $key колонка связи с родителем, она же первичный ключ ресурса
     */
    public function __construct(
        public string $key,
        public UpdateResourceNode|KeepEntityNode|KeepForeignDataNode|DeleteEntityNode|DeleteForeignDataNode $input,
    ) {}
}
