<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Resource;

/**
 * Связь «один к одному»: у строки владельца не больше одной строки ресурса, и наоборот.
 *
 * Ключ лежит на стороне ресурса: `relation` — `[колонка владельца, первичный ключ или уникальная колонка ресурса]`,
 * обычно `[первичный ключ владельца, внешний ключ ресурса на владельца]`. Уникальность колонки ресурса и даёт
 * «не больше одной строки» (проверяет `Resource::validate()`). Колонка ресурса может быть его первичным ключом
 * и одновременно внешним ключом на владельца (`certificate_details.certificate_id`).
 *
 * Примеры: `certificate.detail` — `[id, certificate_id]`, `certificates.id = certificate_details.certificate_id`;
 * `certificate.delivery` — `[id, certificate_id]`, `certificates.id = certificate_delivery_meta.certificate_id`
 * (доставки может не быть: связь пустая, `data: null`).
 *
 * Строки владельца связь не размножает, поэтому ресурс читается тем же SQL-запросом, что и владелец (`JOIN`;
 * `LEFT JOIN`, если ресурса может не быть). Одна строка запроса — несколько ресурсов:
 *  - `SharedDataIterator` даёт каждому `SerializeIterator` (владельца и ресурса) окно на текущую строку;
 *  - `SharedRowJoinIterator` попарно соединяет поток владельца с потоком ресурса и пишет связь (ресурса нет — `null`);
 *  - `SharedScanIterator` один раз на строку продвигает общий источник.
 * В примере `certificate` + `detail` + `delivery` из одной строки `QUERY1` (`example/document.php`).
 */
final readonly class OneToOne extends Relationship
{

}
