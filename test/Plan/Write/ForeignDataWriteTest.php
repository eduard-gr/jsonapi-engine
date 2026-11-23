<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Plan\Write;

use Ggs\JsonApiEngine\Example\Container\ArrayContainer;
use Ggs\JsonApiEngine\Executor\Delete\DeleteForeignDataWriter;
use Ggs\JsonApiEngine\Executor\Insert\InsertForeignDataWriter;
use Ggs\JsonApiEngine\Executor\Update\UpdateForeignDataWriter;
use Ggs\JsonApiEngine\Executor\Write\KeepForeignDataWriter;
use Ggs\JsonApiEngine\Executor\Write\WriteNotFoundException;
use Ggs\JsonApiEngine\Executor\Write\WriterBuilder;
use Ggs\JsonApiEngine\Parser\Write\KeepOperation;
use Ggs\JsonApiEngine\Parser\Write\PersistentIdentity;
use Ggs\JsonApiEngine\Parser\Write\WriteDocumentParser;
use Ggs\JsonApiEngine\Parser\Write\WriteOperation;
use Ggs\JsonApiEngine\Plan\Delete\DeleteForeignDataNode;
use Ggs\JsonApiEngine\Plan\Insert\InsertForeignDataNode;
use Ggs\JsonApiEngine\Plan\Update\UpdateForeignDataNode;
use Ggs\JsonApiEngine\Plan\Write\KeepForeignDataNode;
use Ggs\JsonApiEngine\Plan\Write\WriteJoinNode;
use Ggs\JsonApiEngine\Plan\Write\WriteOperationNode;
use Ggs\JsonApiEngine\Plan\Write\WritePlanner;
use Ggs\JsonApiEngine\Plan\Write\WriteRootNode;
use Ggs\JsonApiEngine\Test\Plan\Write\Fixture\NotedCertificateResource;
use Ggs\JsonApiEngine\Test\Plan\Write\Fixture\ReadOnlyScoreResource;
use Ggs\JsonApiEngine\Test\Plan\Write\Fixture\WritableNoteResource;
use Ggs\JsonApiEngine\Test\Plan\Write\Fixture\WritableNotesSource;
use LogicException;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../example/db/connection.php';

/**
 * Запись в чужой источник (`Model\ForeignDataSource`): вид источника решает план — ветви места получают узлы
 * `…ForeignData…`, `WriterBuilder` отображает их в исполнителей, которые вызывают методы источника и передают ему
 * аргументы маршрута. Ошибки описания (источник не умеет операцию, полная замена детей в чужом источнике, вложенный
 * маршрут) — `LogicException` при построении плана.
 */
final class ForeignDataWriteTest extends TestCase
{
    public function testPlanUsesForeignDataNodes(): void
    {
        $add = self::getPlace(roots: self::getInsert())->add;
        self::assertNotNull($add);
        self::assertInstanceOf(InsertForeignDataNode::class, $add->input);
        self::assertSame(WritableNotesSource::class, $add->input->source);

        $update = self::getPlace(roots: [self::getUpdate(id: '1')])->update;
        self::assertNotNull($update);
        self::assertInstanceOf(UpdateForeignDataNode::class, $update->input);

        $remove = self::getPlace(roots: [new WriteDocumentParser()->delete(resource: WritableNoteResource::class, id: '1')])->remove;
        self::assertInstanceOf(DeleteForeignDataNode::class, $remove);

        $keep = self::getPlace(roots: [self::getKeep(id: 1)])->keep;
        self::assertInstanceOf(KeepForeignDataNode::class, $keep);
        self::assertSame(WritableNotesSource::class, $keep->source);
    }

    public function testChildrenInForeignDataUnderEntityParent(): void
    {
        $roots = [new WriteDocumentParser()->update(
            resource: NotedCertificateResource::class,
            id: '1',
            document: [
                'data' => [
                    'type' => 'certificate',
                    'id' => '1',
                    'relationships' => ['notes' => ['data' => [['type' => 'note', 'lid' => 'n1', 'meta' => ['op' => 'add']]]]],
                ],
                'included' => [['type' => 'note', 'lid' => 'n1', 'attributes' => ['text' => 'new']]],
            ],
        )];
        $plan = new WritePlanner()->plan(resource: NotedCertificateResource::class, roots: $roots)->input;

        self::assertInstanceOf(WriteJoinNode::class, $plan);
        self::assertInstanceOf(WriteOperationNode::class, $plan->child);
        self::assertInstanceOf(InsertForeignDataNode::class, $plan->child->add?->input);

        $source = new WritableNotesSource();
        $pdo = getPDO();
        $pdo->beginTransaction();
        try {
            $this->expectErrorLog();
            self::getBuilder(source: $source)->getRoot(plan: new WritePlanner()->plan(resource: NotedCertificateResource::class, roots: $roots), data: $roots)->execute();
        } finally {
            $pdo->rollBack();
        }

        // Колонку связи с родителем источник получает в строке вставки
        self::assertSame([WritableNotesSource::CERTIFICATE_ID => 1, WritableNotesSource::TEXT => 'new', WritableNotesSource::ID => 3], $source->rows[3]);
    }

