<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Plan\Select\Fixture;

/**
 * Заметки сертификатов (1:N): строки идут по `certificate_id` — это ориентация чтения, поэтому соединение с
 * сертификатами может быть слиянием. Заметка 5 — у сертификата, которого нет.
 */
final class ForeignNotesSource extends ForeignRowsSource
{
    public const string ID = 'id';
    public const string CERTIFICATE_ID = 'certificate_id';
    public const string TEXT = 'text';

    public const array COLUMNS = [
        self::ID => ['type' => self::INT, 'constraint' => self::PRIMARY],
        self::CERTIFICATE_ID => ['type' => self::INT],
        self::TEXT => ['type' => self::STRING],
    ];

    #[\Override]
    public static function getReadOrientation(): array
    {
        return [self::CERTIFICATE_ID];
    }

    #[\Override]
    protected function getRows(): array
    {
        return [
            [self::ID => 1, self::CERTIFICATE_ID => 1, self::TEXT => 'first'],
            [self::ID => 2, self::CERTIFICATE_ID => 1, self::TEXT => 'second'],
            [self::ID => 3, self::CERTIFICATE_ID => 2, self::TEXT => 'third'],
            [self::ID => 5, self::CERTIFICATE_ID => 999999, self::TEXT => 'orphan'],
        ];
    }
}
