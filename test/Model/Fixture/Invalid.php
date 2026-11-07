<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Model\Fixture\Invalid;

use Ggs\JsonApiEngine\Model\Entity;
use Ggs\JsonApiEngine\Model\InnerJoin;
use Ggs\JsonApiEngine\Model\Join;
use Ggs\JsonApiEngine\Model\LeftJoin;
use Ggs\JsonApiEngine\Test\Model\Fixture\Model\CustomersEntity;
use Ggs\JsonApiEngine\Test\Model\Fixture\Model\OrdersEntity;

/**
 * Сущности и модели с ошибками для тестов `Entity::validate()`, `ModelTree` и `EntityDatabaseValidator`.
 * Подключаются через require_once: PSR-4 требует по классу на файл.
 */

final class ThingsEntity extends Entity
{
    public const string TABLE = 'things';

    public const string THING_ID = 'thing_id';
    public const string ID = 'id';
    public const string NAME = 'name';

    public const array COLUMNS = [
        self::THING_ID => ['type' => self::INT, 'constraint' => self::PRIMARY],
        self::ID => ['type' => self::INT],
        self::NAME => ['type' => self::STRING],
    ];
}

final class DetailsEntity extends Entity
{
    public const string TABLE = 'details';

    public const string DETAIL_ID = 'detail_id';
    public const string THING_ID = 'thing_id';
    public const string NAME = 'name';
    public const string NOTE = 'note';

    public const array COLUMNS = [
        self::DETAIL_ID => ['type' => self::INT, 'constraint' => self::PRIMARY],
        self::THING_ID => ['type' => self::INT],
        self::NAME => ['type' => self::STRING],
        self::NOTE => ['type' => self::STRING],
    ];
}

final class NotesEntity extends Entity
{
    public const string TABLE = 'notes';

    public const string NOTE_ID = 'note_id';
    public const string TEXT = 'text';

    public const array COLUMNS = [
        self::NOTE_ID => ['type' => self::INT, 'constraint' => self::PRIMARY],
        self::TEXT => ['type' => self::STRING],
    ];
}

/**
 * Модель с ошибками на каждом уровне дерева для тестов `ModelTree`.
 */
final class BadModel
{
    public static function model(): Join
    {
        return new LeftJoin(
            left: new LeftJoin(
                left: new LeftJoin(
                    left: new LeftJoin(ThingsEntity::class, DetailsEntity::class, ['thing_id', 'note']),
                    right: NotesEntity::class,
                    relation: ['missing', 'note_id'],
                ),
                right: \stdClass::class,
                relation: ['id', 'id'],
            ),
            right: new InnerJoin(OrdersEntity::class, CustomersEntity::class, ['customer_id', 'id']),
            relation: ['thing_id', 'customer_name'],
        );
    }
}

/**
 * Сущности с ошибками в константах для тестов Entity::validate().
 */
final class NoPrimaryEntity extends Entity
{
    public const string TABLE = '';

    public const string NAME = 'name';

    public const array COLUMNS = [
        self::NAME => ['type' => self::STRING],
    ];
}

final class BadEntity extends Entity
{
    public const string TABLE = 'bad';

    public const string ID = 'id';
    public const string NAME = 'name';
    public const string OWNER_ID = 'owner_id';
    public const string PARENT_ID = 'parent_id';
    public const string STRAY = 'stray';
    public const string ORPHAN = 'orphan';

    public const array COLUMNS = [
        self::ID => ['type' => self::INT, 'constraint' => self::PRIMARY],
        self::NAME => ['colum' => 'title', 'type' => 'varchar', 'unique' => 'yes'],
        self::OWNER_ID => ['type' => self::STRING, 'constraint' => self::FOREIGN, 'references' => ThingsEntity::class],
        self::PARENT_ID => ['type' => self::INT, 'constraint' => self::FOREIGN, 'references' => \stdClass::class],
        self::STRAY => ['type' => self::INT, 'references' => ThingsEntity::class],
        'unnamed' => ['type' => self::INT],
    ];
}

/**
 * Сущности, не совпадающие с тестовой БД, для тестов EntityDatabaseValidator.
 */
final class MissingTableEntity extends Entity
{
    public const string TABLE = 'no_such_table';

    public const string ID = 'id';

    public const array COLUMNS = [
        self::ID => ['type' => self::INT, 'constraint' => self::PRIMARY],
    ];
}

final class WrongCertificateKeysEntity extends Entity
{
    public const string TABLE = 'certificate_keys';

    public const string KEY_ID = 'key_id';
    public const string CERTIFICATE_ID = 'certificate_id';
    public const string KEY_ALGORITHM = 'key_algorithm';
    public const string KEY_SIZE = 'key_size';
    public const string LABEL = 'label';

    public const array COLUMNS = [
        self::KEY_ID => ['column' => 'id', 'type' => self::INT],
        self::CERTIFICATE_ID => ['type' => self::INT, 'constraint' => self::PRIMARY],
        self::KEY_ALGORITHM => ['column' => 'algorithm', 'type' => self::STRING, 'nullable' => true, 'unique' => true],
        self::KEY_SIZE => [
            'column' => 'size',
            'type' => self::STRING,
            'constraint' => self::FOREIGN,
            'references' => ThingsEntity::class,
        ],
        self::LABEL => ['type' => self::STRING],
    ];
}
