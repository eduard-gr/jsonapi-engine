<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Executor\Select;

use ArrayIterator;
use Generator;
use Ggs\JsonApiEngine\Document\ResourceRecord;
use Ggs\JsonApiEngine\Executor\PullExecutor;
use Ggs\JsonApiEngine\Executor\Select\HashNestJoinIterator;
use Ggs\JsonApiEngine\Executor\Select\MergeNestJoinIterator;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * Ребёнок без родителя в HashNestJoinIterator и MergeNestJoinIterator — LogicException: дочерний SQL повторяет
 * условия родителя (цепочка предков), поэтому такой ребёнок — ошибка плана. Пустые элементы null ошибкой не считаются.
 */
final class NestJoinParentTest extends TestCase
{
    public function testHashNestJoinFailsOnChildWithoutParent(): void
    {
        $join = new HashNestJoinIterator(
            outer: self::source(items: [self::parent(id: 1)]),
            inner: self::source(items: [self::child(id: 10, parent: 1), self::child(id: 20, parent: 2)]),
            outer_key: 'id',
            inner_key: 'parent_id',
            name: 'children',
        );

        $this->expectException(LogicException::class);
        iterator_to_array($join, false);
    }

    public function testMergeNestJoinFailsOnChildBeforeItsParent(): void
    {
        $join = new MergeNestJoinIterator(
            outer: self::source(items: [self::parent(id: 2)]),
            inner: self::source(items: [self::child(id: 10, parent: 1), self::child(id: 20, parent: 2)]),
            outer_key: 'id',
            inner_key: 'parent_id',
            name: 'children',
        );

        $this->expectException(LogicException::class);
        iterator_to_array($join, false);
    }

    public function testMergeNestJoinFailsOnChildAfterLastParent(): void
    {
        $join = new MergeNestJoinIterator(
            outer: self::source(items: [self::parent(id: 1)]),
            inner: self::source(items: [self::child(id: 10, parent: 1), null, self::child(id: 30, parent: 3)]),
            outer_key: 'id',
            inner_key: 'parent_id',
            name: 'children',
        );

        $this->expectException(LogicException::class);
        iterator_to_array($join, false);
    }

    public function testNullsAreNotChildrenWithoutParent(): void
    {
        $parent = self::parent(id: 1);
        $merge = new MergeNestJoinIterator(
            outer: self::source(items: [$parent]),
            inner: self::source(items: [null, self::child(id: 10, parent: 1), null]),
            outer_key: 'id',
            inner_key: 'parent_id',
            name: 'children',
        );

        self::assertSame([$parent], iterator_to_array($merge, false));
        self::assertSame(['data' => [['id' => '10', 'type' => 'child']]], $parent->jsonSerialize()['relationships']['children']);
    }

    private static function parent(int $id): ResourceRecord
    {
        return new ResourceRecord(id: $id, type: 'parent', attributes: [], relationships: []);
    }

    private static function child(int $id, int $parent): ResourceRecord
    {
        return new ResourceRecord(id: $id, type: 'child', attributes: [], relationships: [], keys: ['parent_id' => $parent]);
    }

    /**
     * @param list<?ResourceRecord> $items
     */
    private static function source(array $items): PullExecutor
    {
        return new class ($items) implements PullExecutor {
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
