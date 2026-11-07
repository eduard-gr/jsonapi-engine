<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Model;

use Ggs\JsonApiEngine\Model\Entity;
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
     * @return iterable<string, array{class-string<Entity>}>
     */
    public static function entities(): iterable
    {
        foreach (['Entity', 'Model'] as $dir) {
            foreach (glob(__DIR__ . "/Fixture/{$dir}/*.php") ?: [] as $file) {
                /** @var class-string<Entity> $class */
                $class = "Ggs\\JsonApiEngine\\Test\\Model\\Fixture\\{$dir}\\" . basename($file, '.php');
                yield "{$dir}/" . basename($file, '.php') => [$class];
            }
        }
    }

    /**
     * @param class-string<Entity> $class
     */
    #[DataProvider('entities')]
    public function testFixtureEntitiesAreValid(string $class): void
    {
        $class::validate();
        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{class-string<Entity>, list<string>}>
     */
    public static function invalid(): iterable
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
     * @param class-string<Entity> $class
     * @param list<string>         $messages
     */
    #[DataProvider('invalid')]
    public function testInvalidEntities(string $class, array $messages): void
    {
        try {
            $class::validate();
            self::fail("{$class}::validate() did not throw");
        } catch (\LogicException $e) {
            foreach ($messages as $message) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testStaticMetadata(): void
    {
        self::assertSame('id', CertificatesEntity::primaryColumn());
        self::assertSame('key_id', CertificateKeysEntity::primaryColumn());
        self::assertSame('size', CertificateKeysEntity::column(CertificateKeysEntity::KEY_SIZE));
        self::assertSame('user_id', CertificatesEntity::column(CertificatesEntity::USER_ID));
        self::assertTrue(CertificateKeysEntity::isUnique(CertificateKeysEntity::CERTIFICATE_ID));
        self::assertTrue(CertificateKeysEntity::isUnique(CertificateKeysEntity::KEY_ID));
        self::assertFalse(CertificateKeysEntity::isUnique(CertificateKeysEntity::KEY_SIZE));

        $this->expectException(\LogicException::class);
        Invalid\NoPrimaryEntity::primaryColumn();
    }
}
