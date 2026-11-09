<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor\Select;

use Generator;
use Ggs\JsonApiEngine\Document\ResourceRecord;
use Ggs\JsonApiEngine\Executor\PullExecutor;
use UnexpectedValueException;

/**
 * Проверяет, что поток ресурсов упорядочен по ключу по возрастанию; сам поток не меняет. Ставится на оба входа
 * MergeNestJoinIterator: слиянию нужен порядок, который дают ORDER BY запросов (HashToMergeRule занимает поток
 * под Merge, SharedScanNode::withSelect() строит ORDER BY). Без проверки неупорядоченный поток молча дал бы неполные
 * связи, а не ошибку.
 *
 * Поток: вход — ?ResourceRecord → выход — тот же поток, элемент в элемент; null проходит без проверки.
 * Ключ берётся через ResourceRecord::getKey() (значения целые, 'id' — id ресурса).
 *  - strict: true — ключи строго возрастают: outer слияния, каждый главный ресурс один раз;
 *  - strict: false — ключи не убывают: inner слияния, у одного главного сколько угодно связанных.
 *
 * Проверка идёт на лету, при переходе к элементу: всё, что было до нарушения, уже ушло потребителю, и исключение
 * прерывает выполнение посреди потока. Узел ставит IteratorBuilder; в ручном дереве его ставит тот, кто собирает
 * дерево — если его нет, MergeNestJoinIterator порядок не проверяет.
 *
 * Ленивый, состояние — только ключ предыдущего элемента.
 */
final readonly class AscendingKeyIterator implements PullExecutor
{
    /**
     * @param PullExecutor $source поток ресурсов
     * @param string       $key    имя ключа (ResourceRecord::getKey()), по которому поток должен быть упорядочен
     * @param bool         $strict true — строго по возрастанию, false — по неубыванию
     */
    public function __construct(
        private PullExecutor $source,
        private string $key,
        private bool $strict,
    ) {}

    #[\Override]
    public function explain(): array
    {
        return [
            'node' => static::class,
            'key' => $this->key,
            'strict' => $this->strict,
            'source' => $this->source->explain(),
        ];
    }

    /**
     * @return Generator<int, ?ResourceRecord>
     * @throws UnexpectedValueException на первом ключе не по порядку
     */
    #[\Override]
    public function getIterator(): Generator
    {
        $previous_key = null;
        foreach ($this->source as $record) {
            if ($record !== null) {
                $key = $record->getKey($this->key);
                $out_of_order = $previous_key !== null
                    && ($this->strict ? $key <= $previous_key : $key < $previous_key);
                if ($out_of_order) {
                    $order = $this->strict ? 'strictly ascending' : 'ascending';
                    throw new UnexpectedValueException(
                        "Stream must be sorted by {$this->key} {$order}: {$key} after {$previous_key}",
                    );
                }
                $previous_key = $key;
            }

            yield $record;
        }
    }
}
