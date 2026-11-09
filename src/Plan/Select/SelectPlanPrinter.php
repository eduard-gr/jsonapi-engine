<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Select;

use sad_spirit\pg_builder\SqlBuilderWalker;

/**
 * Текстовый вид плана для отладки: дерево узлов (tree()) и SQL каждого владельца запроса (queries()).
 *
 * Поток: вход — корень плана после любого шага SelectPlanner (queries() — после шага sql) → выход — текст.
 * План не меняется и не выполняется.
 */
final class SelectPlanPrinter
{
    /**
     * Дерево в читаемом виде: подпись узла, порядок у владельца и входы по именам.
     *
     * @param ResourceNode|SharedScanNode|SqlScanNode|JoinNode $node   корень поддерева
     * @param string                                           $indent отступ текущего уровня
     */
    public static function tree(ResourceNode|SharedScanNode|SqlScanNode|JoinNode $node, string $indent = ''): string
    {
        // $label — подпись узла, $inputs — его входы по именам для вывода ниже
        if ($node instanceof ResourceNode) {
            // Источник: подпись — ресурс, входов нет
            $label = 'ResourceNode ' . self::shortClassName(class: $node->resource);
            $inputs = [];
        } elseif ($node instanceof SharedScanNode || $node instanceof SqlScanNode) {
            // Строка: подпись — класс владельца (до шага sql или после) и ресурс-владелец, вход — верх строки
            $label = self::shortClassName(class: $node::class) . ' row ' . self::shortClassName(class: $node->rowOwner()->resource);
            $inputs = ['input' => $node->input];
        } else {
            // Соединение: подпись — класс и имя связи, входы — обе стороны
            $label = self::shortClassName(class: $node::class) . ' ' . $node->name;
            $inputs = ['outer' => $node->outer, 'inner' => $node->inner];
        }

        // Порядок есть только у владельцев запроса
        if (($node instanceof SharedScanNode || $node instanceof SqlScanNode) && $node->order !== null) {
            $label .= " (order by {$node->order})";
        }

        // Каждый вход — с подписью стороны и на уровень глубже
        $output = $indent . $label . "\n";
        foreach ($inputs as $key => $input) {
            $output .= "{$indent}  {$key}:\n" . self::tree(node: $input, indent: "{$indent}    ");
        }

        return $output;
    }

    /**
     * SQL каждого владельца запроса и значения его параметров (если есть), сверху вниз. Ожидает дерево после BuildSqlPass:
     * каждый владелец — SqlScanNode. Запросы веток внутри строки печатаются сразу после запроса
     * строки (rowBranches()).
     *
     * @param ResourceNode|SharedScanNode|SqlScanNode|JoinNode $node корень поддерева вне строк
     * @throws \LogicException если владелец — ещё SharedScanNode (дерево не прошло шаг sql),
     *                                     или вне строки встретился ResourceNode без владельца
     */
    public static function queries(ResourceNode|SharedScanNode|SqlScanNode|JoinNode $node): string
    {
        // Соединение своего запроса не имеет — запросы у владельцев в обеих сторонах
        if ($node instanceof JoinNode) {
            return self::queries(node: $node->outer) . self::queries(node: $node->inner);
        }

        if ($node instanceof ResourceNode) {
            throw new \LogicException(message: 'Resource without query owner: ' . $node->resource);
        }

        if ($node instanceof SharedScanNode) {
            throw new \LogicException(message: 'Query owner has no select: run the sql step first');
        }
        $select = $node->select;

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
     * @param ResourceNode|SharedScanNode|SqlScanNode|JoinNode $node узел внутри строки
     */
    private static function rowBranches(ResourceNode|SharedScanNode|SqlScanNode|JoinNode $node): string
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
    private static function shortClassName(string $class): string
    {
        $position = strrpos(haystack: $class, needle: '\\');

        return $position === false ? $class : substr(string: $class, offset: $position + 1);
    }
}
