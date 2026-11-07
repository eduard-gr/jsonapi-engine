<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Select;

use Generator;
use Ggs\JsonApiEngine\Parser\QueryParser;
use Ggs\JsonApiEngine\Plan\HashNestJoinNode;
use Ggs\JsonApiEngine\Plan\JoinNode;
use Ggs\JsonApiEngine\Plan\Pass;
use Ggs\JsonApiEngine\Plan\PreloadNode;
use Ggs\JsonApiEngine\Plan\ResourceNode;
use Ggs\JsonApiEngine\Plan\SharedRowJoinNode;
use Ggs\JsonApiEngine\Plan\SharedScanNode;

/**
 * Планировщик select: дерево запроса (QueryParser) → дерево узлов плана → шаги преобразования → SQL.
 *
 * Поток: вход — дерево запроса (TreeResource), проверенное парсером → выход — дерево после каждого шага
 * (название шага => корень дерева); последнее ('sql') — план с Select у каждого владельца запроса.
 *
 * Принципы:
 *  1. Иммутабельные узлы + функциональный стиль. Свойства узлов readonly, «изменить» узел — значит
 *     получить новый через with…(). Не тронутые поддеревья переиспользуются как есть, поэтому результат
 *     каждого шага сохраняется и его можно показать (QueryPlanPrinter).
 *  2. Модель итераторов «Вулкан»: у каждого узла фиксированное число входов, и оно видно по классу:
 *       - ResourceNode   — 0 входов, таблица ресурса;
 *       - SharedScanNode — 1 вход: верх строки (SharedRowJoinNode или ResourceNode); владелец SQL-запроса;
 *       - JoinNode       — 2 входа, outer и inner (SharedRowJoinNode, PreloadNode,
 *                          HashNestJoinNode, HashJoinNode, MergeNestJoinNode).
 *  3. Ветка остаётся у своего ресурса. Ветка 1-1 ресурса (logs у delivery) не поднимается над строкой:
 *     её outer — поток самого ресурса внутри строки, поэтому ключ берётся и связь пишется именно ему.
 *     Внутри строки outer нельзя читать наперёд, и 1:N там становится HashJoinNode (хеш по inner).
 *  4. У каждого SQL-запроса ровно один владелец — SharedScanNode, даже если в запросе один ресурс
 *     (provider, domain, log). ResourceNode — всегда только таблица: порядок потока (order), запрос (select)
 *     и всё, что собирается в SQL, живёт у владельца. Запрос владелец собирает сам из своего поддерева
 *     (SharedScanNode::withSelect()).
 *
 * Вызовы своего кода — с именованными аргументами, кроме функций с переменным числом аргументов
 * (sprintf, array_diff): у них аргументы позиционные. Вызовы pg_builder — позиционные, чтобы имена
 * параметров библиотеки не становились частью нашего кода.
 *
 * Ошибки: дерево запроса проверил парсер, поэтому несоответствие здесь — ошибка кода, LogicException.
 *
 * @psalm-import-type TreeResource from QueryParser
 */
final class QueryPlanner
{
    /**
     * Ключи, обязательные у ресурса в дереве запроса. Проверяются в fromTree(), чтобы несоответствие
     * давало понятное исключение, а не предупреждение PHP и null в узле.
     */
    private const array TREE_RESOURCE_KEYS = ['resource', 'fields', 'filters', 'sort', 'includes'];

    /** Ключи, обязательные у include в дереве запроса */
    private const array TREE_INCLUDE_KEYS = ['type', 'relation', 'resource'];

    /**
     * @param TreeResource $tree дерево запроса от QueryParser
     * @return array<string, ResourceNode|SharedScanNode|JoinNode> название шага => дерево после него
     *                                                               ('nodes' — дерево сразу после fromTree())
     */
    public function plan(array $tree): array
    {
        // Дерево запроса → узлы; дальше только узлы
        $plan = $this->fromTree(input: $tree);

        // Результат каждого шага сохраняется: узлы иммутабельны, следующий шаг его не испортит
        $result = ['nodes' => $plan];
        foreach ($this->getSteps() as $name => $step) {
            $plan = $step(plan: $plan);
            $result[$name] = $plan;
        }

        return $result;
    }

    /**
     * Шаги по порядку: имя шага => Pass. Порядок важен — каждый шаг рассчитан на результат предыдущих.
     *
     * @return Generator<string, Pass>
     */
    private function getSteps(): Generator
    {
        // ветки владельца строки выходят из её outer наверх; ветки 1-1 ресурсов остаются у своего ресурса
        // внутри строки и в SharedScanPass становятся HashJoinNode
        yield 'lift' => new LiftBranchFromOuterRule();

        // PreloadNode под nest join
        yield 'order' => new PreloadBelowNestJoinRule();

        // Владелец SharedScanNode над каждым запросом; HashNestJoinNode внутри строки → HashJoinNode
        yield 'close' => new SharedScanPass();

        // Hash → Merge, где обе стороны можно упорядочить по ключу; после close — владельцы строк уже видны
        yield 'merge' => new HashToMergeRule();

        // Select у каждого владельца запроса
        yield 'sql' => new BuildSqlPass();
    }

