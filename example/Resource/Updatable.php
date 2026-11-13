<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Example\Resource;

/**
 * Возможность ресурса: его можно изменять (`PATCH`). Пара к `Resource\Insertable`; пока прототип — читает только
 * example/patch-document.php (`WriteDocumentParser`), при переносе записи в `src/` переезжает в `src/Resource/`.
 *
 * Маркер на классе ресурса, проверка — `is_a($class, Updatable::class, true)`. Нужен для узла дерева изменений с
 * операцией `update`: корень PATCH (даже если меняются только его связи) и ребёнок `{id}` + ресурс в `included`.
 * Изменение связи по внешнему ключу и связи многие-ко-многим — изменение владельца: нужен `Updatable` владельца, цель
 * не трогается. `{id}` без `included` (ребёнок только назван) ничего не меняет и маркера не требует. Без маркера —
 * 403 с указателем на ресурс.
 *
 * Как у `Insertable`: интерфейс задаёт, что ресурс умеет в принципе; права пользователя и ограничения точки входа —
 * не его дело.
 *
 * @temporary Временное место: интерфейс лежит в example/Resource/, потому что его реализуют ресурсы примера и ему
 *            нужна автозагрузка, а `src/` прототип не меняет. При переносе записи —
 *            `src/Resource/Updatable.php`, рядом с `Insertable` (`Ggs\JsonApiEngine\Resource\Updatable`).
 */
interface Updatable
{
}
