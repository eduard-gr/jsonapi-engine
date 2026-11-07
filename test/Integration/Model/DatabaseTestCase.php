<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Integration\Model;

use PHPUnit\Framework\TestCase;
use sad_spirit\pg_wrapper\Connection;

/**
 * Подключение к тестовой БД (`DQI_TEST_DSN` в phpunit.xml, `docker compose up -d`) и схема `dm` из
 * `test/Model/Fixture/schema.sql`, заново на каждый класс тестов. Без БД тесты пропускаются.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected static ?Connection $connection = null;

    public static function setUpBeforeClass(): void
    {
        $dsn = getenv('DQI_TEST_DSN') ?: '';
        try {
            self::$connection = new Connection($dsn, false);
        } catch (\Throwable $e) {
            self::markTestSkipped('PostgreSQL is not available (DQI_TEST_DSN): ' . $e->getMessage());
        }
        self::$connection->execute((string) file_get_contents(__DIR__ . '/../../Model/Fixture/schema.sql'));
        self::$connection->execute('SET search_path TO dm');
    }

    public static function tearDownAfterClass(): void
    {
        self::$connection = null;
    }

    protected static function connection(): Connection
    {
        return self::$connection ?? throw new \LogicException('No connection');
    }
}
