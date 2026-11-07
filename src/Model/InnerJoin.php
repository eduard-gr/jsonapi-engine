<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Model;

use sad_spirit\pg_builder\enums\JoinType;

/**
 * `INNER JOIN`: строки левого поддерева без пары отсекаются, поэтому соединение подключается всегда.
 */
final readonly class InnerJoin extends Join
{
    #[\Override]
    public function type(): JoinType
    {
        return JoinType::INNER;
    }
}
