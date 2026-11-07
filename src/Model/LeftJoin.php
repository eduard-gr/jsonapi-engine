<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Model;

use sad_spirit\pg_builder\enums\JoinType;

/**
 * `LEFT JOIN`: строки левого поддерева остаются без пары. Правое поддерево подключается, только если нужна его
 * колонка.
 */
final readonly class LeftJoin extends Join
{
    #[\Override]
    public function type(): JoinType
    {
        return JoinType::LEFT;
    }
}
