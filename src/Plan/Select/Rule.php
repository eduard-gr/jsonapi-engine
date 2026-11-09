<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Select;

/**
 * Локальное правило переписывания. Обход реализован здесь, наследнику остаётся apply().
 *
 * Правило применяется ко всему дереву снизу вверх, пока за полный обход не случится ни одной замены
 * (фиксированная точка). Деревья не сравниваются — замены считаются, поэтому в узлах могут быть объекты
 * (Select), и правило можно ставить в любом месте последовательности.
 */
abstract class Rule implements Pass
{
    /**
     * Преобразование одного узла. Правило локальное: смотрит только на узел и его прямые входы.
     *
     * Имя параметра — часть контракта: transformUp вызывает apply(node: $node), поэтому каждое правило
     * обязано назвать параметр $node. Другое имя — Error: Unknown named parameter $node при выполнении.
     *
     * @param ResourceNode|SharedScanNode|SqlScanNode|JoinNode $node узел, входы которого уже преобразованы
     * @return ResourceNode|SharedScanNode|SqlScanNode|JoinNode|null замена узла или null, если правило не подходит
     */
    abstract public function apply(ResourceNode|SharedScanNode|SqlScanNode|JoinNode $node): ResourceNode|SharedScanNode|SqlScanNode|JoinNode|null;

    /**
     * Применяет правило ко всему дереву до фиксированной точки.
     *
     * @param ResourceNode|SharedScanNode|SqlScanNode|JoinNode $plan корень дерева
     */
    #[\Override]
    final public function __invoke(ResourceNode|SharedScanNode|SqlScanNode|JoinNode $plan): ResourceNode|SharedScanNode|SqlScanNode|JoinNode
    {
        do {
            // Сбрасывается перед каждым кругом; transformUp выставит true, если правило где-то сработало
            $changed = false;
            $plan = $this->transformUp(node: $plan, changed: $changed);
        } while ($changed);

        return $plan;
    }

    /**
     * Снизу вверх: сначала входы, потом сам узел. Так правило всегда видит входы уже преобразованными.
     *
     * @param ResourceNode|SharedScanNode|SqlScanNode|JoinNode $node    корень поддерева
     * @param bool                                             $changed по ссылке: true, если правило сработало хотя бы на одном узле
     */
    private function transformUp(ResourceNode|SharedScanNode|SqlScanNode|JoinNode $node, bool &$changed): ResourceNode|SharedScanNode|SqlScanNode|JoinNode
    {
        // После шага sql дерево не переписывается: SqlScanNode ставит последний шаг
        if ($node instanceof SqlScanNode) {
            throw new \LogicException(message: static::class . ' met SqlScanNode: rules must run before the sql step');
        }

        // Входы обходятся по классу узла: у JoinNode — два, у SharedScanNode — один, у ResourceNode — нет
        if ($node instanceof JoinNode) {
            $node = $node
                ->withOuter(outer: $this->transformUp(node: $node->outer, changed: $changed))
                ->withInner(inner: $this->transformUp(node: $node->inner, changed: $changed));
        } elseif ($node instanceof SharedScanNode) {
            // transformUp возвращает любой узел, а вход SharedScanNode — только SharedRowJoinNode или ResourceNode.
            // Если правило заменило верх закрытой строки на что-то другое (например, подняло ветку),
            // это ошибка плана: строка уже закрыта, ветки в ней быть не может. Такие правила
            // должны идти до SharedScanPass.
            $input = $this->transformUp(node: $node->input, changed: $changed);
            if (!$input instanceof SharedRowJoinNode && !$input instanceof ResourceNode) {
                // sprintf принимает значения через ...$values — они позиционные
                throw new \LogicException(message: sprintf(
                    '%s replaced the top of a closed row with %s: SharedScanNode input must be SharedRowJoinNode or ResourceNode, the rule must run before SharedScanPass',
                    static::class,
                    $input::class,
                ));
            }
            $node = $node->withInput(input: $input);
        }

        // null — правило не сработало, узел остаётся как есть
        $result = $this->apply(node: $node);
        if ($result === null) {
            return $node;
        }

        $changed = true;

        return $result;
    }
}
