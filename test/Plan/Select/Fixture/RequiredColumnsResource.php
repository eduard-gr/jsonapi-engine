<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Plan\Select\Fixture;

use Ggs\JsonApiEngine\Model\Join;
use Ggs\JsonApiEngine\Resource;
use Ggs\JsonApiEngine\Test\Model\Fixture\CertificateModel;
use Ggs\JsonApiEngine\Test\Model\Fixture\Entity\CertificatesEntity;
use Ggs\JsonApiEngine\Test\Model\Fixture\Model\KeyAlgorithmsEntity;
use Ggs\JsonApiEngine\Test\Model\Fixture\Model\KeysEntity;

/**
 * Ресурс над `CertificateModel` для SelectColumnsTest: сам просит колонки модели, которых нет у его атрибутов
 * (`getRequiredColumns()`): `status` — всегда, `key_algorithm` из внутренней LEFT-таблицы — только когда запрошен
 * атрибут `key_size`.
 */
final class RequiredColumnsResource extends Resource
{
    public const string TYPE = 'required_columns';

    public const string ID = 'id';
    public const string KEY_SIZE = 'key_size';

    public const array ATTRIBUTES = [
        self::ID => ['column' => CertificatesEntity::ID, 'type' => self::INT, 'constraint' => self::PRIMARY],
        self::KEY_SIZE => ['column' => KeysEntity::KEY_SIZE, 'type' => self::INT],
    ];

    public static function getModel(): Join
    {
        return CertificateModel::getModel();
    }

    #[\Override]
    public static function getRequiredColumns(array $fields): array
    {
        $columns = [CertificatesEntity::STATUS];
        if (\in_array(self::KEY_SIZE, $fields, true)) {
            $columns[] = KeyAlgorithmsEntity::KEY_ALGORITHM;
        }

        return $columns;
    }
}
