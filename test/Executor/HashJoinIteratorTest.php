<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Executor;

use ArrayIterator;
use Generator;
use Ggs\JsonApiEngine\Document\ResourceRecord;
use Ggs\JsonApiEngine\Executor\ExecutorIterator;
use Ggs\JsonApiEngine\Executor\HashJoinIterator;
use PHPUnit\Framework\TestCase;

/**
 * HashJoinIterator: на каждый элемент outer — ровно один элемент в том же порядке, null проходит как null,
 * связанные — по ключу в порядке inner, ресурс без связанных получает пустую связь.
 */
final class HashJoinIteratorTest extends TestCase
{
    public function testKeepsRowCountAndWritesRelationship(): void
    {
        $first = new ResourceRecord(id: 1, type: 'delivery', attributes: [], relationships: []);
        $second = new ResourceRecord(id: 2, type: 'delivery', attributes: [], relationships: []);
        $log = static fn (int $id, int $delivery): ResourceRecord
            => new ResourceRecord(id: $id, type: 'log', attributes: [], relationships: [], keys: ['delivery_id' => $delivery]);

        $join = new HashJoinIterator(
            outer: self::source(items: [$first, null, $second]),
            inner: self::source(items: [$log(12, 1), null, $log(10, 1), $log(30, 3)]),
            outer_key: 'id',
            inner_key: 'delivery_id',
            name: 'logs',
        );

        self::assertSame([$first, null, $second], iterator_to_array($join, false));
        self::assertSame(
            ['data' => [['id' => '12', 'type' => 'log'], ['id' => '10', 'type' => 'log']]],
            $first->jsonSerialize()['relationships']['logs'],
        );
        self::assertSame(['data' => []], $second->jsonSerialize()['relationships']['logs']);
    }

    /**
     * @param list<?ResourceRecord> $items
     */
    private static function source(array $items): ExecutorIterator
    {
        return new class ($items) implements ExecutorIterator {
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
