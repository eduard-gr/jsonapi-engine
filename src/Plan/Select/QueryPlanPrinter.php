<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Select;

use Ggs\JsonApiEngine\Plan\JoinNode;
use Ggs\JsonApiEngine\Plan\ResourceNode;
use Ggs\JsonApiEngine\Plan\SharedRowJoinNode;
use Ggs\JsonApiEngine\Plan\SharedScanNode;
use sad_spirit\pg_builder\SqlBuilderWalker;

/**
 * Текстовый вид плана для отладки: дерево узлов (tree()) и SQL каждого владельца запроса (queries()).
 *
 * Поток: вход — корень плана после любого шага QueryPlanner (queries() — после шага sql) → выход — текст.
 * План не меняется и не выполняется.
 */
final class QueryPlanPrinter
{
    /**
     * Дерево в читаемом виде: подпись узла, порядок у владельца и входы по именам.
     *
     * @param ResourceNode|SharedScanNode|JoinNode $node   корень поддерева
     * @param string                               $indent отступ текущего уровня
     */
    public static function tree(ResourceNode|SharedScanNode|JoinNode $node, string $indent = ''): string
    {
        // $label — подпись узла, $inputs — его входы по именам для вывода ниже
        if ($node instanceof ResourceNode) {
            // Источник: подпись — ресурс, входов нет
            $label = 'ResourceNode ' . self::short(class: $node->resource);
            $inputs = [];
        } elseif ($node instanceof SharedScanNode) {
            // Строка: подпись — ресурс-владелец, вход — верх строки
            $label = 'SharedScanNode row ' . self::short(class: $node->rowOwner()->resource);
            $inputs = ['input' => $node->input];
        } else {
            // Соединение: подпись — класс и имя связи, входы — обе стороны
            $label = self::short(class: $node::class) . ' ' . $node->name;
            $inputs = ['outer' => $node->outer, 'inner' => $node->inner];
        }

        // Порядок есть только у владельцев запроса
        if ($node instanceof SharedScanNode && $node->order !== null) {
            $label .= " (order by {$node->order})";
        }

        // Каждый вход — с подписью стороны и на уровень глубже
        $out = $indent . $label . "\n";
        foreach ($inputs as $key => $input) {
            $out .= "{$indent}  {$key}:\n" . self::tree(node: $input, indent: "{$indent}    ");
        }

        return $out;
    }

    /**
     * SQL каждого владельца запроса и значения его параметров (если есть), сверху вниз. Ожидает дерево после BuildSqlPass:
     * у каждого SharedScanNode есть select. Запросы веток внутри строки печатаются сразу после запроса
     * строки (rowBranches()).
     *
     * @param ResourceNode|SharedScanNode|JoinNode $node корень поддерева вне строк
     * @throws \LogicException если у владельца запроса нет select — дерево не прошло шаг sql,
     *                         или вне строки встретился ResourceNode без владельца
     */
    public static function queries(ResourceNode|SharedScanNode|JoinNode $node): string
    {
        // Соединение своего запроса не имеет — запросы у владельцев в обеих сторонах
        if ($node instanceof JoinNode) {
            return self::queries(node: $node->outer) . self::queries(node: $node->inner);
        }

        if ($node instanceof ResourceNode) {
            throw new \LogicException(message: 'Resource without query owner: ' . $node->resource);
        }

        $select = $node->select ?? throw new \LogicException(message: 'Query owner has no select: run the sql step first');

        // Текст запроса в одну строку: переносы и отступы walker'а — в один пробел
        $sql = (string) preg_replace(pattern: '/\s+/', replacement: ' ', subject: $select->dispatch(new SqlBuilderWalker()));

        $owner = 'row ' . $node->rowOwner()->resource;

        // У строки могут быть ветки 1-1 ресурсов со своими запросами
        $branches = self::rowBranches(node: $node->input);

        // Значения параметров — под запросом, в виде JSON
        $parameters = $node->parameters === []
            ? ''
            : '  parameters: ' . json_encode($node->parameters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

        return "{$owner}:\n  {$sql}\n{$parameters}" . $branches;
    }

    /**
     * Запросы веток 1-1 ресурсов внутри строки: inner каждой ветки — отдельный запрос.
     *
     * @param ResourceNode|SharedScanNode|JoinNode $node узел внутри строки
     */
    private static function rowBranches(ResourceNode|SharedScanNode|JoinNode $node): string
    {
        if (!$node instanceof JoinNode) {
            return '';
        }

        // inner SharedRowJoinNode — ещё та же строка; inner ветки — свой запрос
        $inner = $node instanceof SharedRowJoinNode
            ? self::rowBranches(node: $node->inner)
            : self::queries(node: $node->inner);

        return self::rowBranches(node: $node->outer) . $inner;
    }

    /** Имя класса без пространства имён: Ggs\…\CertificateResource → CertificateResource */
    private static function short(string $class): string
    {
        $position = strrpos(haystack: $class, needle: '\\');

        return $position === false ? $class : substr(string: $class, offset: $position + 1);
    }
}
