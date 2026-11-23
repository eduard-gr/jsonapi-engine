<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor\Write;

use PDO;
use sad_spirit\pg_builder\SqlBuilderWalker;
use sad_spirit\pg_builder\Statement;

/**
 * Выполнение запроса pg_builder для исполнителей записи: общий шаг `UpdateEntityWriter`, `DeleteEntityWriter`,
 * `EntityReplaceWriter` и `EntityRouteParentWriter`.
 *
 * Поток: запрос pg_builder + именованные параметры → текст SQL → `prepare()`, `bindValue()` с типом по PHP (как в
 * `EntityScanReader`: `execute($array)` отправил бы `false` как `''`) → строки результата. Выполненный запрос с
 * параметрами и результатом пишется в `error_log()` (отладка, как у `InsertEntityWriter`).
 *
 * Ошибки базы (внешний ключ, `NOT NULL`, уникальность) — исключения PDO как есть. TODO: `prepare()` на каждый вызов,
 * кеша подготовленных запросов нет.
 */
final class StatementRunner
{
    /**
     * @param array<string, mixed> $parameters имя параметра без двоеточия → значение
     * @return list<array<string, mixed>> строки результата (у DELETE/UPDATE без RETURNING — пусто)
     */
    public static function run(PDO $pdo, Statement $statement, array $parameters): array
    {
        $sql = (string) preg_replace(
            pattern: '/\s+/',
            replacement: ' ',
            subject: $statement->dispatch(new SqlBuilderWalker()),
        );
        $prepared = $pdo->prepare($sql);
        foreach ($parameters as $name => $value) {
            $type = PDO::PARAM_STR;
            if (is_int($value)) {
                $type = PDO::PARAM_INT;
            }
            if (is_bool($value)) {
                $type = PDO::PARAM_BOOL;
            }
            if ($value === null) {
                $type = PDO::PARAM_NULL;
            }
            $prepared->bindValue(':' . $name, $value, $type);
        }
        $prepared->execute();
        /** @var list<array<string, mixed>> $rows */
        $rows = $prepared->columnCount() > 0 ? $prepared->fetchAll(PDO::FETCH_ASSOC) : [];
        $result = $prepared->columnCount() > 0 ? (string) json_encode($rows) : "{$prepared->rowCount()} rows";
        error_log("{$sql}\n    " . (string) json_encode($parameters) . " → {$result}");

        return $rows;
    }
}
