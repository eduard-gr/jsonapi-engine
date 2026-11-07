<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan;

/**
 * Шаг преобразования плана: дерево → новое дерево.
 *
 * Общий вид для правил (Rule) и проходов с контекстом (Select\SharedScanPass, Select\BuildSqlPass),
 * поэтому планировщик вызывает любой шаг одинаково: $step(plan: $plan). Исходное дерево не меняется:
 * узлы иммутабельны, и результат каждого шага можно сохранить и показать.
 *
 * Имя параметра — часть контракта: планировщик передаёт его по имени, поэтому каждая реализация
 * __invoke обязана назвать параметр $plan. Другое имя — Error: Unknown named parameter $plan при выполнении.
 */
interface Pass
{
    /**
     * @param ResourceNode|SharedScanNode|JoinNode $plan корень дерева
     * @return ResourceNode|SharedScanNode|JoinNode корень нового дерева
     */
    public function __invoke(ResourceNode|SharedScanNode|JoinNode $plan): ResourceNode|SharedScanNode|JoinNode;
}
