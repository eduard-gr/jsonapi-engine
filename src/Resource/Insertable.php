<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Resource;

/**
 * Возможность ресурса: его можно создавать (`POST`). Маркер на классе ресурса (`CertificateResource implements
 * Insertable`); экземпляров у ресурсов нет, поэтому проверка — `is_a($class, Insertable::class, true)`.
 *
 * Без интерфейса ресурс создать нельзя — ни корнем документа, ни новым ребёнком по `lid`: `WriteDocumentParser`
 * отказывает (`InvalidDocumentException`, 403), `InsertPlanner`, `WritePlanner` и `InsertResourceWriter` бросают
 * `LogicException`. Сослаться на существующую запись `{type, id}` можно и без него (`provider` у сертификата). Читать
 * можно любой ресурс, отдельной возможности чтения нет. Рядом — `Updatable` и `Deletable`.
 *
 * Возможности описывает только ресурс: у сущностей таких флагов нет, запрет писать отдельную колонку — `mode` READ
 * атрибута. Интерфейс задаёт, что ресурс умеет в принципе; что разрешено конкретной точке входа или роли, будет решать
 * будущая схема ограничений (она только сужает).
 *
 * Все таблицы модели `Insertable`-ресурса пишутся (`InsertPlanner::tables()`): основная — всегда, присоединённая `LeftJoin`
 * — если в строке есть её колонки.
 */
interface Insertable
{
}
