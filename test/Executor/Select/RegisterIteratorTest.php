<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Executor\Select;

use ArrayIterator;
use Generator;
use Ggs\JsonApiEngine\Document\ResourceRecord;
use Ggs\JsonApiEngine\Document\ResourceRegistry;
use Ggs\JsonApiEngine\Executor\PullExecutor;
use Ggs\JsonApiEngine\Executor\Select\RegisterIterator;
use PHPUnit\Framework\TestCase;

/**
 * RegisterIterator: повторный type:id даёт зарегистрированный объект, null проходит, ресурс попадает в реестр.
 */
final class RegisterIteratorTest extends TestCase
{
    public function testRepeatedResourceIsTheRegisteredObject(): void
    {
        $first = new ResourceRecord(id: 1, type: 'provider', attributes: ['name' => 'A'], relationships: []);
        $repeated = new ResourceRecord(id: 1, type: 'provider', attributes: ['name' => 'A'], relationships: []);
        $other = new ResourceRecord(id: 2, type: 'provider', attributes: ['name' => 'B'], relationships: []);
        $registry = new ResourceRegistry();

        $records = iterator_to_array(
            new RegisterIterator(
                source: self::source(items: [$first, null, $repeated, $other]),
                registry: $registry,
            ),
            false,
        );

        // По элементу на вход: null остаётся на своём месте, потоки одной строки не сдвигаются
        self::assertCount(4, $records);
        self::assertNull($records[1]);
        // Повторный type:id — тот же объект, что был отдан первым; построенный заново отброшен
        self::assertSame($first, $records[0]);
        self::assertSame($first, $records[2]);
        self::assertSame($other, $records[3]);
        // Ресурсы в реестре: по нему Document соберёт included
        self::assertSame($first, $registry->find('provider:1'));
        self::assertSame($other, $registry->find('provider:2'));
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
