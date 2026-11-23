<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Plan\Select;

use Ggs\JsonApiEngine\Document\ResourceRegistry;
use Ggs\JsonApiEngine\Example\Container\ArrayContainer;
use Ggs\JsonApiEngine\Example\Container\ExampleResources;
use Ggs\JsonApiEngine\Example\Resource\DetailResource;
use Ggs\JsonApiEngine\Executor\Select\ForeignDataScanReader;
use Ggs\JsonApiEngine\Executor\Select\HashOneJoinReader;
use Ggs\JsonApiEngine\Executor\Select\ReaderBuilder;
use Ggs\JsonApiEngine\Operator;
use Ggs\JsonApiEngine\Parser\QueryParser;
use Ggs\JsonApiEngine\Plan\Select\EntityScanNode;
use Ggs\JsonApiEngine\Plan\Select\EntityScanNodeDraft;
use Ggs\JsonApiEngine\Plan\Select\EntityScanNodeDraftPass;
use Ggs\JsonApiEngine\Plan\Select\EntitySharedJoinNode;
use Ggs\JsonApiEngine\Plan\Select\ForeignDataScanNode;
use Ggs\JsonApiEngine\Plan\Select\HashNestJoinNode;
use Ggs\JsonApiEngine\Plan\Select\HashOneJoinNode;
use Ggs\JsonApiEngine\Plan\Select\JoinNode;
use Ggs\JsonApiEngine\Plan\Select\MergeNestJoinNode;
use Ggs\JsonApiEngine\Plan\Select\ResourceNode;
use Ggs\JsonApiEngine\Plan\Select\SelectPlanner;
use Ggs\JsonApiEngine\Resource;
use Ggs\JsonApiEngine\Test\Plan\Select\Fixture\ForeignCertificateResource;
use Ggs\JsonApiEngine\Test\Plan\Select\Fixture\ForeignGroupResource;
use Ggs\JsonApiEngine\Test\Plan\Select\Fixture\ForeignGroupsSource;
use Ggs\JsonApiEngine\Test\Plan\Select\Fixture\ForeignNoteResource;
use Ggs\JsonApiEngine\Test\Plan\Select\Fixture\ForeignNotesSource;
use Ggs\JsonApiEngine\Test\Plan\Select\Fixture\ForeignScoreResource;
use Ggs\JsonApiEngine\Test\Plan\Select\Fixture\ForeignScoresSource;
use LogicException;
use PHPUnit\Framework\TestCase;
use sad_spirit\pg_builder\SqlBuilderWalker;

require_once __DIR__ . '/../../../example/db/connection.php';

/**
 * Чужой источник (`Model\ForeignDataSource`) в плане чтения: ресурс над ним — отдельный лист `ForeignDataScanNode`,
 * связь 1-1 с ним выходит из общей строки (`HashOneJoinNode`), слияние выбирается по ориентации чтения источника,
 * цепочка предков на нём обрывается; `ReaderBuilder` читает его через объект из контейнера и передаёт аргументы
 * маршрута. Сертификаты и домены — из SQLite базы примера.
 */
final class ForeignDataPlanTest extends TestCase
{
    public function testForeignDataIsSeparateLeafOutsideOfSharedRow(): void
    {
        $plan = self::getPlan(resource: ForeignCertificateResource::class, query: ['include' => 'score,notes,domains']);

        // domains (таблица) — Hash: порядок сертификатов уже занят слиянием с notes
        self::assertInstanceOf(HashNestJoinNode::class, $plan);
        self::assertSame('domains', $plan->name);
        self::assertInstanceOf(EntityScanNode::class, $plan->inner);

        // notes: ключ связи — первая колонка ориентации чтения источника, поэтому слияние
        $notes = $plan->outer;
        self::assertInstanceOf(MergeNestJoinNode::class, $notes);
        self::assertInstanceOf(ForeignDataScanNode::class, $notes->inner);
        self::assertSame(ForeignNotesSource::class, $notes->inner->getDataSource());
        self::assertSame(ForeignNotesSource::CERTIFICATE_ID, $notes->inner->order);
        self::assertSame([ForeignNotesSource::CERTIFICATE_ID], $notes->inner->keys);
        self::assertSame(
            [ForeignNotesSource::ID, ForeignNotesSource::TEXT, ForeignNotesSource::CERTIFICATE_ID],
            $notes->inner->getColumns(),
        );

        // score: связь 1-1 с чужим источником — не общая строка, а соединение потоков
        $score = $notes->outer;
        self::assertInstanceOf(HashOneJoinNode::class, $score);
        self::assertInstanceOf(ForeignDataScanNode::class, $score->inner);
        self::assertNull($score->inner->order);
        self::assertInstanceOf(EntityScanNode::class, $score->outer);
        self::assertInstanceOf(ResourceNode::class, $score->outer->input);

        self::assertSame([], self::getDrafts(node: $plan));
    }

