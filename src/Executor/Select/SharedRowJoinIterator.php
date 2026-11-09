<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor\Select;

use Generator;
use Ggs\JsonApiEngine\Document\ResourceRecord;
use Ggs\JsonApiEngine\Executor\PullExecutor;

/**
 * Записывает главному ресурсу to-one связь на связанный ресурс из той же строки (связь 1:1).
 *
 * Поток: вход — $outer: ResourceRecord, $inner: ?ResourceRecord
 * (обычно SerializeIterator на общем SharedDataIterator) → выход — ResourceRecord главного.
 *
 * Соединяет ресурсы, построенные из одной и той же общей строки (отсюда имя): Shared — узел
 * из семейства SharedDataIterator/SharedScanIterator, Row — соединяются ресурсы одной строки.
 * Механически это соединение по позиции, а не по ключу: n-й элемент $outer соединяется
 * с n-м элементом $inner попарно, как функция zip.
 * Для соединения по ключу (связь 1:N) — HashNestJoinIterator или MergeNestJoinIterator. Связь N:1
 * со справочником соединения не требует: её пишет сериализатор из внешнего ключа своей строки.
 *
 * ВАЖНО: узел работает только в связке с SharedScanIterator. Обе ветви ($outer и $inner)
 * должны читать один и тот же SharedDataIterator, а сам узел — стоять внутри поддерева
 * SharedScanIterator, который сдвигает этот источник. Только так гарантируется, что n-е элементы
 * обеих ветвей построены из одной строки. Узел это не проверяет, нарушение не даёт ошибки:
 *  - без SharedScanIterator источник не сдвигается, и узел бесконечно отдаёт ресурсы из первой строки;
 *  - если ветви читают разные источники, пары соединяются по порядку строк: при разном количестве
 *    или порядке строк ресурсы молча свяжутся неправильно, а часть пропадёт.
 *
 * Стороны: $outer — главные ресурсы (certificate), $inner — связанные (detail или delivery).
 *
 * Главному ресурсу в relationships[$name].data записывается {id, type} связанного ресурса,
 * или null, если для этой строки связанного ресурса нет (связь to-one). Сам связанный ресурс
 * никуда не копируется: в included его соберёт Document по этой связи.
 *
 * Объект одноразовый: генераторы получаются в конструкторе, поэтому повторный вызов
 * getIterator() не выбросит ошибку, а молча вернёт пустой поток — генераторы уже исчерпаны.
 * Узлы сторон при этом хранятся отдельно, поэтому explain() работает всегда.
 */
class SharedRowJoinIterator implements JoinIterator
{
    /**
     * Поток главных ресурсов, которые обогащаются связью
     */
    public Generator $outer;

    /**
     * Поток связанных ресурсов, на которые ставится ссылка
     */
    public Generator $inner;

    /**
     * Узлы сторон — только для explain(): генераторы не показывают, какой узел за ними стоит
     */
    private readonly PullExecutor $outer_node;
    private readonly PullExecutor $inner_node;

    /**
     * Потоки читаются вручную через valid()/current()/next(), поэтому нужен Generator —
     * его гарантирует контракт PullExecutor::getIterator(), отдельная проверка не нужна.
     *
     * Ленивость контракт не гарантирует: если getIterator() узла сам вычитывает поток
     * (например, через iterator_to_array) до того, как вернуть генератор, при чтении через
     * SharedDataIterator он не завершится вовсе — источник не сдвигается, и такой узел бесконечно
     * получает первую строку, пока не кончится память.
     *
     * @param PullExecutor $outer Поток главных ресурсов
     * @param PullExecutor $inner Поток связанных ресурсов
     * @param string $name Имя связи в relationships главного ресурса
     */
    public function __construct(
        PullExecutor $outer,
        PullExecutor $inner,
        private string $name,
    )
    {
        $this->outer_node = $outer;
        $this->inner_node = $inner;
        $this->outer = $outer->getIterator();
        $this->inner = $inner->getIterator();
    }

    /**
     * Описывает узлы сторон, а не генераторы: генераторы не трогаются
     */
    #[\Override]
    public function explain(): array
    {
        return [
            'node' => static::class,
            'name' => $this->name,
            'outer' => $this->outer_node->explain(),
            'inner' => $this->inner_node->explain(),
        ];
    }

    /**
     * @return Generator<int, ResourceRecord>
     */
    #[\Override]
    public function getIterator(): Generator
    {
        /**
         * Мы знаем что класс SharedRowJoinIterator используется для обогащения главного ресурса
         * на основе общего потока данных, это значит, что количество прочитанных данных с $outer и $inner
         * одинаковое, потому что они пришли из одного селекта, поэтому мы можем их читать в одном while
         *
         * Первый вызов valid() запускает генераторы и строит ресурсы из первой строки источника
         */
        while ($this->outer->valid() && $this->inner->valid()) {
            $outer = $this->outer->current();

            // Связь записываем всегда: если связанного ресурса нет (null),
            // получится 'data' => null, и клиент увидит, что связь пустая, а не что её не загрузили
            $outer->setRelationship(
                name: $this->name,
                related: $this->inner->current()
            );

            yield $outer;

            /**
             * Сдвигаем оба потока только после yield: к этому моменту SharedScanIterator уже
             * сдвинул общий источник, и оба потока построят ресурсы из следующей строки.
             * Без этих вызовов генераторы стоят на месте и цикл бесконечно отдаёт один и тот же ресурс
             */
            $this->outer->next();
            $this->inner->next();
        }
    }
}
