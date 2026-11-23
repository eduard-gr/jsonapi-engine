<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\FixedColumn;

use Ggs\JsonApiEngine\Example\Container\ArrayContainer;
use Ggs\JsonApiEngine\Executor\Select\ReaderBuilder;
use Ggs\JsonApiEngine\Executor\Write\WriteNotFoundException;
use Ggs\JsonApiEngine\Executor\Write\WriterBuilder;
use Ggs\JsonApiEngine\Model\MetadataInterface;
use Ggs\JsonApiEngine\Parser\InvalidDocumentException;
use Ggs\JsonApiEngine\Parser\QueryParser;
use Ggs\JsonApiEngine\Parser\Write\WriteDocumentParser;
use Ggs\JsonApiEngine\Parser\Write\WriteOperation;
use Ggs\JsonApiEngine\Plan\Select\EntityScanNode;
use Ggs\JsonApiEngine\Plan\Select\SelectPlanner;
use Ggs\JsonApiEngine\Plan\Write\WritePlanner;
use Ggs\JsonApiEngine\Resource;
use Ggs\JsonApiEngine\Test\FixedColumn\Fixture\FixedForeignSource;
use Ggs\JsonApiEngine\Test\FixedColumn\Fixture\FixedWithoutValueEntity;
use Ggs\JsonApiEngine\Test\FixedColumn\Fixture\FixedWritableEntity;
use Ggs\JsonApiEngine\Test\FixedColumn\Fixture\FixedWrongTypeEntity;
use Ggs\JsonApiEngine\Test\FixedColumn\Fixture\MapComponentEntity;
use Ggs\JsonApiEngine\Test\FixedColumn\Fixture\MapResource;
use Ggs\JsonApiEngine\Test\FixedColumn\Fixture\ProfileResource;
use Ggs\JsonApiEngine\Test\FixedColumn\Fixture\ProfilesEntity;
use Ggs\JsonApiEngine\Test\FixedColumn\Fixture\TrackingComponentEntity;
use Ggs\JsonApiEngine\Test\FixedColumn\Fixture\TrackingResource;
use Ggs\JsonApiEngine\Test\FixedColumn\Fixture\UnknownModeEntity;
use Ggs\JsonApiEngine\Test\FixedColumn\Fixture\ValueWithoutFixedEntity;
use Ggs\JsonApiEngine\Test\FixedColumn\Fixture\WritableKindResource;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use sad_spirit\pg_builder\SqlBuilderWalker;

/**
 * Постоянная колонка сущности (`'mode' => FIXED`, `'value'`): сущность — строки таблицы с этим значением. Две
 * сущности над одной таблицей `components` (вид 70 и 1200) читаются и пишутся как разные таблицы: движок сам ставит
 * условие в чтение (подзапрос в FROM — работает и в JOIN), изменение и удаление и значение во вставку. Режим
 * колонки и атрибута — битовая маска. База — SQLite в памяти.
 */
final class FixedColumnTest extends TestCase
{
    private PDO $pdo;

