<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Executor\Select;

use ArrayIterator;
use Generator;
use Ggs\JsonApiEngine\Document\ResourceRecord;
use Ggs\JsonApiEngine\Executor\PullExecutorInterface;
use Ggs\JsonApiEngine\Executor\Select\HashNestJoinReader;
use Ggs\JsonApiEngine\Executor\Select\MergeNestJoinReader;
use PHPUnit\Framework\TestCase;

/**
 * Ребёнок без родителя в HashNestJoinReader и MergeNestJoinReader пропускается: связь ему записать некому, родители
 * выходят все, со своими детьми. Источник потоков не важен (чужой источник условий родителя не получает; база может
 * измениться между двумя запросами). Пустые элементы null тоже пропускаются.
 */
final class NestJoinParentTest extends TestCase
{
    public function testHashNestJoinSkipsChildWithoutParent(): void
    {
        $parent = self::getParent(id: 1);
        $join = new HashNestJoinReader(
            outer: self::getSource(items: [$parent]),
            inner: self::getSource(items: [self::getChild(id: 10, parent: 1), self::getChild(id: 20, parent: 2)]),
            outer_key: 'id',
            inner_key: 'parent_id',
            name: 'children',
        );

        self::assertSame([$parent], iterator_to_array($join, false));
        self::assertSame(['data' => [['id' => '10', 'type' => 'child']]], $parent->jsonSerialize()['relationships']['children']);
    }

    public function testMergeNestJoinSkipsChildBeforeItsParent(): void
    {
        $parent = self::getParent(id: 2);
        $join = new MergeNestJoinReader(
            outer: self::getSource(items: [$parent]),
            inner: self::getSource(items: [self::getChild(id: 10, parent: 1), self::getChild(id: 20, parent: 2)]),
            outer_key: 'id',
            inner_key: 'parent_id',
            name: 'children',
        );

        self::assertSame([$parent], iterator_to_array($join, false));
        self::assertSame(['data' => [['id' => '20', 'type' => 'child']]], $parent->jsonSerialize()['relationships']['children']);
    }

    public function testMergeNestJoinSkipsChildAfterLastParent(): void
    {
        $parent = self::getParent(id: 1);
        $join = new MergeNestJoinReader(
            outer: self::getSource(items: [$parent]),
            inner: self::getSource(items: [self::getChild(id: 10, parent: 1), null, self::getChild(id: 30, parent: 3)]),
            outer_key: 'id',
            inner_key: 'parent_id',
            name: 'children',
        );

        self::assertSame([$parent], iterator_to_array($join, false));
        self::assertSame(['data' => [['id' => '10', 'type' => 'child']]], $parent->jsonSerialize()['relationships']['children']);
    }

    public function testNullsAreNotChildrenWithoutParent(): void
    {
        $parent = self::getParent(id: 1);
        $merge = new MergeNestJoinReader(
            outer: self::getSource(items: [$parent]),
            inner: self::getSource(items: [null, self::getChild(id: 10, parent: 1), null]),
            outer_key: 'id',
            inner_key: 'parent_id',
            name: 'children',
        );

        self::assertSame([$parent], iterator_to_array($merge, false));
        self::assertSame(['data' => [['id' => '10', 'type' => 'child']]], $parent->jsonSerialize()['relationships']['children']);
    }

    private static function getParent(int $id): ResourceRecord
    {
        return new ResourceRecord(id: $id, type: 'parent', attributes: [], relationships: []);
    }

    private static function getChild(int $id, int $parent): ResourceRecord
    {
        return new ResourceRecord(id: $id, type: 'child', attributes: [], relationships: [], keys: ['parent_id' => $parent]);
    }

    /**
     * @param list<?ResourceRecord> $items
     */
    private static function getSource(array $items): PullExecutorInterface
    {
        return new class ($items) implements PullExecutorInterface {
            /** @param list<?ResourceRecord> $items */
            public function __construct(private array $items) {}

            public function explain(): array
            {
                return ['node' => 'items'];
            }

            public function getIterator(): Generator
            {
                yield from new ArrayIterator($this->items);
            }
        };
    }
}
