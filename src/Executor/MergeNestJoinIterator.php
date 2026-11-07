<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor;

use Generator;
use Ggs\JsonApiEngine\Document\ResourceRecord;
use UnexpectedValueException;

/**
 * Соединяет главные ресурсы со связанными по ключу за один совместный проход двух
 * отсортированных потоков и записывает связь to-many (1:N).
 *
 * Название: Merge — механизм, слияние двух отсортированных потоков; nest join — форма результата.
 * Классический merge join отдаёт плоские пары (главный, связанный), повторяя главный на каждого
 * связанного и теряя главные без совпадений. Этот узел отдаёт каждый главный один раз, с вложенным
 * списком всех связанных (возможно, пустым) — как HashNestJoinIterator, только без хеш-таблицы.
 *
 * Поток: вход — $outer: ResourceRecord (SharedScanIterator, SerializeIterator
 * или PreloadIterator над ними — он сохраняет порядок),
 * $inner: ?ResourceRecord (SerializeIterator, другой узел соединения
 * или SortIterator над ним) → выход — ResourceRecord главного.
 *
 * Семантика та же, что у HashNestJoinIterator: $outer — главные ресурсы, $inner — связанные;
 * связанный ресурс записывается в связь relationships[$name] главного (сам он никуда
 * не копируется — в included его соберёт Document); главный ресурс без совпадений остаётся
 * с пустой связью data = [] (LEFT JOIN). Ключи задаются именами $outer_key и $inner_key
 * и берутся через ResourceRecord::getKey(), значения бывают только целыми.
 *
 * Связанные без главного — пока молча пропускаются, как и в HashNestJoinIterator; позже это станет
 * исключением LogicException (код уже написан и закомментирован), потому что дочерний SQL
 * обязан повторять условия родителя. Таких случаев два:
 *  - ключ связанного меньше ключа текущего главного: его главный уже пройден или его нет;
 *  - $outer закончился, а в $inner остались связанные. Пока они не дочитываются; когда проверка
 *    включится, узел будет дочитывать $inner до конца, и его курсор закроется позже.
 *
 * Требование: оба потока отсортированы по ключу соединения по возрастанию (ORDER BY в запросах):
 *  - $outer — строго по возрастанию, ключ главного ресурса уникален;
 *  - $inner — по неубыванию, у одного главного может быть сколько угодно связанных.
 * Узел проверяет порядок на лету и выбрасывает исключение при нарушении — иначе неотсортированный
 * поток молча дал бы неполные связи.
 *
 * Вложенные соединения: если $inner — другой узел соединения, его выход идёт в порядке его $outer.
 * Поэтому один поток обычно не подходит для двух MergeNestJoinIterator с разными ключами. Например,
 * в certificate ← domains ← logs домены для соединения с логами нужны по возрастанию id,
 * а для соединения с сертификатами — по возрастанию certificate_id; совпадать эти порядки
 * не обязаны. Варианты:
 *  - MergeNestJoinIterator на одном уровне, HashNestJoinIterator (которому порядок не важен) на другом.
 *    Так сделано сейчас: domains ← logs соединяет MergeNestJoinIterator (оба запроса отсортированы
 *    по domain id), certificate ← domains — HashNestJoinIterator;
 *  - MergeNestJoinIterator на обоих уровнях, а между ними SortIterator, который переупорядочивает выход
 *    внутреннего соединения по ключу внешнего (например, по certificate_id). Это дороже
 *    по памяти: SortIterator держит всю сортируемую сторону.
 *
 * Алгоритм: потоки идут навстречу друг другу, как при слиянии отсортированных списков.
 * Для очередного главного ресурса с ключом K узел читает $inner, пока ключ связанного не больше K:
 * меньше K — связанному нет главного, он пропускается; равно K — присоединяется;
 * больше K — относится к следующим главным, чтение $inner останавливается до следующего шага.
 *
 * Чем отличается от HashNestJoinIterator:
 *  - не блокирует: главный ресурс выходит наружу, как только прочитаны его связанные,
 *    не дожидаясь конца потоков;
 *  - не строит хеш-таблицу: сам узел держит только текущий главный ресурс. Все ресурсы
 *    при этом всё равно остаются в реестре до конца выполнения, поэтому общая память
 *    выполнения от выбора узла почти не зависит;
 *  - но требует отсортированных потоков и держит открытыми оба курсора одновременно.
 *
 * ВАЖНО для планировщика: курсоры $outer и $inner открыты одновременно на всё время прохода
 * (в отличие от HashNestJoinIterator, который читает стороны по очереди). На одном подключении
 * в небуферизованном режиме MySQL так нельзя — нужен буферизованный режим (по умолчанию
 * в PDO MySQL) или отдельные подключения. SQLite одновременные курсоры допускает.
 *
 * Порядок: главные ресурсы выходят в порядке $outer, связанные внутри связи — в порядке $inner.
 */
