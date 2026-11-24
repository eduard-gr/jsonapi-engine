<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Parser\Fixture;

use Ggs\JsonApiEngine\Example\Entity\CertificatesEntity;
use Ggs\JsonApiEngine\Resource;

/**
 * Ошибка описания: первичный ключ объявлен `OPTIONAL`, хотя `id` отдаётся всегда.
 */
final class OptionalPrimaryResource extends Resource
{
    public const string TYPE = 'optional-primary';
    public const string ID = 'id';

    public const array ATTRIBUTES = [
        self::ID => [
            'column' => CertificatesEntity::ID,
            'type' => self::INT,
            'constraint' => self::PRIMARY,
            'mode' => self::READ | self::OPTIONAL,
        ],
    ];

    public static function getModel(): string
    {
        return CertificatesEntity::class;
    }
}