    /**
     * Дерево запроса ресурса → узлы плана. Единственное место, где планировщик читает дерево запроса:
     * дальше все шаги работают только с узлами.
     *
     * Ресурс становится ResourceNode с полями, фильтрами и сортировкой из дерева. Каждый include становится
     * соединением класса по виду связи: ONE_TO_ONE — SharedRowJoinNode, MANY_TO_ONE — PreloadNode,
     * ONE_TO_MANY — HashNestJoinNode. Include навешиваются в порядке объявления: каждый следующий
     * становится новым корнем, а всё построенное до него — его outer. inner — связанный ресурс,
     * построенный этим же методом рекурсивно.
     *
     * Для сертификата с include provider, detail, delivery, domains получается левая цепочка:
     *   domains( delivery( detail( provider(certificate, provider), detail ), delivery… ), domain… )
     * Порядок из дерева здесь не исправляется: поднять ветки над строкой, закрыть строку и выбрать
     * алгоритм соединения — задачи следующих шагов (lift, order, close, merge).
     *
     * @param array $input узел дерева запроса: resource, fields, filters, sort, includes (имя => описание связи)
     * @return ResourceNode|JoinNode ResourceNode, если у ресурса нет include, иначе соединение последнего include
     * @throws \LogicException если в дереве нет обязательного ключа или вид связи неизвестен
     */
    private function fromTree(array $input): ResourceNode|JoinNode
    {
        // Сначала проверка: без неё отсутствующий ключ дал бы предупреждение PHP и null в узле
        $this->requireKeys(node: $input, keys: self::TREE_RESOURCE_KEYS, where: 'resource');

        // Лист: сам ресурс. Данные из дерева переходят как есть — без нормализации и без ключей связей,
        // их добавит SQL-шаг (см. описание ResourceNode)
        $node = new ResourceNode(
            resource: $input['resource'],
            fields: $input['fields'],
            filters: $input['filters'],
            sort: $input['sort'],
        );

        // $name — имя связи ('delivery'), $include — её описание: type, relation, resource.
        // Перебор в порядке объявления в дереве: от него зависит порядок узлов в исходном плане
        foreach ($input['includes'] as $name => $include) {
            $name = (string) $name;
            // Имя include в тексте исключения — чтобы было видно, какой именно include неполный
            $this->requireKeys(node: $include, keys: self::TREE_INCLUDE_KEYS, where: "include '{$name}'");

            // Класс соединения по виду связи. HashJoinNode и MergeNestJoinNode здесь не создаются:
            // их выбирают SharedScanPass и HashToMergeRule по месту узла в дереве
            $class = match ($include['type']) {
                QueryParser::ONE_TO_ONE => SharedRowJoinNode::class,
                QueryParser::MANY_TO_ONE => PreloadNode::class,
                QueryParser::ONE_TO_MANY => HashNestJoinNode::class,
                default => throw new \LogicException(
                    message: "Unknown relationship type '{$include['type']}' in include '{$name}'",
                ),
            };

            // Новый корень: outer — всё, что построено до этого include, inner — связанный ресурс.
            // inner строится рекурсивно, поэтому у связанного ресурса могут быть свои include любой глубины
            $node = new $class(
                name: $name,                        // имя include — имя связи в документе
                relation: $include['relation'],     // [колонка родителя, колонка ребёнка]
                outer: $node,
                inner: $this->fromTree(input: $include['resource']),
            );
        }

        // Корень поддерева ресурса: соединение последнего include или сам ResourceNode, если include нет
        return $node;
    }

    /**
     * Проверяет, что в узле дерева запроса есть все обязательные ключи.
     *
     * Проверяется только наличие ключа, а не значение: ключ со значением null или пустым массивом
     * считается присутствующим. Лишние ключи не мешают.
     *
     * Все отсутствующие ключи перечисляются в одном исключении, а не по одному. Например:
     * "Query tree include 'x' is missing keys: relation, resource".
     *
     * @param array    $node  узел дерева запроса: ресурс или include
     * @param string[] $keys  обязательные ключи (TREE_RESOURCE_KEYS или TREE_INCLUDE_KEYS)
     * @param string   $where что проверяется — для текста исключения: 'resource' или "include 'имя'"
     * @throws \LogicException с перечнем отсутствующих ключей
     */
    private function requireKeys(array $node, array $keys, string $where): void
    {
        // Обязательные ключи, которых нет среди ключей узла. array_diff принимает массивы через
        // ...$arrays — аргументы позиционные
        $missing = array_diff($keys, array_keys($node));

        if ($missing !== []) {
            throw new \LogicException(
                message: "Query tree {$where} is missing keys: " . implode(separator: ', ', array: $missing),
            );
        }
    }
}
