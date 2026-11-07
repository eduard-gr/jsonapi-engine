<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor;

use Generator;
use PDO;
use PDOException;

/**
 * Лист дерева: выполняет SQL-запрос и отдаёт его результат построчно.
 *
 * Параметры запроса (именованные, :t0_status_0 — Plan\SharedScanNode::$parameters) привязываются через bindValue()
 * с типом по типу значения PHP: int — PARAM_INT, bool — PARAM_BOOL, остальное — PARAM_STR. Не через
 * execute($массив): он передаёт всё строками, и false стал бы '', который PostgreSQL не примет как boolean.
 *
 * Поток: вход — нет (лист) → выход — строки array.
 *
 * Каждая строка — ассоциативный массив, ключи — имена колонок или их алиасы из запроса
 * (например certificate_id, detail_id, meta_id в QUERY1). По этим ключам SerializeIterator
 * собирает ресурсы.
 *
 * Узел ленивый: запрос выполняется не в конструкторе и не при вызове getIterator(),
 * а при первом обращении к генератору (rewind()/valid()/current()). Строки читаются
 * из курсора по одной, по мере запроса потребителем.
 *
 * Будет ли весь результат лежать в памяти, зависит от драйвера PDO: SQLite читает строки
 * по одной, а MySQL по умолчанию буферизует весь результат на клиенте.
 *
 * Каждый вызов getIterator() выполняет запрос заново. Чтобы несколько ветвей читали
 * один результат, узел оборачивается в SharedDataIterator, который вызывает getIterator() один раз.
 */
class SqlScanIterator implements ExecutorIterator
{
    /**
     * @param PDO $pdo Подключение к базе
     * @param string $sql Запрос: подготавливается и выполняется как есть
     * @param array<string, int|float|string|bool> $parameters Значения именованных параметров запроса по имени
     *        без двоеточия; float передаётся строкой
     */
    public function __construct(
        private PDO $pdo,
        private string $sql,
        private array $parameters = [],
    )
    {

    }

    /**
     * Запрос записывается одной строкой: переводы строк и отступы схлопываются в пробел.
     * SQL-комментарии (-- …) остаются внутри строки, поэтому текст после них в описании
     * выглядит закомментированным, хотя в запросе это не так.
     * Параметры описываются, только если они есть.
     * Подключение не описывается: в нём нет ничего о топологии
     */
    #[\Override]
    public function explain(): array
    {
        $explain = [
            'node' => static::class,
            'sql' => preg_replace('/\s+/', ' ', trim($this->sql)),
        ];
        if ($this->parameters !== []) {
            $explain['parameters'] = $this->parameters;
        }

        return $explain;
    }

    /**
     * @return Generator<int, array<string, mixed>>
     * @throws PDOException если запрос не удалось выполнить (при PDO::ERRMODE_EXCEPTION)
     */
    #[\Override]
    public function getIterator(): Generator
    {
        $statement = $this->pdo->prepare($this->sql);
        foreach ($this->parameters as $name => $value) {
            $statement->bindValue(':' . $name, \is_float($value) ? (string) $value : $value, match (true) {
                \is_int($value) => PDO::PARAM_INT,
                \is_bool($value) => PDO::PARAM_BOOL,
                default => PDO::PARAM_STR,
            });
        }
        $statement->execute();

        try {
            // fetch() возвращает false, когда строки закончились; пустого массива быть не может,
            // потому что в строке результата всегда есть хотя бы одна колонка
            while ($tuple = $statement->fetch(\PDO::FETCH_ASSOC)) {
                yield $tuple;
            }
        } finally {
            // Явно закрываем курсор, когда генератор завершается: строки закончились, внутри
            // случилось исключение или генератор уничтожен. Пока курсор открыт, MySQL
            // в небуферизованном режиме не даст выполнить другой запрос на этом подключении.
            // Если потребитель вышел из foreach раньше (break), finally выполнится не сразу,
            // а только когда генератор будет уничтожен (unset или выход из области видимости)
            $statement->closeCursor();
        }
    }
}
