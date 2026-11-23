<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Plan\Select\Fixture;

/**
 * Оценка сертификата (обратная 1:1): первичный ключ — `certificate_id`. Ориентации чтения нет.
 */
final class ForeignScoresSource extends ForeignRowsSource
{
    public const string CERTIFICATE_ID = 'certificate_id';
    public const string SCORE = 'score';

    public const array COLUMNS = [
        self::CERTIFICATE_ID => ['type' => self::INT, 'constraint' => self::PRIMARY],
        self::SCORE => ['type' => self::INT],
    ];

    #[\Override]
    public static function getReadOrientation(): ?array
    {
        return null;
    }

    #[\Override]
    protected function getRows(): array
    {
        return [
            [self::CERTIFICATE_ID => 2, self::SCORE => 20],
            [self::CERTIFICATE_ID => 1, self::SCORE => 10],
        ];
    }
}
