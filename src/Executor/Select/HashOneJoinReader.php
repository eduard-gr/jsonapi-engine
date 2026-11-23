<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor\Select;

use Generator;
use Ggs\JsonApiEngine\Document\ResourceRecord;
use Ggs\JsonApiEngine\Executor\PullExecutorInterface;
use UnexpectedValueException;

/**
 * Связь 1:1 между потоками: главные читаются целиком в хеш-таблицу, каждый получает `data: null`, затем связанные
 * находят своего главного по ключу. Связанный без главного пропускается. Узел плана — `Plan\Select\HashOneJoinNode`
 * (связь 1-1, одна из сторон которой — чужой источник); сам читатель от вида источника не зависит. Блокирующий:
 * `$outer` читается целиком до первого результата.
 *
 * Поток: `$outer`: ResourceRecord, `$inner`: ?ResourceRecord → ResourceRecord главного в порядке `$outer`.
 * Два связанных у одного главного — `UnexpectedValueException`.
 */
final class HashOneJoinReader implements JoinReaderInterface
{
    public function __construct(
        private PullExecutorInterface $outer,
        private PullExecutorInterface $inner,
        private string $outer_key,
        private string $inner_key,
        private string $name,
    ) {}

    #[\Override]
    public function explain(): array
    {
        return [
            'node' => static::class,
            'name' => $this->name,
            'outer' => $this->outer->explain(),
            'inner' => $this->inner->explain(),
        ];
    }

    #[\Override]
    public function getIterator(): Generator
    {
        /** @var array<int, ResourceRecord> $table */
        $table = [];
        foreach ($this->outer as $record) {
            $record->setRelationship(name: $this->name, related: null);
            $table[$record->getKey($this->outer_key)] = $record;
        }
        $linked = [];
        foreach ($this->inner as $related) {
            if ($related === null) {
                continue;
            }
            $key = $related->getKey($this->inner_key);
            if (!isset($table[$key])) {
                continue;
            }
            if (isset($linked[$key])) {
                throw new UnexpectedValueException("Second related resource for key {$key} in '{$this->name}'");
            }
            $linked[$key] = true;
            $table[$key]->setRelationship(name: $this->name, related: $related);
        }
        yield from array_values($table);
    }
}
