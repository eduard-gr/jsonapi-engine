<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Plan\Write\Fixture;

use Ggs\JsonApiEngine\Resource;
use Ggs\JsonApiEngine\Resource\DeletableInterface;
use Ggs\JsonApiEngine\Resource\InsertableInterface;
use Ggs\JsonApiEngine\Resource\UpdatableInterface;

/**
 * `note` над чужим источником `WritableNotesSource`: вставка, изменение и удаление.
 */
final class WritableNoteResource extends Resource implements InsertableInterface, UpdatableInterface, DeletableInterface
{
    public const string TYPE = 'note';
    public const string ID = 'id';
    public const string TEXT = 'text';

    public const array ATTRIBUTES = [
        self::ID => ['column' => WritableNotesSource::ID, 'type' => self::INT, 'constraint' => self::PRIMARY],
        self::TEXT => ['column' => WritableNotesSource::TEXT, 'type' => self::STRING],
    ];

    public static function getModel(): string
    {
        return WritableNotesSource::class;
    }
}
