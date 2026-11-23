<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Model\Fixture\Source;

use Ggs\JsonApiEngine\Model\DataSource;
use Ggs\JsonApiEngine\Model\ForeignDataSource;

/**
 * Чужой источник: наследник `DataSource`, но не `DatabaseEntitySource` — только описание колонок.
 */
final class RatingsSource extends ForeignDataSource
{
    public const string CERTIFICATE_ID = 'certificate_id';
    public const string SCORE = 'score';

    public const array COLUMNS = [
        self::CERTIFICATE_ID => ['type' => self::INT, 'constraint' => self::PRIMARY],
        self::SCORE => ['type' => self::INT],
    ];
}
