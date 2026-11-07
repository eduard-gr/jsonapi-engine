<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Example\Entity;

use Ggs\JsonApiEngine\Model\Entity;

/**
 * Таблица `providers`: справочник провайдеров, у сертификата один провайдер (N:1 от `certificates`).
 */
final class ProvidersEntity extends Entity
{
    public const string TABLE = 'providers';

    public const string ID = 'id';
    public const string NAME = 'name';
    public const string WEBSITE = 'website';
    public const string CREATED_AT = 'created_at';

    public const array COLUMNS = [
        self::ID => ['type' => self::INT, 'constraint' => self::PRIMARY],
        self::NAME => ['type' => self::STRING, 'unique' => true],
        self::WEBSITE => ['type' => self::STRING, 'nullable' => true],
        self::CREATED_AT => ['type' => self::DATETIME],
    ];
}
