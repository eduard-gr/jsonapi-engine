<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Integration\Model;

use Ggs\JsonApiEngine\Model\Entity;
use Ggs\JsonApiEngine\Model\EntityDatabaseValidator;
use Ggs\JsonApiEngine\Test\Model\Fixture\Invalid;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/../../Model/Fixture/Invalid.php';

/**
 * Сущности фикстур совпадают с таблицами тестовой БД.
 */
final class EntityDatabaseTest extends DatabaseTestCase
{
    /**
     * @return iterable<string, array{class-string<Entity>}>
     */
    public static function entities(): iterable
    {
        foreach (glob(__DIR__ . '/../../Model/Fixture/Entity/*.php') as $file) {
            $class = 'Ggs\\JsonApiEngine\\Test\\Model\\Fixture\\Entity\\' . basename($file, '.php');
            yield basename($file, '.php') => [$class];
        }
    }

    /**
     * @param class-string<Entity> $class
     */
    #[DataProvider('entities')]
    public function testFixtureEntitiesMatchDatabase(string $class): void
    {
        EntityDatabaseValidator::validate(self::connection(), $class);
        $this->addToAssertionCount(1);
    }

    public function testMissingTable(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('table no_such_table does not exist');
        EntityDatabaseValidator::validate(self::connection(), Invalid\MissingTableEntity::class);
    }

    public function testMismatches(): void
    {
        try {
            EntityDatabaseValidator::validate(self::connection(), Invalid\WrongCertificateKeysEntity::class);
            self::fail('validate() did not throw');
        } catch (\LogicException $e) {
            foreach ([
                'column key_id (certificate_keys.id): column is the primary key, constraint must be PRIMARY',
                'column certificate_id (certificate_keys.certificate_id): column is not the single-column primary key',
                'column certificate_id (certificate_keys.certificate_id): column has a foreign key, constraint must be FOREIGN',
                'column key_algorithm (certificate_keys.algorithm): column is NOT NULL, nullable must be false',
                'column key_algorithm (certificate_keys.algorithm): column has no unique index',
                'column key_size (certificate_keys.size): type string does not match int4',
                'column key_size (certificate_keys.size): no foreign key to things.thing_id',
                'column label (certificate_keys.label): column does not exist',
            ] as $message) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }
}
