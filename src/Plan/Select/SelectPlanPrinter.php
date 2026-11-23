<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Select;

use sad_spirit\pg_builder\SqlBuilderWalker;

/**
 * Текстовый вид плана для отладки: дерево узлов (getTree()) и SQL каждого владельца запроса (getQueries()).
 *
 * Поток: вход — корень плана после любого шага SelectPlanner (getQueries() — после шага sql) → выход — текст.
 * План не меняется и не выполняется.
 */
final class SelectPlanPrinter
{
    /**
     * Дерево в читаемом виде: подпись узла, порядок у владельца и входы по именам.
     *
     * @param ResourceNode|EntityScanNodeDraft|EntityScanNode|ForeignDataScanNode|JoinNode $node   корень поддерева
     * @param string                                           $indent отступ текущего уровня
     */
    public static function getTree(ResourceNode|EntityScanNodeDraft|EntityScanNode|ForeignDataScanNode|JoinNode $node, string $indent = ''): string
    {
        // $label — подпись узла, $inputs — его входы по именам для вывода ниже
        if ($node instanceof ResourceNode) {
            // Источник: подпись — ресурс, входов нет
            $label = 'ResourceNode ' . self::getShortClassName(class: $node->resource);
            $inputs = [];
        } elseif ($node instanceof ForeignDataScanNode) {
            // Чужой источник: подпись — ресурс и класс источника, вход — сам ресурс
            $label = 'ForeignDataScanNode ' . self::getShortClassName(class: $node->input->resource)
                . ' from ' . self::getShortClassName(class: $node->getDataSource());
            $inputs = ['input' => $node->input];
        } elseif ($node instanceof EntityScanNodeDraft || $node instanceof EntityScanNode) {
            // Строка: подпись — класс владельца (до шага sql или после) и ресурс-владелец, вход — верх строки
            $label = self::getShortClassName(class: $node::class) . ' row ' . self::getShortClassName(class: $node->getRootResourceNode()->resource);
            $inputs = ['input' => $node->input];
        } else {
            // Соединение: подпись — класс и имя связи, входы — обе стороны
            $label = self::getShortClassName(class: $node::class) . ' ' . $node->name;
            $inputs = ['outer' => $node->outer, 'inner' => $node->inner];
        }

        // Порядок есть только у владельцев запроса и у чужого источника
        if (($node instanceof EntityScanNodeDraft || $node instanceof EntityScanNode || $node instanceof ForeignDataScanNode) && $node->order !== null) {
            $label .= " (order by {$node->order})";
        }

        // Каждый вход — с подписью стороны и на уровень глубже
        $output = $indent . $label . "\n";
        foreach ($inputs as $key => $input) {
            $output .= "{$indent}  {$key}:\n" . self::getTree(node: $input, indent: "{$indent}    ");
        }

        return $output;
    }

    /**
     * SQL каждого владельца запроса и значения его параметров (если есть), сверху вниз; у ресурса чужого источника —
     * класс источника и то, что получит его select(). Ожидает дерево после BuildSqlPass:
     * каждый владелец — EntityScanNode. Запросы веток внутри строки печатаются сразу после запроса
     * строки (getEntityScanBranches()).
     *
     * @param ResourceNode|EntityScanNodeDraft|EntityScanNode|ForeignDataScanNode|JoinNode $node корень поддерева вне строк
     * @throws \LogicException если владелец — ещё EntityScanNodeDraft (дерево не прошло шаг sql),
     *                                     или вне строки встретился ResourceNode без владельца
     */
    public static function getQueries(ResourceNode|EntityScanNodeDraft|EntityScanNode|ForeignDataScanNode|JoinNode $node): string
    {
        // Соединение своего запроса не имеет — запросы у владельцев в обеих сторонах
        if ($node instanceof JoinNode) {
            return self::getQueries(node: $node->outer) . self::getQueries(node: $node->inner);
        }

        if ($node instanceof ResourceNode) {
            throw new \LogicException(message: 'Resource without query owner: ' . $node->resource);
        }

        // У чужого источника SQL нет: печатается то, что он получит
        if ($node instanceof ForeignDataScanNode) {
            $call = json_encode(
                ['columns' => $node->getColumns(), 'conditions' => $node->getConditions(), 'order' => $node->getSortOrder()],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );

            return 'foreign data ' . $node->input->resource . ":
  " . $node->getDataSource() . "::select {$call}
";
        }

        if ($node instanceof EntityScanNodeDraft) {
            throw new \LogicException(message: 'Query owner has no select: run the sql step first');
        }
        $select = $node->select;

        // Текст запроса в одну строку: переносы и отступы walker'а — в один пробел
        $sql = (string) preg_replace(pattern: '/\s+/', replacement: ' ', subject: $select->dispatch(new SqlBuilderWalker()));

        $owner = 'row ' . $node->getRootResourceNode()->resource;

        // У строки могут быть ветки 1-1 ресурсов со своими запросами
        $branches = self::getEntityScanBranches(node: $node->input);

        // Значения параметров — под запросом, в виде JSON
        $parameters = $node->parameters === []
            ? ''
            : '  parameters: ' . json_encode($node->parameters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

        return "{$owner}:\n  {$sql}\n{$parameters}" . $branches;
    }

    /**
     * Запросы веток 1-1 ресурсов внутри строки: inner каждой ветки — отдельный запрос.
     *
     * @param ResourceNode|EntityScanNodeDraft|EntityScanNode|ForeignDataScanNode|JoinNode $node узел внутри строки
     */
    private static function getEntityScanBranches(ResourceNode|EntityScanNodeDraft|EntityScanNode|ForeignDataScanNode|JoinNode $node): string
    {
        if (!$node instanceof JoinNode) {
            return '';
        }

        // inner EntitySharedJoinNode — ещё та же строка; inner ветки — свой запрос
        $inner = $node instanceof EntitySharedJoinNode
            ? self::getEntityScanBranches(node: $node->inner)
            : self::getQueries(node: $node->inner);

        return self::getEntityScanBranches(node: $node->outer) . $inner;
    }

    /** Имя класса без пространства имён: Ggs\…\CertificateResource → CertificateResource */
    private static function getShortClassName(string $class): string
    {
        $position = strrpos(haystack: $class, needle: '\\');

        return $position === false ? $class : substr(string: $class, offset: $position + 1);
    }
}
