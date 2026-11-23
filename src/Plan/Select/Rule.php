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
abstract class Rule implements PassInterface
{
    /**
     * Преобразование одного узла. Правило локальное: смотрит только на узел и его прямые входы.
     *
     * Имя параметра — часть контракта: transformUp вызывает apply(node: $node), поэтому каждое правило
     * обязано назвать параметр $node. Другое имя — Error: Unknown named parameter $node при выполнении.
     *
     * @param ResourceNode|EntityScanNodeDraft|EntityScanNode|ForeignDataScanNode|JoinNode $node узел, входы которого уже преобразованы
     * @return ResourceNode|EntityScanNodeDraft|EntityScanNode|ForeignDataScanNode|JoinNode|null замена узла или null, если правило не подходит
     */
    abstract public function apply(ResourceNode|EntityScanNodeDraft|EntityScanNode|ForeignDataScanNode|JoinNode $node): ResourceNode|EntityScanNodeDraft|EntityScanNode|ForeignDataScanNode|JoinNode|null;

    /**
     * Применяет правило ко всему дереву до фиксированной точки.
     *
     * @param ResourceNode|EntityScanNodeDraft|EntityScanNode|ForeignDataScanNode|JoinNode $plan корень дерева
     */
    #[\Override]
    final public function __invoke(ResourceNode|EntityScanNodeDraft|EntityScanNode|ForeignDataScanNode|JoinNode $plan): ResourceNode|EntityScanNodeDraft|EntityScanNode|ForeignDataScanNode|JoinNode
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
     * @param ResourceNode|EntityScanNodeDraft|EntityScanNode|ForeignDataScanNode|JoinNode $node    корень поддерева
     * @param bool                                             $changed по ссылке: true, если правило сработало хотя бы на одном узле
     */
    private function transformUp(ResourceNode|EntityScanNodeDraft|EntityScanNode|ForeignDataScanNode|JoinNode $node, bool &$changed): ResourceNode|EntityScanNodeDraft|EntityScanNode|ForeignDataScanNode|JoinNode
    {
        // После шага sql дерево не переписывается: EntityScanNode ставит последний шаг
        if ($node instanceof EntityScanNode) {
            throw new \LogicException(message: static::class . ' met EntityScanNode: rules must run before the sql step');
        }

        // Входы обходятся по классу узла: у JoinNode — два, у EntityScanNodeDraft — один, у ResourceNode — нет
        if ($node instanceof JoinNode) {
            $node = $node
                ->withOuter(outer: $this->transformUp(node: $node->outer, changed: $changed))
                ->withInner(inner: $this->transformUp(node: $node->inner, changed: $changed));
        } elseif ($node instanceof EntityScanNodeDraft) {
            // transformUp возвращает любой узел, а вход EntityScanNodeDraft — только EntitySharedJoinNode или ResourceNode.
            // Если правило заменило верх закрытой строки на что-то другое (например, подняло ветку),
            // это ошибка плана: строка уже закрыта, ветки в ней быть не может. Такие правила
            // должны идти до EntityScanNodeDraftPass.
            $input = $this->transformUp(node: $node->input, changed: $changed);
            if (!$input instanceof EntitySharedJoinNode && !$input instanceof ResourceNode) {
                // sprintf принимает значения через ...$values — они позиционные
                throw new \LogicException(message: sprintf(
                    '%s replaced the top of a closed row with %s: EntityScanNodeDraft input must be EntitySharedJoinNode or ResourceNode, the rule must run before EntityScanNodeDraftPass',
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
