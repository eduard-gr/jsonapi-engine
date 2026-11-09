<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor\Select;

use Generator;
use Ggs\JsonApiEngine\Document\ResourceRecord;
use Ggs\JsonApiEngine\Document\ResourceRegistry;
use Ggs\JsonApiEngine\Executor\PullExecutor;
use Ggs\JsonApiEngine\Resource;

/**
 * Превращает строки источника в ресурсы JSON:API ресурсом самим — без плана — и регистрирует их в реестре.
 *
 * Поток: вход — строки array (SqlScanIterator или SharedDataIterator) → выход — ?ResourceRecord;
 * для повторного type:id — тот же объект, что был отдан раньше.
 *
 * Для деревьев, собранных руками без плана (example/document.php, DocumentTreeTest): каждая строка уходит в
 * написанный ресурсом статический `$resource::serialize($tuple)`, который читает её по алиасам своего запроса
 * (`QUERY1`–`QUERY4` примера) и возвращает ResourceRecord или null, если ресурса в строке нет. Базовый
 * `Resource::serialize()` бросает исключение: ресурс без своего метода так не собрать. По плану ресурс собирает
 * SerializeIterator — по описанию ресурса и записи SqlScanNode::columns.
 *
 * Как и SerializeIterator: отдаёт по элементу на каждую строку (в том числе null — потоки одного источника остаются
 * синхронными), каждый ресурс проходит через ResourceRegistry::intern() (один объект на type:id за выполнение),
 * узел ленивый. В explain() описывается классом ресурса.
 */
final readonly class ManualSerializeIterator implements PullExecutor
{
    /**
     * @param PullExecutor           $source   Строки источника (ассоциативные массивы): SqlScanIterator или SharedDataIterator
     * @param class-string<Resource> $resource Класс ресурса со своим `serialize()`
     * @param ResourceRegistry       $registry Реестр выполнения — один на всё дерево и Document
     */
    public function __construct(
        private PullExecutor $source,
        private string $resource,
        private ResourceRegistry $registry,
    ) {}

    #[\Override]
    public function explain(): array
    {
        return [
            'node' => static::class,
            'resource' => $this->resource,
            'source' => $this->source->explain(),
        ];
    }

    /**
     * @return Generator<int, ?ResourceRecord>
     */
    #[\Override]
    public function getIterator(): Generator
    {
        foreach ($this->source as $tuple) {
            $record = $this->resource::serialize($tuple);

            // Дальше идёт зарегистрированный объект: при повторном type:id это не $record
            yield $record === null ? null : $this->registry->intern($record);
        }
    }
}
