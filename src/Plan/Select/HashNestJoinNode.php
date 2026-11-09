<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Select;

/**
 * ONE_TO_MANY, ветка над строкой: связанные inner вкладываются в записи outer через хеш-таблицу
 * по outer, порядок сторон не важен.
 *
 * Блокирующий: outer читается целиком до первого результата. Поэтому внутри строки стоять не может —
 * там SharedScanPass превращает его в HashJoinNode. Если обе стороны можно упорядочить по ключам,
 * HashToMergeRule превращает его в MergeNestJoinNode.
 */
final class HashNestJoinNode extends JoinNode {}
