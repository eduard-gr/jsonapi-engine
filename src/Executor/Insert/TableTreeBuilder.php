<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor\Insert;

use Ggs\JsonApiEngine\Plan\Insert\InsertPlanner;
use Ggs\JsonApiEngine\Resource;
use PDO;

/**
 * Поддерево вставки уровня таблиц для одного ресурса — для дерева уровня ресурсов, собранного руками
 * (`example/insert-document.php`, `test/Executor/Insert/InsertWriterTest.php`). Короткий путь к
 * `WriterBuilder::table(InsertPlanner::tables($resource))`: правила (порядок таблиц по `PRIMARY`/`references`,
 * необязательная сторона `LeftJoin`, проверка `Insertable`) описаны и живут в `Plan\Insert\InsertPlanner::tables()`.
 *
 * Поток: класс `Insertable`-ресурса → TableWriter. Ресурс без `Insertable` или с некорректной моделью —
 * `LogicException`. Дерево целиком (с уровнем ресурсов) по документу строит `InsertPlanner::plan()` +
 * `WriterBuilder`.
 */
final readonly class TableTreeBuilder
{
    public function __construct(
        private PDO $pdo,
    ) {}

    /**
     * @param class-string<Resource> $resource
     */
    public function build(string $resource): TableWriter
    {
        return new WriterBuilder(pdo: $this->pdo)->table(plan: new InsertPlanner()->tables(resource: $resource));
    }
}
