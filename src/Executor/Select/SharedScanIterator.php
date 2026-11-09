<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor\Select;

use Generator;
use Ggs\JsonApiEngine\Document\ResourceRecord;
use Ggs\JsonApiEngine\Executor\PullExecutor;
use UnexpectedValueException;

/**
 * Корень поддерева, читающего общий источник: прогоняет поддерево и сдвигает SharedDataIterator.
 *
 * Поток: вход — главные ресурсы поддерева, ResourceRecord (обычно от SharedRowJoinIterator)
 * → выход — то же самое, один ресурс на строку общего источника.
 *
 * Все ветви поддерева читают одну и ту же строку через SharedDataIterator, а сдвинуть её сами не могут.
 * Этот узел забирает из поддерева главный ресурс, отдаёт его наружу и только потом переходит
 * к следующей строке источника — один шаг поддерева на одну строку.
 *
 * Снаружи это обычный узел: отдаёт поток главных ресурсов и может стоять внутри другого дерева
 * (например, как поток главных ресурсов $source в PreloadIterator, который в свою очередь стоит
 * в $outer HashNestJoinIterator). Синхронизация ветвей полностью спрятана внутри,
 * внешний управляющий цикл не нужен.
 *
 * Поддерево и SharedDataIterator передаются уже собранными, поэтому их может строить планировщик:
 * один экземпляр SharedDataIterator подставляется во все SerializeIterator поддерева и сюда.
 *
 * Ограничение: поддерево должно отдавать ровно один главный ресурс на строку источника.
 * Если оно отдаст два ресурса на строку, источник сдвинется дважды и строка будет пропущена —
 * этот случай узел не обнаруживает. Обратный случай (поддерево закончилось раньше источника)
 * проверяется в конце getIterator().
 */
class SharedScanIterator implements PullExecutor
{
    /**
     * @param SharedDataIterator $data Общий источник, который читают ветви $source
     * @param PullExecutor $source Поддерево, отдающее по одному главному ResourceRecord на строку
     */
    public function __construct(
        private SharedDataIterator $data,
        private PullExecutor           $source,
    ) {}

    #[\Override]
    public function explain(): array
    {
        return [
            'node' => static::class,
            'data' => $this->data->explain(),
            'source' => $this->source->explain(),
        ];
    }

    /**
     * @return Generator<int, ResourceRecord> То же, что отдаёт поддерево
     * @throws UnexpectedValueException если поддерево закончилось раньше источника
     */
    #[\Override]
    public function getIterator(): Generator
    {
        // Первую строку сдвигать не нужно: foreach запускает поддерево вызовом rewind(),
        // и все ветви лениво читают строку, на которой стоит источник, — то есть первую
        foreach ($this->source as $record) {
            yield $record;

            /**
             * Сдвигаем источник именно здесь — между yield и следующим шагом foreach:
             *  - к моменту yield все ветви уже прочитали текущую строку: $record собран из неё;
             *  - после возврата из yield потребитель закончил работу с $record;
             *  - следующий шаг foreach вызовет next() у поддерева, и ветви прочитают уже новую строку.
             * После последней строки источник станет невалидным, ветви завершатся, и foreach закончится.
             */
            $this->data->advance();
        }

        // Поддерево закончилось, а в источнике ещё есть строки — они бы молча потерялись
        if ($this->data->valid()) {
            throw new UnexpectedValueException('Flow finished before shared data source');
        }
    }
}
