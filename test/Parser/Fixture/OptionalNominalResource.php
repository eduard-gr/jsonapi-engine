<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Parser\Fixture;

use Ggs\JsonApiEngine\Example\Entity\CertificatesEntity;
use Ggs\JsonApiEngine\Resource;

/**
 * Сертификат из таблицы примера с атрибутом «только по запросу»: `nominal` объявлен `OPTIONAL` — без `fields` его в
 * ответе нет, по имени в `fields` он отдаётся.
 */
final class OptionalNominalResource extends Resource
{
    public const string TYPE = 'certificate';
    public const string ID = 'id';
    public const string CODE = 'code';
    public const string STATUS = 'status';
    public const string NOMINAL = 'nominal';

    public const array ATTRIBUTES = [
        self::ID => ['column' => CertificatesEntity::ID, 'type' => self::INT, 'constraint' => self::PRIMARY],
        self::CODE => ['column' => CertificatesEntity::CODE, 'type' => self::STRING],
        self::STATUS => ['column' => CertificatesEntity::STATUS, 'type' => self::STRING],
        self::NOMINAL => [
            'column' => CertificatesEntity::NOMINAL,
            'type' => self::STRING,
            'mode' => self::READ | self::WRITE | self::OPTIONAL,
        ],
    ];

    public static function getModel(): string
    {
        return CertificatesEntity::class;
    }
}
