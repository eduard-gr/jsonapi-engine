<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Select;

/**
 * ONE_TO_ONE: ресурс inner живёт в той же SQL-строке, что и outer.
 *
 * Единственное соединение, чей inner — часть той же строки. У остальных соединений (веток) inner —
 * отдельный запрос, а сама ветка может висеть как над строкой, так и внутри неё (ветка 1-1 ресурса).
 * В SQL строки это LEFT JOIN, в ON которого — только условие связи (relation).
 */
final class SharedRowJoinNode extends JoinNode {}