    public function testClientFiltersAndSortGoToForeignDataInColumns(): void
    {
        $plan = self::getPlan(resource: ForeignNoteResource::class, query: ['filter' => ['text' => 'first'], 'sort' => '-text']);

        self::assertInstanceOf(ForeignDataScanNode::class, $plan);
        self::assertSame(
            [['column' => ForeignNotesSource::TEXT, 'operator' => Operator::Eq, 'value' => 'first']],
            $plan->getConditions(),
        );
        self::assertSame([['column' => ForeignNotesSource::TEXT, 'descending' => true]], $plan->getSortOrder());
        self::assertSame([], $plan->keys);
    }

    public function testForeignDataWithClientSortIsNotMerged(): void
    {
        $plan = self::getPlan(resource: ForeignCertificateResource::class, query: ['include' => 'notes', 'sort' => 'notes.text']);

        self::assertInstanceOf(HashNestJoinNode::class, $plan);
        self::assertInstanceOf(ForeignDataScanNode::class, $plan->inner);
        self::assertNull($plan->inner->order);
    }

    public function testAncestorChainStopsAtForeignData(): void
    {
        $plan = self::getPlan(resource: ForeignGroupResource::class, query: ['include' => 'certificates.domains']);

        self::assertInstanceOf(JoinNode::class, $plan);
        self::assertInstanceOf(ForeignDataScanNode::class, $plan->outer);
        $certificates = $plan->inner;
        self::assertInstanceOf(JoinNode::class, $certificates);
        self::assertInstanceOf(EntityScanNode::class, $certificates->outer);
        self::assertInstanceOf(EntityScanNode::class, $certificates->inner);

        // Ребёнок чужого источника условий родителя не получает; его собственный ребёнок получает только его условия
        self::assertStringNotContainsString('with ', self::getSql(node: $certificates->outer));
        $domains = self::getSql(node: $certificates->inner);
        self::assertStringContainsString('with p1 as ( select t0.id as id from certificates as t0 )', $domains);
        self::assertStringNotContainsString('p2', $domains);
    }

    public function testDocumentReadsForeignDataAndSkipsOrphans(): void
    {
        $notes = new ForeignNotesSource();
        $scores = new ForeignScoresSource();
        $plan = self::getPlan(
            resource: ForeignCertificateResource::class,
            query: ['include' => 'score,notes', 'filter' => ['id' => ['lte' => '3']], 'fields' => ['certificate' => 'code']],
        );
        $builder = new ReaderBuilder(
            pdo: getPDO(),
            container: self::getContainer(sources: [$notes, $scores]),
        );

        $explain = json_encode($builder->build(plan: $plan, registry: new ResourceRegistry())->explain());
        self::assertStringContainsString(json_encode(ForeignDataScanReader::class), (string) $explain);
        self::assertStringContainsString(json_encode(HashOneJoinReader::class), (string) $explain);

        /** @var array{data: list<array<string, mixed>>, included: list<array<string, mixed>>} $document */
        $document = json_decode((string) json_encode($builder->getDocument(plan: $plan, arguments: ['tenant' => 7])), true);

        self::assertSame(['1', '2', '3'], array_column($document['data'], 'id'));
        $relationships = array_column($document['data'], 'relationships', 'id');
        self::assertSame(['id' => '1', 'type' => 'score'], $relationships['1']['score']['data']);
        self::assertNull($relationships['3']['score']['data']);
        self::assertSame(['1', '2'], array_column($relationships['1']['notes']['data'], 'id'));
        self::assertSame(['3'], array_column($relationships['2']['notes']['data'], 'id'));
        self::assertSame([], $relationships['3']['notes']['data']);

        // Заметка без сертификата до документа не доходит
        $included = array_map(static fn(array $resource): string => "{$resource['type']}:{$resource['id']}", $document['included']);
        sort($included);
        self::assertSame(['note:1', 'note:2', 'note:3', 'score:1', 'score:2'], $included);

        // Источник получает условия клиента и аргументы маршрута, но не ключи родителей
        self::assertSame(['tenant' => 7], $notes->calls[0]['arguments']);
        self::assertSame([], $notes->calls[0]['conditions']);
        self::assertSame(['tenant' => 7], $scores->calls[0]['arguments']);
    }

