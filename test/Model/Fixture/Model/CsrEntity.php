<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Model\Fixture\Model;

use Ggs\JsonApiEngine\Model\Entity;

/**
 * CSR сертификата в отдельной таблице: первичный ключ — ссылка на сертификат.
 */
final class CsrEntity extends Entity
{
    public const string TABLE = 'certificates_csr';

    public const string CERTIFICATE_ID = 'certificate_id';
    public const string CSR = 'csr';

    public const array COLUMNS = [
        self::CERTIFICATE_ID => [
            'type' => self::INT,
            'constraint' => self::PRIMARY,
        ],
        self::CSR => ['type' => self::STRING],
    ];
}
