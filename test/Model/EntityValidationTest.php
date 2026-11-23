<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Model;

use Ggs\JsonApiEngine\Model\DatabaseEntitySource;
use Ggs\JsonApiEngine\Test\Model\Fixture\Entity\CertificateKeysEntity;
use Ggs\JsonApiEngine\Test\Model\Fixture\Entity\CertificatesEntity;
use Ggs\JsonApiEngine\Test\Model\Fixture\Invalid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixture/Invalid.php';

final class EntityValidationTest extends TestCase
{
    /**
     * Сущности фикстур: таблицы тестовой БД (`Entity/`) и таблицы только для модульных тестов (`Model/`).
     *
     * @return iterable<string, array{class-string<DatabaseEntitySource>}>
     */
    public static function getEntities(): iterable
    {
        foreach (['Entity', 'Model'] as $directory) {
            foreach (glob(__DIR__ . "/Fixture/{$directory}/*.php") ?: [] as $file) {
                /** @var class-string<DatabaseEntitySource> $class */
                $class = "Ggs\\JsonApiEngine\\Test\\Model\\Fixture\\{$directory}\\" . basename($file, '.php');
                yield "{$directory}/" . basename($file, '.php') => [$class];
            }
        }
    }

    /**
     * @param class-string<DatabaseEntitySource> $class
     */
    #[DataProvider('getEntities')]
    public function testFixtureEntitiesAreValid(string $class): void
    {
        $class::validate();
        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{class-string<DatabaseEntitySource>, list<string>}>
     */
    public static function getInvalidEntities(): iterable
    {
        yield 'no primary' => [Invalid\NoPrimaryEntity::class, [
            'TABLE must be a non-empty string',
            'exactly one PRIMARY column is required, found 0',
        ]];
        yield 'bad columns' => [Invalid\BadEntity::class, [
            'column name: unknown key colum',
            'column name: unknown type varchar',
            'column name: unique must be bool',
            'column owner_id: type must match the primary key type of',
            'column parent_id: FOREIGN requires references',
            'column stray: references is allowed only with FOREIGN',
            'column unnamed: no constant declares its name',
            'constant ORPHAN: orphan is not in COLUMNS',
        ]];
    }

    /**
     * @param class-string<DatabaseEntitySource> $class
     * @param list<string>         $messages
     */
    #[DataProvider('getInvalidEntities')]
    public function testInvalidEntities(string $class, array $messages): void
    {
        try {
            $class::validate();
            self::fail("{$class}::validate() did not throw");
        } catch (\LogicException $exception) {
            foreach ($messages as $message) {
                self::assertStringContainsString($message, $exception->getMessage());
            }
        }
    }

    public function testStaticMetadata(): void
    {
        self::assertSame('id', CertificatesEntity::getPrimaryKeyColumnName());
        self::assertSame('key_id', CertificateKeysEntity::getPrimaryKeyColumnName());
        self::assertSame('size', CertificateKeysEntity::getColumn(CertificateKeysEntity::KEY_SIZE));
        self::assertSame('user_id', CertificatesEntity::getColumn(CertificatesEntity::USER_ID));
        self::assertTrue(CertificateKeysEntity::isUnique(CertificateKeysEntity::CERTIFICATE_ID));
        self::assertTrue(CertificateKeysEntity::isUnique(CertificateKeysEntity::KEY_ID));
        self::assertFalse(CertificateKeysEntity::isUnique(CertificateKeysEntity::KEY_SIZE));

        $this->expectException(\LogicException::class);
        Invalid\NoPrimaryEntity::getPrimaryKeyColumnName();
    }
}