    #[\Override]
    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('create table profiles (id integer primary key, login text not null)');
        $this->pdo->exec(
            'create table components (user_profile_id integer not null, component_id integer not null, data text not null,'
            . ' primary key (user_profile_id, component_id))',
        );
        $this->pdo->exec("insert into profiles (id, login) values (1, 'anna'), (2, 'boris')");
        $this->pdo->exec(
            "insert into components (user_profile_id, component_id, data) values (1, 70, 'map-1'), (1, 1200, 'tracking-1'),"
            . " (2, 1200, 'tracking-2')",
        );
    }

    public function testModeIsBitMask(): void
    {
        self::assertSame(MetadataInterface::READ | MetadataInterface::WRITE, MetadataInterface::READ_WRITE);
        self::assertSame(0, MetadataInterface::READ & MetadataInterface::WRITE);
        self::assertSame(0, MetadataInterface::READ_WRITE & MetadataInterface::FIXED);

        self::assertSame([MapComponentEntity::COMPONENT_ID => 70], MapComponentEntity::getFixedValues());
        self::assertSame([], ProfilesEntity::getFixedValues());
        foreach ([ProfilesEntity::class, MapComponentEntity::class, TrackingComponentEntity::class] as $entity) {
            $entity::validate();
        }
        foreach ([ProfileResource::class, MapResource::class, TrackingResource::class] as $resource) {
            $resource::validate();
        }
    }

    public function testFixedColumnLimitsRowsOfRootResource(): void
    {
        $plan = self::getPlan(resource: MapResource::class, query: []);
        self::assertInstanceOf(EntityScanNode::class, $plan);
        self::assertStringContainsString(
            'from ( select * from components where component_id = 70 ) as t0',
            (string) preg_replace('/\s+/', ' ', $plan->select->dispatch(new SqlBuilderWalker())),
        );

        $document = $this->getDocument(resource: MapResource::class, query: []);
        self::assertSame(
            [['id' => '1', 'type' => 'map', 'attributes' => ['data' => 'map-1', 'kind' => 70]]],
            $document['data'],
        );
    }

    public function testFixedColumnWorksInsideJoin(): void
    {
        $document = $this->getDocument(resource: ProfileResource::class, query: ['include' => 'map,tracking']);

        // Три компонента в одной таблице не размножают профили: условие стоит в JOIN, а не в WHERE
        self::assertSame(['1', '2'], array_column($document['data'], 'id'));
        $relationships = array_column($document['data'], 'relationships', 'id');
        self::assertSame(['id' => '1', 'type' => 'map'], $relationships['1']['map']['data']);
        self::assertSame(['id' => '1', 'type' => 'tracking'], $relationships['1']['tracking']['data']);
        // У профиля 2 карты нет, но сам профиль на месте
        self::assertNull($relationships['2']['map']['data']);
        self::assertSame(['id' => '2', 'type' => 'tracking'], $relationships['2']['tracking']['data']);

        $included = array_column($document['included'], 'attributes', 'type');
        self::assertSame('map-1', $included['map']['data']);
    }

    public function testInsertWritesFixedValueAndReplaceKeepsOtherKinds(): void
    {
        // Профилю 2 назначается новая карта: связь 1-1 заменяется целиком — прежняя карта удаляется, новая вставляется
        $roots = [new WriteDocumentParser()->update(
            resource: ProfileResource::class,
            id: '2',
            document: [
                'data' => [
                    'type' => 'profile',
                    'id' => '2',
                    'relationships' => ['map' => ['data' => ['type' => 'map', 'lid' => 'm1']]],
                ],
                'included' => [['type' => 'map', 'lid' => 'm1', 'attributes' => ['data' => 'map-2']]],
            ],
        )];
        $this->expectErrorLog();
        $this->execute(resource: ProfileResource::class, roots: $roots);

        // Вид компонента клиент не присылал — его подставил движок; удаление «прежней карты» не тронуло трекинг
        // того же профиля, хотя он лежит в той же таблице с тем же user_profile_id
        self::assertSame(
            [['component_id' => 70, 'data' => 'map-2'], ['component_id' => 1200, 'data' => 'tracking-2']],
            $this->getRows('select component_id, data from components where user_profile_id = 2 order by component_id'),
        );
    }

    public function testUpdateAndDeleteTouchOnlyRowsOfTheirKind(): void
    {
        $this->expectErrorLog();
        $this->execute(resource: MapResource::class, roots: [new WriteDocumentParser()->update(
            resource: MapResource::class,
            id: '1',
            document: ['data' => ['type' => 'map', 'id' => '1', 'attributes' => ['data' => 'changed']]],
        )]);
        self::assertSame(
            [['component_id' => 70, 'data' => 'changed'], ['component_id' => 1200, 'data' => 'tracking-1']],
            $this->getRows('select component_id, data from components where user_profile_id = 1 order by component_id'),
        );

        $this->execute(resource: MapResource::class, roots: [new WriteDocumentParser()->delete(resource: MapResource::class, id: '1')]);
        self::assertSame(
            [['component_id' => 1200]],
            $this->getRows('select component_id from components where user_profile_id = 1'),
        );
    }

    public function testRowOfAnotherKindIsNotFound(): void
    {
        // У профиля 2 есть только трекинг (1200): карты (70) с таким id нет, хотя строка в таблице есть
        $roots = [new WriteDocumentParser()->update(
            resource: MapResource::class,
            id: '2',
            document: ['data' => ['type' => 'map', 'id' => '2', 'attributes' => ['data' => 'changed']]],
        )];

        try {
            $this->expectErrorLog();
            $this->execute(resource: MapResource::class, roots: $roots);
            self::fail('a row of another kind must not be found');
        } catch (WriteNotFoundException) {
            self::assertSame([['data' => 'tracking-2']], $this->getRows('select data from components where user_profile_id = 2'));
        }
    }

    public function testClientCanNotWriteFixedColumn(): void
    {
        $this->expectException(InvalidDocumentException::class);
        new WriteDocumentParser()->update(
            resource: MapResource::class,
            id: '1',
            document: ['data' => ['type' => 'map', 'id' => '1', 'attributes' => ['kind' => 1200]]],
        );
    }

    /**
     * @return iterable<string, array{class-string, string}>
     */
    public static function getInvalidDescriptions(): iterable
    {
        yield 'FIXED without value' => [FixedWithoutValueEntity::class, 'FIXED mode and value are declared only together'];
        yield 'value without FIXED' => [ValueWithoutFixedEntity::class, 'FIXED mode and value are declared only together'];
        yield 'FIXED with WRITE' => [FixedWritableEntity::class, 'FIXED column can not be WRITE'];
        yield 'value of another type' => [FixedWrongTypeEntity::class, 'value does not match type int'];
        yield 'unknown mode bit' => [UnknownModeEntity::class, 'mode must be a mask of READ, WRITE and FIXED'];
        yield 'FIXED in a foreign data source' => [FixedForeignSource::class, 'FIXED is supported only by'];
        yield 'writable attribute over a FIXED column' => [WritableKindResource::class, "is FIXED, declare 'mode' => self::READ"];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('getInvalidDescriptions')]
    public function testInvalidDescriptionIsRejected(string $class, string $message): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($message);
        $class::validate();
    }

    /**
     * @param class-string<Resource> $resource
     * @param array<string, mixed>   $query
     */
    private static function getPlan(string $resource, array $query): mixed
    {
        return new SelectPlanner()->plan(tree: new QueryParser()->parse(resource: $resource, query: $query))['sql'];
    }

    /**
     * @param class-string<Resource> $resource
     * @param array<string, mixed>   $query
     * @return array{data: list<array<string, mixed>>, included?: list<array<string, mixed>>}
     */
    private function getDocument(string $resource, array $query): array
    {
        $builder = new ReaderBuilder(
            pdo: $this->pdo,
            container: self::getContainer(),
        );

        return json_decode((string) json_encode($builder->getDocument(plan: self::getPlan(resource: $resource, query: $query))), true);
    }

    /**
     * @param class-string<Resource> $resource
     * @param list<WriteOperation>   $roots
     */
    private function execute(string $resource, array $roots): void
    {
        $builder = new WriterBuilder(
            pdo: $this->pdo,
            lock: false,
            container: self::getContainer(),
        );
        $builder->getRoot(plan: new WritePlanner()->plan(resource: $resource, roots: $roots), data: $roots)->execute();
    }

    private static function getContainer(): ArrayContainer
    {
        return new ArrayContainer(
            services: [
                new ProfileResource(),
                new MapResource(),
                new TrackingResource(),
            ],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getRows(string $sql): array
    {
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
}
