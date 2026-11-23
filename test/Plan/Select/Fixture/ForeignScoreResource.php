<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Plan\Select\Fixture;

use Ggs\JsonApiEngine\Resource;

/**
 * `score` над чужим источником `ForeignScoresSource`, id — id сертификата.
 */
final class ForeignScoreResource extends Resource
{
    public const string TYPE = 'score';
    public const string ID = 'id';
    public const string SCORE = 'score';

    public const array ATTRIBUTES = [
        self::ID => ['column' => ForeignScoresSource::CERTIFICATE_ID, 'type' => self::INT, 'constraint' => self::PRIMARY],
        self::SCORE => ['column' => ForeignScoresSource::SCORE, 'type' => self::INT],
    ];

    public static function getModel(): string
    {
        return ForeignScoresSource::class;
    }
}
