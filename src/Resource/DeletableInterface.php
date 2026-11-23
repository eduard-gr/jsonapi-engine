<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Resource;

/**
 * Возможность ресурса: его можно удалять. Пара к `InsertableInterface` и `UpdatableInterface`; проверяет
 * `Parser\Write\WriteDocumentParser` до первого запроса в базу.
 *
 * Маркер на классе ресурса, проверка — `is_a($class, DeletableInterface::class, true)`. В PATCH нужен цели связи по владению,
 * когда документ может удалить её ресурсы: `meta.op: remove` у элемента, полная замена to-many (связь без пометок:
 * неназванные удаляются), замена to-one через `lid` или `null` (текущий удаляется). Проверка — по форме документа, а не
 * по данным: полная замена требует маркера, даже если удалять в итоге нечего. Без маркера — 403 с указателем.
 *
 * Корень `DELETE /…/{id}` — тоже `DeletableInterface`. Потомков удаляет база (`ON DELETE CASCADE`: логи домена уходят с
 * доменом), их маркер не проверяется: `DeletableInterface` значит «можно удалить запросом», а что уходит вместе с ресурсом,
 * задаёт схема базы.
 */
interface DeletableInterface
{
}
