<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Example\Entity;

use Ggs\JsonApiEngine\Model\Entity;

/**
 * Таблица `domain_configs`: настройки домена, 1:1 с `domains`.
 *
 * `domain_id` — первичный ключ и одновременно внешний ключ на `domains.id`. Колонка описывает только одно
 * ограничение, поэтому записано `PRIMARY`; связь с доменом задаётся в модели ресурса.
 */
final class DomainConfigsEntity extends Entity
{
    public const string TABLE = 'domain_configs';

    public const string DOMAIN_ID = 'domain_id';
    public const string AUTO_RENEW = 'auto_renew';
    public const string DNS_PROVIDER = 'dns_provider';
    public const string SSL_REDIRECT_ENABLED = 'ssl_redirect_enabled';
    public const string CUSTOM_NGINX_CONFIG = 'custom_nginx_config';

    public const array COLUMNS = [
        self::DOMAIN_ID => ['type' => self::INT, 'constraint' => self::PRIMARY],
        self::AUTO_RENEW => ['type' => self::BOOL],
        self::DNS_PROVIDER => ['type' => self::STRING, 'nullable' => true],
        self::SSL_REDIRECT_ENABLED => ['type' => self::BOOL, 'nullable' => true],
        self::CUSTOM_NGINX_CONFIG => ['type' => self::STRING, 'nullable' => true],
    ];
}