    public function testForeignDataAsParentOfTable(): void
    {
        $plan = self::getPlan(
            resource: ForeignGroupResource::class,
            query: ['include' => 'certificates', 'filter' => ['certificates.id' => ['lte' => '3']], 'fields' => ['certificate' => 'code']],
        );
        $builder = new ReaderBuilder(
            pdo: getPDO(),
            container: self::getContainer(sources: [new ForeignGroupsSource()]),
        );
        /** @var array{data: list<array<string, mixed>>} $document */
        $document = json_decode((string) json_encode($builder->getDocument(plan: $plan)), true);

        // Сертификаты 1, 2, 3 — у групп 3, 4, 1; сертификат группы, которой нет в источнике, был бы пропущен
        $certificates = array_map(
            static fn(array $group): array => array_column($group['relationships']['certificates']['data'], 'id'),
            array_column($document['data'], null, 'id'),
        );
        self::assertSame(['1' => ['3'], '3' => ['1'], '4' => ['2']], $certificates);
    }

    public function testOneToOneWithForeignDataInsideSharedRowIsRejected(): void
    {
        $row = new EntitySharedJoinNode(
            name: 'detail',
            relation: ['id', 'certificate_id'],
            outer: self::getResourceNode(resource: ForeignCertificateResource::class),
            inner: new HashOneJoinNode(
                name: 'score',
                relation: ['certificate_id', 'certificate_id'],
                outer: self::getResourceNode(resource: DetailResource::class),
                inner: self::getResourceNode(resource: ForeignScoreResource::class),
            ),
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("One-to-one relationship 'score' with a foreign data source inside a shared row");
        new EntityScanNodeDraftPass()(plan: $row);
    }

    public function testScanNodeRejectsResourceOverEntity(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('is not read from a foreign data source');
        new ForeignDataScanNode(
            input: self::getResourceNode(resource: ForeignCertificateResource::class),
        );
    }

    /**
     * @param class-string<Resource> $resource
     * @param array<string, mixed>   $query
     */
    private static function getPlan(string $resource, array $query): ResourceNode|EntityScanNodeDraft|EntityScanNode|ForeignDataScanNode|JoinNode
    {
        return new SelectPlanner()->plan(tree: new QueryParser()->parse(resource: $resource, query: $query))['sql'];
    }

    /**
     * @param class-string<Resource> $resource
     */
    private static function getResourceNode(string $resource): ResourceNode
    {
        return new ResourceNode(
            resource: $resource,
            fields: [],
            filters: [],
            sort: [],
        );
    }

    /**
     * @param list<object> $sources
     */
    private static function getContainer(array $sources): ArrayContainer
    {
        return new ArrayContainer(
            services: [
                ...$sources,
                ...ExampleResources::getAll(),
                new ForeignCertificateResource(),
                new ForeignNoteResource(),
                new ForeignScoreResource(),
                new ForeignGroupResource(),
            ],
        );
    }

    private static function getSql(EntityScanNode $node): string
    {
        return (string) preg_replace('/\s+/', ' ', $node->select->dispatch(new SqlBuilderWalker()));
    }

    /**
     * Узлы без SQL, оставшиеся в плане: после шага sql их быть не должно.
     *
     * @return list<EntityScanNodeDraft>
     */
    private static function getDrafts(ResourceNode|EntityScanNodeDraft|EntityScanNode|ForeignDataScanNode|JoinNode $node): array
    {
        if ($node instanceof EntityScanNodeDraft) {
            return [$node];
        }
        if ($node instanceof JoinNode) {
            return [...self::getDrafts(node: $node->outer), ...self::getDrafts(node: $node->inner)];
        }

        return [];
    }
}
