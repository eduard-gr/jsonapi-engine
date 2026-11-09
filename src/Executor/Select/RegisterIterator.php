<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor\Select;

use Generator;
use Ggs\JsonApiEngine\Document\ResourceRecord;
use Ggs\JsonApiEngine\Document\ResourceRegistry;
use Ggs\JsonApiEngine\Executor\PullExecutor;

/**
 * Регистрирует ресурсы потока в реестре выполнения: каждый ресурс проходит через ResourceRegistry::intern(), и дальше
 * идёт зарегистрированный объект. Если ресурс с тем же type:id уже встречался, наружу уходит прежний объект, а только
 * что построенный отбрасывается: один ресурс существует в одном экземпляре на всё выполнение, и Document находит его
 * в реестре при сборке included. Сам реестр не перебирает — пропускает через него поток.
 *
 * Поток: вход — ?ResourceRecord (SerializeIterator по плану или ManualSerializeIterator в ручном дереве) → выход —
 * ?ResourceRecord из реестра; null проходит как есть (ресурса в строке нет; потоки одной строки остаются синхронными:
 * по элементу на строку).
 *
 * Стоит над каждым сериализатором: его ставит IteratorBuilder, в ручных деревьях (example/document.php,
 * DocumentTreeTest) — тот, кто собирает дерево. Ресурс без регистрации до included не дойдёт: Document ищет
 * связанные ресурсы только в реестре.
 *
 * Сериализация выполняется на каждой строке: повторный ресурс сначала строится, а потом отбрасывается — реестр
 * экономит память документа, но не работу сериализации. Реестр один на выполнение — тот же экземпляр получают все
 * RegisterIterator дерева и Document. Узел ленивый, в explain() реестр не описывается: это состояние выполнения,
 * а не топология.
 */
final readonly class RegisterIterator implements PullExecutor
{
    /**
     * @param PullExecutor     $source   поток ресурсов сериализатора
     * @param ResourceRegistry $registry реестр выполнения — один на всё дерево и Document
     */
    public function __construct(
        private PullExecutor $source,
        private ResourceRegistry $registry,
    ) {}

    #[\Override]
    public function explain(): array
    {
        return [
            'node' => static::class,
            'source' => $this->source->explain(),
        ];
    }

    /**
     * @return Generator<int, ?ResourceRecord>
     */
    #[\Override]
    public function getIterator(): Generator
    {
        foreach ($this->source as $record) {
            // Дальше идёт зарегистрированный объект: при повторном type:id это не $record
            yield $record === null ? null : $this->registry->intern($record);
        }
    }
}
