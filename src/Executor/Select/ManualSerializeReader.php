<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor\Select;

use Ggs\JsonApiEngine\Document\ResourceRecord;
use Ggs\JsonApiEngine\Plan\Select\EntityScanNode;
use Ggs\JsonApiEngine\Resource\SerializeInterface;

/**
 * Ручная сериализация: превращает строки источника в ресурсы JSON:API методом `serialize()` самого ресурса.
 * Наследник `SerializeReader`: тот собирает ресурс по описанию (`ATTRIBUTES`), этот отдаёт строку ресурсу.
 *
 * Поток: вход — строки array (EntityScanReader или EntitySharedWindowReader) → выход — ?ResourceRecord, по одному на
 * строку, как у родителя. От родителя — запись колонок, проверка первичного ключа, ключи соединений, поток и
 * explain() (узел описывается своим классом); заменены подготовка и сборка ресурса из строки.
 *
 * Когда ставится: ресурс реализует `Resource\SerializeInterface` (метод `serialize(array $tuple): ?ResourceRecord`).
 * `ReaderBuilder` проверяет это по классу ресурса из плана и ставит этот узел вместо `SerializeReader`; дерево,
 * собранное руками, выбирает узел само. Ресурс без интерфейса — LogicException в конструкторе.
 *
 * Что делает на каждой строке:
 *  - первичный ключ NULL — ресурса в строке нет (LEFT JOIN): метод не вызывается, отдаётся null;
 *  - собирает `$tuple` — «колонка модели → значение» для всех колонок ресурса в строке: первичный ключ, колонки
 *    атрибутов fields, колонки `Resource::getRequiredColumns()` и ключи соединений. Алиасов запроса (t0_…) метод не
 *    видит; колонки других ресурсов той же строки в `$tuple` не попадают. Значения — как пришли из источника, без
 *    приведения по type;
 *  - вызывает `serialize($tuple, $fields)` на экземпляре ресурса (ему доступны зависимости конструктора). id, type, атрибуты
 *    и связи по внешнему ключу — целиком на методе: тип и набор атрибутов могут зависеть от строки (полиморфный
 *    ресурс, example/polymorphic-document.php);
 *  - метод вернул null — ресурса в строке нет; иначе узел дописывает в ResourceRecord ключи соединений (keys),
 *    как родитель: метод о них не заботится.
 *
 * Вторым аргументом метод получает fields — запрошенные атрибуты и связи по внешнему ключу в порядке запроса: по
 * ним, а не по наличию колонки в `$tuple`, он решает, что выводить (колонка бывает в строке и как ключ соединения).
 * Колонки, которые нужны методу всегда, ресурс называет в `getRequiredColumns()` — иначе запрос плана их не выберет.
 *
 * Тихий отказ: метод, который читает колонку, не выбранную запросом, получит ошибку PHP «Undefined array key»,
 * а не LogicException; метод, который игнорирует fields, отдаст атрибуты, которых клиент не просил; метод, который
 * на каждой строке ходит через свои зависимости в базу или сеть, — запросы в обход плана (N+1), узел этого не видит.
 *
 * @psalm-import-type RowResource from EntityScanNode
 */
final class ManualSerializeReader extends SerializeReader
{
    /** Тот же ресурс, как реализация ручной сериализации */
    private SerializeInterface $manual;

    /** @var array<string, string> колонка модели → колонка в строке, все колонки ресурса: строка для `serialize()` */
    private array $map = [];

    /**
     * @psalm-param RowResource $columns
     * @throws \LogicException если ресурс не реализует `SerializeInterface`
     */
    #[\Override]
    protected function prepare(array $columns): void
    {
        $resource = $this->resource;
        if (!$resource instanceof SerializeInterface) {
            throw new \LogicException(
                $resource::class . ' does not implement ' . SerializeInterface::class . ': use ' . SerializeReader::class,
            );
        }
        $this->manual = $resource;
        $this->map = $columns['columns'];
    }

    /**
     * Ресурс из строки методом `serialize()` ресурса; null — первичный ключ NULL или так решил метод.
     *
     * @param array<string, mixed> $row
     */
    #[\Override]
    protected function serialize(array $row): ?ResourceRecord
    {
        if ($row[$this->primary] === null) {
            return null;
        }

        $tuple = [];
        foreach ($this->map as $model_column => $row_column) {
            $tuple[$model_column] = $row[$row_column];
        }
        $record = $this->manual->serialize($tuple, $this->fields);
        if ($record === null) {
            return null;
        }
        foreach ($this->getKeys(row: $row) as $key => $value) {
            $record->setKey(name: $key, value: $value);
        }

        return $record;
    }
}
