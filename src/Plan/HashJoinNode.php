<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan;

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
 * Из дерева запроса напрямую не строится: Select\QueryPlanner::fromTree() создаёт для ONE_TO_MANY
 * HashNestJoinNode, а Select\SharedScanPass превращает его в HashJoinNode, если тот оказался внутри строки.
 *
 * Для остальных шагов:
 *  - Select\HashToMergeRule его не трогает — правило ищет только HashNestJoinNode, а над строкой оно
 *    спускается по outer только до SharedScanNode и внутрь строки не заходит. Merge внутри строки
 *    (строка упорядочена по ключу ресурса 1-1) пока не делается;
 *  - Select\BuildSqlPass кладёт в FROM строки его outer, а для inner строит отдельный запрос;
 *  - Executor\ExecutorBuilder собирает из него Executor\HashJoinIterator.
 */
final class HashJoinNode extends JoinNode {}
