<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Resource;

/**
 * Возможность ресурса: его можно изменять (`PATCH`). Пара к `InsertableInterface` и `DeletableInterface`; проверяет
 * `Parser\Write\WriteDocumentParser` до первого запроса в базу.
 *
 * Маркер на классе ресурса, проверка — `is_a($class, UpdatableInterface::class, true)`. Нужен ресурсу с операцией изменения
 * (`UpdateOperation`): корень PATCH (даже если меняются только его связи) и ребёнок `{id}` + ресурс в `included`.
 * Изменение связи по внешнему ключу — изменение владельца: нужен `UpdatableInterface` владельца, цель не трогается. `{id}` без
 * `included` (ребёнок только назван, `KeepOperation`) ничего не меняет и маркера не требует. Без маркера — 403 с
 * указателем на ресурс.
 *
 * Как у `InsertableInterface`: интерфейс задаёт, что ресурс умеет в принципе; права пользователя и ограничения точки входа —
 * не его дело.
 */
interface UpdatableInterface
{
}
