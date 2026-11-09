<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Executor\Select;

use ArrayIterator;
use Generator;
use Ggs\JsonApiEngine\Document\ResourceRecord;
use Ggs\JsonApiEngine\Executor\PullExecutor;
use Ggs\JsonApiEngine\Executor\Select\AscendingKeyIterator;
use Ggs\JsonApiEngine\Executor\Select\MergeNestJoinIterator;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * AscendingKeyIterator: строгий порядок (outer слияния) и неубывание (inner), null проходит, поток не меняется;
 * на входе MergeNestJoinIterator нарушение порядка — исключение, а не молча неполные связи.
 */
final class AscendingKeyIteratorTest extends TestCase
{
    public function testOrderedStreamPassesUnchanged(): void
    {
        $items = [self::child(id: 10, parent: 1), null, self::child(id: 11, parent: 1), self::child(id: 20, parent: 2)];

        $records = iterator_to_array(
            new AscendingKeyIterator(
                source: self::source(items: $items),
                key: 'parent_id',
                strict: false,
            ),
            false,
        );

        self::assertSame($items, $records);
    }

    public function testStrictRejectsRepeatedKey(): void
    {
        $iterator = new AscendingKeyIterator(
            source: self::source(items: [self::child(id: 10, parent: 1), self::child(id: 11, parent: 1)]),
            key: 'parent_id',
            strict: true,
        );

        $this->expectException(UnexpectedValueException::class);
        iterator_to_array($iterator, false);
    }

    public function testNonStrictRejectsDescendingKey(): void
    {
        $iterator = new AscendingKeyIterator(
            source: self::source(items: [self::child(id: 20, parent: 2), null, self::child(id: 10, parent: 1)]),
            key: 'parent_id',
            strict: false,
        );

        $this->expectException(UnexpectedValueException::class);
        iterator_to_array($iterator, false);
    }

    public function testUnsortedOuterOfMergeFails(): void
    {
        $merge = new MergeNestJoinIterator(
            outer: new AscendingKeyIterator(
                source: self::source(items: [self::parent(id: 2), self::parent(id: 1)]),
                key: 'id',
                strict: true,
            ),
            inner: new AscendingKeyIterator(
                source: self::source(items: []),
                key: 'parent_id',
                strict: false,
            ),
            outer_key: 'id',
            inner_key: 'parent_id',
            name: 'children',
        );

        $this->expectException(UnexpectedValueException::class);
        iterator_to_array($merge, false);
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
