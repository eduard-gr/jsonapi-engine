<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Model;

use Ggs\JsonApiEngine\Operator;

/**
 * Чужой источник (`ForeignDataSource`) умеет читать: его ресурс в плане — лист `Plan\Select\ForeignDataScanNode`,
 * а `select()` вызывает `Executor\Select\ForeignDataScanReader`. Методы работают в колонках модели, не в атрибутах:
 * перевод «атрибут → колонка» делает план. `$arguments` — аргументы маршрута как есть
 * (`/certificates/3/notes` → `['certificate_id' => 3]`); что с ними делать, решает источник.
 *
 * Чего движок проверить не может: что условия и сортировка клиента действительно применены и
 * что первичный ключ уникален. Ориентация из `getReadOrientation()` проверяется там, где на неё опирается
 * план: на входах соединения слиянием стоит `AscendingKeyReader`, нарушение — `LogicException`.
 */
interface SelectForeignDataInterface
{
    /**
     * Ориентация чтения: колонки, по возрастанию которых источник отдаёт строки `select()` (от старшей к младшей),
     * когда клиент сортировку не задал; null — ориентации нет, порядок строк не обещан. Метод статический: его
     * читает планировщик, а объекта источника при планировании нет.
     *
     * По ней план выбирает соединение слиянием (`Plan\Select\HashToMergeRule`): ключ связи должен быть первой колонкой
     * ориентации, иначе остаётся Hash. Движок ориентацию у источника не запрашивает — источник читает в ней сам.
     * С сортировкой клиента она не действует (и слияние не выбирается).
     *
     * @return ?non-empty-list<string>
     */
    public static function getReadOrientation(): ?array;

    /**
     * Строки источника.
     *
     * @param list<string>                                                  $columns    колонки, которые нужны движку;
     *                                                                                  лишние в строке не мешают
     * @param list<array{column: string, operator: Operator, value: mixed}> $conditions условия клиента, все через И;
     *                                                                                  `Operator::Ne` обязан находить и
     *                                                                                  строки с пустым значением (как
     *                                                                                  `IS DISTINCT FROM` у сущностей)
     * @param list<array{column: string, descending: bool}>                 $order      сортировка клиента; пусто —
     *                                                                                  ориентация из
     *                                                                                  `getReadOrientation()`
     * @param array<string, mixed>                                          $arguments аргументы маршрута
     * @return iterable<array<string, mixed>> колонка → значение
     */
    public function select(array $columns, array $conditions, array $order, array $arguments): iterable;
}
