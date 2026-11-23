<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Executor\Select;

use ArrayIterator;
use Generator;
use Ggs\JsonApiEngine\Document\ResourceRecord;
use Ggs\JsonApiEngine\Executor\PullExecutorInterface;
use Ggs\JsonApiEngine\Executor\Select\AscendingKeyReader;
use Ggs\JsonApiEngine\Executor\Select\MergeNestJoinReader;
use Ggs\JsonApiEngine\Executor\Select\UniqueAscendingKeyReader;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * AscendingKeyReader (неубывание, inner слияния) и его наследник UniqueAscendingKeyReader (строгий порядок,
 * outer): null проходит, поток не меняется;
 * на входе MergeNestJoinReader нарушение порядка — исключение, а не молча неполные связи.
 */
final class AscendingKeyReaderTest extends TestCase
{
    public function testOrderedStreamPassesUnchanged(): void
    {
        $items = [self::getChild(id: 10, parent: 1), null, self::getChild(id: 11, parent: 1), self::getChild(id: 20, parent: 2)];

        $records = iterator_to_array(
            new AscendingKeyReader(
                source: self::getSource(items: $items),
                key: 'parent_id',
            ),
            false,
        );

        self::assertSame($items, $records);
    }

    public function testStrictRejectsRepeatedKey(): void
    {
        $reader = new UniqueAscendingKeyReader(
            source: self::getSource(items: [self::getChild(id: 10, parent: 1), self::getChild(id: 11, parent: 1)]),
            key: 'parent_id',
        );

        $this->expectException(UnexpectedValueException::class);
        iterator_to_array($reader, false);
    }

    public function testNonStrictRejectsDescendingKey(): void
    {
        $reader = new AscendingKeyReader(
            source: self::getSource(items: [self::getChild(id: 20, parent: 2), null, self::getChild(id: 10, parent: 1)]),
            key: 'parent_id',
        );

        $this->expectException(UnexpectedValueException::class);
        iterator_to_array($reader, false);
    }

    public function testUnsortedOuterOfMergeFails(): void
    {
        $merge = new MergeNestJoinReader(
            outer: new UniqueAscendingKeyReader(
                source: self::getSource(items: [self::getParent(id: 2), self::getParent(id: 1)]),
                key: 'id',
            ),
            inner: new AscendingKeyReader(
                source: self::getSource(items: []),
                key: 'parent_id',
            ),
            outer_key: 'id',
            inner_key: 'parent_id',
            name: 'children',
        );

        $this->expectException(UnexpectedValueException::class);
        iterator_to_array($merge, false);
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
