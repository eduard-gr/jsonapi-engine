<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Model\Fixture\Model;

use Ggs\JsonApiEngine\Model\DatabaseEntitySource;

/**
 * CSR сертификата в отдельной таблице: первичный ключ — ссылка на сертификат.
 */
final class CsrEntity extends DatabaseEntitySource
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
