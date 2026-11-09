<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Select;

use Ggs\JsonApiEngine\Resource;
use sad_spirit\pg_builder\Select;

/**
 * Владелец SQL-запроса с собранным запросом: SharedScanNode после шага sql. Его ставит BuildSqlPass вместо каждого
 * SharedScanNode (SharedScanNode::withSelect()), когда дерево уже окончательное. Исполнитель — Executor\Select:
 * SqlScanIterator с запросом и параметрами, над ним поток строки (SerializeIterator или SharedDataIterator
 * + SharedScanIterator), см. IteratorBuilder.
 *
 * Отдельный узел, а не nullable-поля SharedScanNode: запрос, карта колонок и параметры появляются вместе и только
 * на шаге sql, поэтому у SqlScanNode они обязательны, а «план без шага sql» виден по типу — в нём остались
 * SharedScanNode, и IteratorBuilder и SelectPlanPrinter отказываются их выполнять или печатать.
 *
 * Ровно один вход — верх строки, как у SharedScanNode: SharedRowJoinNode (ресурс с 1-1) или ResourceNode (один
 * ресурс). Входы веток 1-1 ресурсов внутри строки (inner) — тоже уже SqlScanNode. После шага sql дерево
 * не переписывается: Rule на SqlScanNode бросает исключение.
 *
 * Иммутабелен, без методов with…(): копии не нужны, шагов после sql нет.
 *
 * @psalm-type RowResource = array{
 *     resource: class-string<Resource>,
 *     alias: string,
 *     columns: array<string, string>,
 *     fields: list<string>,
 *     keys: list<string>,
 * }
 * @psalm-type RowColumns = list<RowResource>
 * @psalm-type QueryParameters = array<string, int|float|string|bool>
 */
final readonly class SqlScanNode
{
    /**
     * @param SharedRowJoinNode|ResourceNode $input      верх строки, как у SharedScanNode; самый левый ResourceNode —
     *                                                   ресурс-владелец
     * @param string|null                    $order      колонка, по которой поток упорядочен ради MergeNestJoinNode
     *                                                   (из SharedScanNode, её ставит HashToMergeRule); null — порядок
     *                                                   не занимали
     * @param Select                         $select     запрос владельца (pg_builder): WITH предков, FROM строки, WHERE,
     *                                                   ORDER BY
     * @param RowColumns                     $columns    всё, что нужно Executor\Select\SerializeIterator, чтобы собрать
     *                                                   ресурсы из строки запроса: по ресурсу строки в порядке обхода
     *                                                   слева направо (ресурс-владелец — первый) — класс ресурса, его
     *                                                   алиас в запросе (t0, t1, …), карта «колонка модели → имя
     *                                                   колонки в строке» (t1_buyer_email), запрошенные атрибуты
     *                                                   (fields узла) и колонки модели — ключи соединений (keys
     *                                                   ResourceRecord)
     * @param QueryParameters                $parameters значения именованных параметров select (:t0_status_0) по имени
     *                                                   без двоеточия, уже скаляры для PDO; без фильтров — пусто
     */
    public function __construct(
        public SharedRowJoinNode|ResourceNode $input,
        public ?string $order,
        public Select $select,
        public array $columns,
        public array $parameters,
    ) {}

    /**
     * Ресурс, которому принадлежит строка: самый левый ResourceNode (как SharedScanNode::rowOwner()).
     *
     * @throws \LogicException если слева в строке оказался не ResourceNode
     */
    public function rowOwner(): ResourceNode
    {
        $node = $this->input;
        while ($node instanceof SharedRowJoinNode) {
            $node = $node->outer;
        }
        if (!$node instanceof ResourceNode) {
            throw new \LogicException(message: 'Shared row must start with ResourceNode, got ' . $node::class);
        }

        return $node;
    }
}
