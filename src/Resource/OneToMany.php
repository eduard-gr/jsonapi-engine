<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Resource;

/**
 * Связь «один ко многим»: у строки владельца любое число строк ресурса (в том числе ни одной), у строки ресурса —
 * не больше одного владельца.
 *
 * Ключ лежит на стороне ресурса: `relation` — `[колонка владельца, внешний ключ ресурса на владельца]`, обычно
 * `[первичный ключ владельца, внешний ключ ресурса]`. Колонка ресурса не уникальна.
 * Примеры: `certificate.domains` — `[id, certificate_id]`, `certificates.id = domains.certificate_id`;
 * `domain.logs` — `[id, domain_id]`, `domains.id = domain_logs.domain_id`.
 *
 * `JOIN` в запросе владельца размножил бы его строки, поэтому ресурс читается отдельным SQL-запросом, а строки
 * ресурса привязываются к владельцам по ключу (пустой список — `data: []`) узлом соединения:
 *  - `HashNestJoinIterator` — порядок потоков не важен; блокирующий: сначала строит таблицу владельцев;
 *  - `MergeNestJoinIterator` — оба потока отсортированы по ключу, потоковый.
 * В примере `certificate.domains` — `HashNestJoinIterator` (`QUERY2`), `domain.logs` — `MergeNestJoinIterator`
 * (`QUERY2` и `QUERY3` отсортированы по id домена) (`example/document.php`).
 */
final readonly class OneToMany extends Relationship
{
}
