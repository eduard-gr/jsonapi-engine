<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor\Select;

use Generator;
use Ggs\JsonApiEngine\Document\ResourceRecord;
use Ggs\JsonApiEngine\Executor\PullExecutor;
use OutOfBoundsException;
use UnexpectedValueException;

/**
 * Пересортировывает результат соединения по другому ключу — по возрастанию целого числа.
 *
 * Поток: вход — ResourceRecord (только узел соединения: JoinIterator) → выход — те же ResourceRecord
 * в новом порядке.
 *
 * Назначение — подготовить поток для MergeNestJoinIterator, когда он пришёл отсортированным не по тому
 * ключу. Например, выход domains ← logs идёт по domain id, а для соединения с сертификатами
 * нужен порядок по certificate_id: SortIterator(ключ certificate_id) ставится между ними.
 *
 * Ограничения входа:
 *  - только узел соединения (JoinIterator) — проверяется типом параметра при сборке дерева;
 *  - только ResourceRecord в потоке — проверяется при выполнении, иначе исключение;
 *  - ключ — только служебный ключ ресурса или 'id' (целое число по контракту
 *    ResourceRecord::getKey()); если его у ресурса нет — OutOfBoundsException при выполнении.
 *
 * Сортировка по возрастанию и устойчивая (usort в PHP 8 сохраняет исходный порядок равных
 * элементов): прежний порядок потока становится вторым ключом. Например, домены, пришедшие
 * по возрастанию id, после сортировки по certificate_id идут по (certificate_id, id) —
 * так и нужно MergeNestJoinIterator и связи сертификата.
 *
 * Ключ задаётся именем $key и берётся через ResourceRecord::getKey() — один раз на элемент,
 * а не при каждом сравнении: usort сравнивает O(n log n) раз.
 *
 * Узел блокирующий: читает вход целиком и только потом отдаёт первый элемент.
 * Для курсоров это плюс: курсоры входа закрываются раньше, чем узел начинает отдавать
 * поток, — как фаза build в HashNestJoinIterator.
 *
 * Ограничение: весь вход держится в памяти. Если вход приходит из базы, дешевле отсортировать
 * его в SQL (ORDER BY); SortIterator нужен, когда порядок задаёт узел соединения, а не запрос.
 *
 * Выбор для планировщика: SortIterator + MergeNestJoinIterator держит в памяти всю сортируемую сторону,
 * а HashNestJoinIterator — только главную. Например, для certificate ← (domains ← logs) SortIterator держит
 * все домены, а HashNestJoinIterator — только сертификаты. Сортировка выгодна, когда
 * сортируемая сторона небольшая или её всё равно нужно держать целиком.
 *
 * SortIterator сам не JoinIterator: два SortIterator подряд не ставятся — второй всё равно задал бы
 * свой порядок, и первый был бы лишним.
 */
class SortIterator implements PullExecutor
{
    /**
     * @param JoinIterator $source Поток ResourceRecord от узла соединения
     * @param string $key Имя ключа сортировки (ResourceRecord::getKey()), например 'certificate_id'
     */
    public function __construct(
        private JoinIterator $source,
        private string   $key,
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
     * @return Generator<int, ResourceRecord>
     * @throws UnexpectedValueException если элемент входа не ResourceRecord
     * @throws OutOfBoundsException если у ресурса нет ключа $key
     */
    #[\Override]
    public function getIterator(): Generator
    {
        // [ключ, ресурс]: ключ вычисляется один раз, сравниваются уже готовые числа
        $rows = [];

        foreach ($this->source as $record) {
            if (!$record instanceof ResourceRecord) {
                throw new UnexpectedValueException(
                    'SortIterator accepts only ResourceRecord, ' . get_debug_type($record) . ' given'
                );
            }

            $rows[] = [$record->getKey($this->key), $record];
        }

        // Устойчивая сортировка: равные ключи сохраняют порядок входа
        usort($rows, static fn(array $left_row, array $right_row): int => $left_row[0] <=> $right_row[0]);

        foreach ($rows as [, $record]) {
            yield $record;
        }
    }
}
