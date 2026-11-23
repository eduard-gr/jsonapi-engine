<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Model\Fixture\Source;

/**
 * Модель из одного листа — чужого источника.
 */
final class RatingModel
{
    public static function getModel(): string
    {
        return RatingsSource::class;
    }
}
