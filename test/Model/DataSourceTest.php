<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Model;

use Ggs\JsonApiEngine\Model\ModelTree;
use Ggs\JsonApiEngine\Test\Model\Fixture\CertificateModel;
use Ggs\JsonApiEngine\Test\Model\Fixture\Entity\CertificatesEntity;
use Ggs\JsonApiEngine\Test\Model\Fixture\Source\DatabaseColumnSource;
use Ggs\JsonApiEngine\Test\Model\Fixture\Source\JoinedRatingModel;
use Ggs\JsonApiEngine\Test\Model\Fixture\Source\RatingModel;
use Ggs\JsonApiEngine\Test\Model\Fixture\Source\RatingsSource;
use PHPUnit\Framework\TestCase;

/**
 * Чужой источник (`DataSource`, но не `DatabaseEntitySource`): проверка описания и его место в модели данных.
 */
final class DataSourceTest extends TestCase
{
    public function testForeignDataSourceIsValid(): void
    {
        RatingsSource::validate();

        self::assertSame(RatingsSource::CERTIFICATE_ID, RatingsSource::getPrimaryKeyColumnName());
        self::assertTrue(RatingsSource::isUnique(RatingsSource::CERTIFICATE_ID));
        self::assertFalse(RatingsSource::isUnique(RatingsSource::SCORE));
    }

    public function testDatabaseColumnNameBelongsToEntityOnly(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('column id: unknown key column');
        DatabaseColumnSource::validate();
    }

    public function testModelOfOneSource(): void
    {
        $tree = ModelTree::of(RatingModel::class);

        self::assertSame([RatingsSource::CERTIFICATE_ID, RatingsSource::SCORE], $tree->getColumns());
        self::assertSame(RatingsSource::class, $tree->getRootDataSource());
        self::assertNull($tree->getRootEntity(), 'a foreign data source is not an entity');
        self::assertTrue($tree->isUnique(RatingsSource::CERTIFICATE_ID));
    }

    public function testForeignDataSourceHasNoSql(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(RatingsSource::class . ' is a foreign data source: it has no SQL');
        ModelTree::of(RatingModel::class)->getFrom('t0', [RatingsSource::SCORE => true]);
    }

    public function testForeignDataSourceCannotBeJoined(): void
    {
        $errors = implode("\n", ModelTree::build(JoinedRatingModel::class)->getErrors());

        self::assertStringContainsString(
            'model root.right: ' . RatingsSource::class . ' is a foreign data source and cannot be joined',
            $errors,
        );
    }

    public function testEntityIsItsOwnRootSource(): void
    {
        $tree = ModelTree::of(CertificateModel::class);

        self::assertSame(CertificatesEntity::class, $tree->getRootEntity());
        self::assertSame(CertificatesEntity::class, $tree->getRootDataSource());
    }
}
