<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor\Select;

use Generator;
use Ggs\JsonApiEngine\Document\ResourceRecord;
use Ggs\JsonApiEngine\Executor\PullExecutorInterface;
use UnexpectedValueException;

/**
 * Проверяет, что поток ресурсов упорядочен по ключу по возрастанию; сам поток не меняет. Ставится на оба входа
 * MergeNestJoinReader: слиянию нужен порядок, который дают ORDER BY запросов (HashToMergeRule занимает поток
 * под Merge, EntityScanNodeDraft::withSelect() строит ORDER BY). Без проверки неупорядоченный поток молча дал бы неполные
 * связи, а не ошибку.
 *
 * Поток: вход — ?ResourceRecord → выход — тот же поток, элемент в элемент; null проходит без проверки.
 * Ключ берётся через ResourceRecord::getKey() (значения целые, 'id' — id ресурса). Ключи не должны убывать,
 * повторы допустимы: это вход inner слияния — у одного главного ресурса сколько угодно связанных.
 *
 * Строгий порядок — наследник `UniqueAscendingKeyReader`: повтор ключа тоже нарушение (вход outer слияния, каждый
 * главный ресурс один раз). Какой из двух узлов поставить, решает тот, кто строит дерево, по роли входа; в
 * explain() узлы различаются своим классом. Наследник заменяет одно сравнение (`isOutOfOrder()`) и название
 * порядка в тексте ошибки (`ORDER`).
 *
 * Проверка идёт на лету, при переходе к элементу: всё, что было до нарушения, уже ушло потребителю, и исключение
 * прерывает выполнение посреди потока. Узел ставит ReaderBuilder; в ручном дереве его ставит тот, кто собирает
 * дерево — если его нет, MergeNestJoinReader порядок не проверяет.
 *
 * Ленивый, состояние — только ключ предыдущего элемента.
 */
readonly class AscendingKeyReader implements PullExecutorInterface
{
    /** Название порядка в тексте ошибки */
    protected const string ORDER = 'ascending';

    /**
     * @param PullExecutorInterface $source поток ресурсов
     * @param string       $key    имя ключа (ResourceRecord::getKey()), по которому поток должен быть упорядочен
     */
    public function __construct(
        private PullExecutorInterface $source,
        private string $key,
    ) {}

    #[\Override]
    public function explain(): array
    {
        return [
            'node' => static::class,
            'key' => $this->key,
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
                if ($previous_key !== null && $this->isOutOfOrder(key: $key, previous_key: $previous_key)) {
                    throw new UnexpectedValueException(
                        "Stream must be sorted by {$this->key} " . static::ORDER . ": {$key} after {$previous_key}",
                    );
                }
                $previous_key = $key;
            }

            yield $record;
        }
    }

    /**
     * Нарушен ли порядок: ключ меньше предыдущего. Наследник заменяет сравнение.
     */
    protected function isOutOfOrder(int $key, int $previous_key): bool
    {
        return $key < $previous_key;
    }
}
