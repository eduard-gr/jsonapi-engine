<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Plan\Write\Fixture;

use Ggs\JsonApiEngine\Resource;
use Ggs\JsonApiEngine\Resource\InsertableInterface;
use Ggs\JsonApiEngine\Test\Model\Fixture\Source\RatingsSource;

/**
 * Ошибка описания для ForeignDataWriteTest: ресурс объявлен `InsertableInterface`, а его чужой источник
 * (`RatingsSource`) вставку не реализует. Планировщик записи обязан отказать при построении плана.
 */
final class ReadOnlyScoreResource extends Resource implements InsertableInterface
{
    public const string TYPE = 'score';
    public const string ID = 'id';
    public const string SCORE = 'score';

    public const array ATTRIBUTES = [
        self::ID => ['column' => RatingsSource::CERTIFICATE_ID, 'type' => self::INT, 'constraint' => self::PRIMARY],
        self::SCORE => ['column' => RatingsSource::SCORE, 'type' => self::INT],
    ];

    public static function getModel(): string
    {
        return RatingsSource::class;
    }
}
