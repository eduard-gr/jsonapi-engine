<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor\Write;

use Ggs\JsonApiEngine\Executor\PushExecutorInterface;
use Ggs\JsonApiEngine\Model\DatabaseEntitySource;
use Ggs\JsonApiEngine\Model\ModelTree;
use Ggs\JsonApiEngine\Parser\Write\KeepOperation;
use Ggs\JsonApiEngine\Parser\Write\WriteOperation;
use Ggs\JsonApiEngine\Resource;
use LogicException;
use PDO;
use sad_spirit\pg_builder\Select;
use sad_spirit\pg_builder\nodes\ColumnReference;
use sad_spirit\pg_builder\nodes\QualifiedName;
use sad_spirit\pg_builder\nodes\TargetElement;
use sad_spirit\pg_builder\nodes\expressions\NamedParameter;
use sad_spirit\pg_builder\nodes\expressions\OperatorExpression;
use sad_spirit\pg_builder\nodes\lists\TargetList;
use sad_spirit\pg_builder\nodes\range\RelationReference;

/**
 * Только ищет ресурс: ничего не меняет. Ветвь `keep` места (`WriteOperationWriter`), строит `WriterBuilder` по
 * `KeepEntityNode`. Исполняет `KeepOperation`; другая операция — LogicException. Пара к `UpdateResourceWriter`
 * (тот меняет) и `DeleteEntityWriter` (тот удаляет); как и удаление, работает с главной таблицей сам, без уровня
 * таблиц.
 *
 * Поток: `KeepOperation` (`identity->id`) + `$link` → `SELECT <pk> FROM <главная таблица> WHERE <pk> = :key_id AND
 * <колонки связи>` → `[pk => id]`; пусто — ресурса нет или он принадлежит другому родителю (наверху это 404).
 *
 * Колонки связи от родителя (`$link`) — условие принадлежности: чужой ребёнок не найдётся. Если первичный ключ
 * ресурса и есть колонка связи (`delivery.certificate_id`), условия два на одну колонку: id, не равный родителю,
 * не найдётся.
 *
 * Читается только главная (крайняя левая) таблица модели: остальные таблицы ресурса из нескольких таблиц не
 * проверяются и ключей не дают. Детям этого достаточно: связь по владению держится за первичный ключ владельца,
 * а он в главной таблице.
 *
 * Ограничение: колонка связи должна лежать в главной таблице, иначе принадлежность одним запросом не проверить.
 * Узел этого не проверяет — проверил план: `WritePlanner` для ветви `keep` требует колонку главной таблицы
 * (LogicException при построении плана). Строка не блокируется.
 */
final readonly class KeepEntityWriter implements PushExecutorInterface
{
    /**
     * @param class-string<Resource> $resource
     */
    public function __construct(
        private PDO $pdo,
        private string $resource,
    ) {}

    #[\Override]
    public function push(WriteOperation $operation, array $link = []): array
    {
        if (!$operation instanceof KeepOperation) {
            throw new LogicException("{$this->resource}: keep needs KeepOperation, got " . $operation::class);
        }
        $entity = $this->getEntity();
        $primary = $this->resource::getPrimaryKeyColumnName();
        $statement = new Select(new TargetList([new TargetElement(new ColumnReference($entity::getColumn($primary)))]));
        $statement->from[] = new RelationReference(new QualifiedName($entity::TABLE));
        $statement->where->and(new OperatorExpression(
            '=',
            new ColumnReference($entity::getColumn($primary)),
            new NamedParameter('key_id'),
        ));
        $parameters = ['key_id' => $operation->identity->id];
        // Колонки связи от родителя — условие принадлежности: чужой ребёнок не найдётся
        foreach ($link as $column => $value) {
            $statement->where->and(new OperatorExpression(
                '=',
                new ColumnReference($entity::getColumn($column)),
                new NamedParameter("link_{$column}"),
            ));
            $parameters["link_{$column}"] = $value;
        }

        return StatementRunner::run(pdo: $this->pdo, statement: $statement, parameters: $parameters) === []
            ? []
            : [$primary => $operation->identity->id];
    }

    /**
     * Главная (крайняя левая) таблица модели ресурса: в ней ищется строка ресурса.
     *
     * @return class-string<DatabaseEntitySource>
     */
    private function getEntity(): string
    {
        return ModelTree::of($this->resource)->getRootEntity()
            ?? throw new LogicException("{$this->resource}: invalid model");
    }

    #[\Override]
    public function explain(): array
    {
        return ['node' => static::class, 'resource' => $this->resource, 'table' => $this->getEntity()::TABLE];
    }
}
