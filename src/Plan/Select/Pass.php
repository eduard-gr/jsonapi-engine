<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Select;

/**
 * Шаг преобразования плана: дерево → новое дерево.
 *
 * Общий вид для правил (Rule) и проходов с контекстом (SharedScanPass, BuildSqlPass),
 * поэтому планировщик вызывает любой шаг одинаково: $step(plan: $plan). Исходное дерево не меняется:
 * узлы иммутабельны, и результат каждого шага можно сохранить и показать.
 *
 * Имя параметра — часть контракта: планировщик передаёт его по имени, поэтому каждая реализация
 * __invoke обязана назвать параметр $plan. Другое имя — Error: Unknown named parameter $plan при выполнении.
 */
interface Pass
{
    /**
     * @param ResourceNode|SharedScanNode|SqlScanNode|JoinNode $plan корень дерева
     * @return ResourceNode|SharedScanNode|SqlScanNode|JoinNode корень нового дерева
     */
    public function __invoke(ResourceNode|SharedScanNode|SqlScanNode|JoinNode $plan): ResourceNode|SharedScanNode|SqlScanNode|JoinNode;
}
