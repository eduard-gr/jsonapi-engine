<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Select;

/**
 * ONE_TO_MANY внутри общей строки, ветка 1-1 ресурса: logs у delivery в строке сертификата.
 *
 * outer — поток ресурса внутри строки (ResourceNode delivery или его SharedRowJoinNode со своими 1-1):
 * по одной записи на строку, и читать его наперёд нельзя — строку двигает SharedScanNode, а HashNestJoinNode
 * прочитал бы весь outer до первого результата. Поэтому хешируется inner: при первой записи outer inner
 * читается целиком в таблицу «ключ → связанные», дальше каждая запись outer получает связь из таблицы
 * и сразу уходит наружу. Пустая запись outer (ресурса в строке нет, LEFT JOIN) проходит как есть —
 * на строку по-прежнему ровно один элемент.
 *
 * inner — отдельный запрос со своим владельцем SharedScanNode, как у HashNestJoinNode.
 *
 * Из дерева запроса напрямую не строится: SelectPlanner::fromTree() создаёт для ONE_TO_MANY
 * HashNestJoinNode, а SharedScanPass превращает его в HashJoinNode, если тот оказался внутри строки.
 *
 * Для остальных шагов:
 *  - HashToMergeRule его не трогает — правило ищет только HashNestJoinNode, а над строкой оно
 *    спускается по outer только до SharedScanNode и внутрь строки не заходит. Merge внутри строки
 *    (строка упорядочена по ключу ресурса 1-1) пока не делается;
 *  - BuildSqlPass кладёт в FROM строки его outer, а для inner строит отдельный запрос;
 *  - Executor\Select\IteratorBuilder собирает из него Executor\Select\HashJoinIterator.
 */
final class HashJoinNode extends JoinNode {}
