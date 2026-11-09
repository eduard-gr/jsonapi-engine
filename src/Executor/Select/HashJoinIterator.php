<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor\Select;

use Ds\Map;
use Generator;
use Ggs\JsonApiEngine\Document\ResourceRecord;
use Ggs\JsonApiEngine\Executor\PullExecutor;
use UnexpectedValueException;

/**
 * Соединение 1:N внутри общей строки: записывает ресурсам потока $outer связь to-many со связанными из $inner.
 * Пара к Plan\Select\HashJoinNode — ветка 1-1 ресурса (logs у delivery), которая осталась внутри строки.
 *
 * Поток: вход — $outer: ?ResourceRecord (поток ресурса строки: SerializeIterator над SharedDataIterator или
 * другой узел строки), $inner: ?ResourceRecord (отдельный запрос — SerializeIterator, SharedScanIterator или
 * узел соединения) → выход — ?ResourceRecord: на каждый элемент $outer ровно один элемент, в том же порядке.
 *
 * Отличие от HashNestJoinIterator — сторона хеш-таблицы и синхронность:
 *  - хеш строится по $inner (связанным), а $outer идёт потоком. $outer — часть общей строки, которую читают
 *    несколько потоков одновременно (SharedDataIterator): её нельзя прочитать целиком заранее, иначе
 *    остальные потоки строки разойдутся с ней;
 *  - на каждый элемент $outer отдаётся ровно один элемент, null — как null (ресурса в строке нет, LEFT JOIN):
 *    SharedRowJoinIterator над ним рассчитывает на один элемент на строку.
 * Поэтому узел не JoinIterator: он отдаёт и null.
 *
 * Фазы:
 *  - build: при первом шаге $inner читается целиком в Ds\Map «ключ → список связанных» (в порядке $inner);
 *    null пропускаются. Это блокирующая часть: связанные одного ресурса строки могут прийти в любом месте $inner;
 *  - probe: каждый ресурс $outer получает связь relationships[$name] = связанные по его ключу или [] (LEFT JOIN:
 *    ресурс без связанных остаётся с пустой связью).
 *
 * Ключи — имена $outer_key (relation[0], колонка ресурса строки) и $inner_key (relation[1], колонка связанного),
 * значения — ResourceRecord::getKey().
 *
 * Не проверяется: что у каждого связанного есть ресурс в $outer. Связанные без него молча не попадают
 * в документ (как в HashNestJoinIterator, пока дочерний SQL не повторяет условия родителя). Если один ресурс
 * встретится в $outer дважды, связь запишется ему дважды с тем же списком.
 */
class HashJoinIterator implements PullExecutor
{
    /**
     * @param PullExecutor $outer     поток ресурса строки (связь пишется ему), с null
     * @param PullExecutor $inner     связанные ресурсы, отдельный запрос
     * @param string           $outer_key имя ключа ресурса строки (relation[0])
     * @param string           $inner_key имя ключа связанного (relation[1])
     * @param string           $name      имя связи
     */
    public function __construct(
        private PullExecutor $outer,
        private PullExecutor $inner,
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
            'outer_key' => $this->outer_key,
            'inner_key' => $this->inner_key,
            'outer' => $this->outer->explain(),
            'inner' => $this->inner->explain(),
        ];
    }

    /**
     * @return Generator<int, ?ResourceRecord>
     */
    #[\Override]
    public function getIterator(): Generator
    {
        $table = $this->build();

        foreach ($this->outer as $record) {
            if ($record !== null) {
                $record->setRelationships(name: $this->name, related: $table->get($record->getKey($this->outer_key), []));
            }

            // null — тоже элемент строки: число элементов должно совпадать с числом строк
            yield $record;
        }
    }

    /**
     * @return Map<int, list<ResourceRecord>> ключ связанного → связанные в порядке $inner
     */
    private function build(): Map
    {
        $table = new Map();
        foreach ($this->inner as $related) {
            if ($related === null) {
                continue;
            }
            if (!$related instanceof ResourceRecord) {
                throw new UnexpectedValueException(
                    "Inner stream of HashJoinIterator '{$this->name}' must yield ResourceRecord, " . get_debug_type($related) . ' given',
                );
            }
            $key = $related->getKey($this->inner_key);
            $list = $table->get($key, []);
            $list[] = $related;
            $table->put($key, $list);
        }

        return $table;
    }
}