class MergeNestJoinIterator implements JoinIterator
{
    /**
     * @param ExecutorIterator $outer Поток главных ResourceRecord, отсортированный по $outer_key
     *        строго по возрастанию
     * @param ExecutorIterator $inner Поток связанных ?ResourceRecord, отсортированный по $inner_key
     *        по неубыванию; элементы null пропускаются
     * @param string $outer_key Имя ключа соединения главного ресурса (ResourceRecord::getKey()),
     *        обычно 'id'; значение уникально в $outer
     * @param string $inner_key Имя ключа соединения связанного ресурса — служебный ключ
     *        с внешним ключом, например 'certificate_id'
     * @param string $name Имя связи в relationships главного ресурса
     */
    public function __construct(
        private ExecutorIterator    $outer,
        private ExecutorIterator    $inner,
        private string  $outer_key,
        private string  $inner_key,
        private string  $name,
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
     * @return Generator<int, ResourceRecord>
     * @throws UnexpectedValueException если $outer не строго возрастает по ключу
     *         или $inner убывает по ключу
     */
    #[\Override]
    public function getIterator(): Generator
    {
        // $inner читается вручную: на каждом шаге $outer из него берётся ровно столько,
        // сколько относится к текущему главному ресурсу
        $inner = $this->inner->getIterator();
        $previousOuterKey = null;
        $previousInnerKey = null;

        foreach ($this->outer as $record) {
            $key = $record->getKey($this->outer_key);

            if ($previousOuterKey !== null && $key <= $previousOuterKey) {
                throw new UnexpectedValueException(
                    "Outer stream of MergeNestJoinIterator '{$this->name}' must be sorted by key strictly ascending: "
                    . "{$key} after {$previousOuterKey}"
                );
            }
            $previousOuterKey = $key;

            // LEFT JOIN: связь есть всегда, пустая — если связанных не найдётся
            $record->setRelationships(name: $this->name, related: []);

            while ($inner->valid()) {
                $related = $inner->current();

                if ($related === null) {
                    $inner->next();
                    continue;
                }

                $relatedKey = $related->getKey($this->inner_key);

                if ($previousInnerKey !== null && $relatedKey < $previousInnerKey) {
                    throw new UnexpectedValueException(
                        "Inner stream of MergeNestJoinIterator '{$this->name}' must be sorted by key ascending: "
                        . "{$relatedKey} after {$previousInnerKey}"
                    );
                }
                $previousInnerKey = $relatedKey;

                // Связанный относится к одному из следующих главных — оставляем его в $inner
                // (не сдвигаем) и отдаём текущий главный ресурс
                if ($relatedKey > $key) {
                    break;
                }

                if ($relatedKey === $key) {
                    $record->addRelationship(
                        name: $this->name,
                        related: $related
                    );
                } else {
                    // Ключ меньше текущего главного: главные идут по возрастанию, и главного
                    // с таким ключом нет. Пока связанный пропускается; позже — ошибка плана
                    // throw new \LogicException(
                    //     "Related resource {$related->key()} has no parent with key {$relatedKey} in MergeNestJoinIterator '{$this->name}'"
                    // );
                }

                $inner->next();
            }

            yield $record;
        }

        // $outer закончился: оставшимся в $inner связанным заведомо нет главного.
        // Пока они не дочитываются; позже — ошибка плана (пустые элементы null ошибкой не считаются)
        // while ($inner->valid()) {
        //     $related = $inner->current();
        //     if ($related !== null) {
        //         throw new \LogicException(
        //             "Related resource {$related->key()} has no parent: outer stream of MergeNestJoinIterator '{$this->name}' is over"
        //         );
        //     }
        //     $inner->next();
        // }
    }
}
