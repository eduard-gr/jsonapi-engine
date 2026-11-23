<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Plan\Select\Fixture;

use Ggs\JsonApiEngine\Operator;
use Ggs\JsonApiEngine\Resource;

/**
 * `note` над чужим источником `ForeignNotesSource`.
 */
final class ForeignNoteResource extends Resource
{
    public const string TYPE = 'note';
    public const string ID = 'id';
    public const string TEXT = 'text';

    public const array ATTRIBUTES = [
        self::ID => ['column' => ForeignNotesSource::ID, 'type' => self::INT, 'constraint' => self::PRIMARY],
        self::TEXT => ['column' => ForeignNotesSource::TEXT, 'type' => self::STRING, 'filter' => [Operator::Eq], 'sort' => true],
    ];

    public static function getModel(): string
    {
        return ForeignNotesSource::class;
    }
}