    public function testWritersCallForeignDataWithRouteArguments(): void
    {
        $source = new WritableNotesSource();
        $arguments = ['tenant' => 7];

        $roots = self::getInsert();
        $writer = self::getBuilder(source: $source)->getRoot(plan: self::getPlan(roots: $roots), data: $roots, arguments: $arguments);
        self::assertStringContainsString(json_encode(InsertForeignDataWriter::class), (string) json_encode($writer->explain()));
        self::assertSame([[WritableNotesSource::ID => 3]], $writer->execute());
        self::assertSame('new', $source->rows[3][WritableNotesSource::TEXT]);

        $roots = [self::getUpdate(id: '1')];
        $writer = self::getBuilder(source: $source)->getRoot(plan: self::getPlan(roots: $roots), data: $roots, arguments: $arguments);
        self::assertStringContainsString(json_encode(UpdateForeignDataWriter::class), (string) json_encode($writer->explain()));
        $writer->execute();
        self::assertSame('changed', $source->rows[1][WritableNotesSource::TEXT]);

        $roots = [self::getKeep(id: 2)];
        $writer = self::getBuilder(source: $source)->getRoot(plan: self::getPlan(roots: $roots), data: $roots, arguments: $arguments);
        self::assertStringContainsString(json_encode(KeepForeignDataWriter::class), (string) json_encode($writer->explain()));
        self::assertSame([[WritableNotesSource::ID => 2]], $writer->execute());

        $roots = [new WriteDocumentParser()->delete(resource: WritableNoteResource::class, id: '2')];
        $writer = self::getBuilder(source: $source)->getRoot(plan: self::getPlan(roots: $roots), data: $roots, arguments: $arguments);
        self::assertStringContainsString(json_encode(DeleteForeignDataWriter::class), (string) json_encode($writer->explain()));
        $writer->execute();
        self::assertArrayNotHasKey(2, $source->rows);

        self::assertSame(['insert', 'update', 'update', 'delete'], array_column($source->calls, 'method'));
        self::assertSame([$arguments, $arguments, $arguments, $arguments], array_column($source->calls, 'arguments'));
    }

    public function testMissingRowInForeignDataIsNotFound(): void
    {
        $roots = [self::getUpdate(id: '99')];
        $writer = self::getBuilder(source: new WritableNotesSource())->getRoot(plan: self::getPlan(roots: $roots), data: $roots);

        $this->expectException(WriteNotFoundException::class);
        $writer->execute();
    }

    public function testSourceWithoutOperationIsRejectedAtPlanTime(): void
    {
        $roots = new WriteDocumentParser()->insert(
            resource: ReadOnlyScoreResource::class,
            document: ['data' => ['type' => 'score', 'lid' => 's1', 'attributes' => ['score' => 5]]],
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('does not implement');
        new WritePlanner()->plan(resource: ReadOnlyScoreResource::class, roots: $roots);
    }

    public function testFullReplacementOfForeignDataChildrenIsRejected(): void
    {
        $roots = [new WriteDocumentParser()->update(
            resource: NotedCertificateResource::class,
            id: '1',
            document: [
                'data' => [
                    'type' => 'certificate',
                    'id' => '1',
                    'relationships' => ['notes' => ['data' => [['type' => 'note', 'id' => '1']]]],
                ],
            ],
        )];

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('full replacement of children kept in a foreign data source');
        new WritePlanner()->plan(resource: NotedCertificateResource::class, roots: $roots);
    }

    public function testRouteParentWithForeignDataIsRejected(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('route arguments go to the source as is');
        new WritePlanner()->plan(
            resource: WritableNoteResource::class,
            roots: self::getInsert(),
            parent: NotedCertificateResource::class,
            relationship: NotedCertificateResource::NOTES,
        );
    }

    /**
     * @return list<WriteOperation>
     */
    private static function getInsert(): array
    {
        return new WriteDocumentParser()->insert(
            resource: WritableNoteResource::class,
            document: ['data' => ['type' => 'note', 'lid' => 'n1', 'attributes' => ['text' => 'new']]],
        );
    }

    private static function getUpdate(string $id): WriteOperation
    {
        return new WriteDocumentParser()->update(
            resource: WritableNoteResource::class,
            id: $id,
            document: ['data' => ['type' => 'note', 'id' => $id, 'attributes' => ['text' => 'changed']]],
        );
    }

    private static function getKeep(int $id): KeepOperation
    {
        return new KeepOperation(
            resource: WritableNoteResource::class,
            identity: new PersistentIdentity(
                id: $id,
            ),
        );
    }

    /**
     * @param list<WriteOperation> $roots
     */
    private static function getPlan(array $roots): WriteRootNode
    {
        return new WritePlanner()->plan(resource: WritableNoteResource::class, roots: $roots);
    }

    /**
     * @param list<WriteOperation> $roots
     */
    private static function getPlace(array $roots): WriteOperationNode
    {
        $place = self::getPlan(roots: $roots)->input;
        self::assertInstanceOf(WriteOperationNode::class, $place);

        return $place;
    }

    private static function getBuilder(WritableNotesSource $source): WriterBuilder
    {
        return new WriterBuilder(
            pdo: getPDO(),
            lock: false,
            container: new ArrayContainer(
                services: [
                    $source,
                    new WritableNoteResource(),
                    new NotedCertificateResource(),
                ],
            ),
        );
    }
}
