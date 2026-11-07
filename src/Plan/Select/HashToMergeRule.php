<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Select;

use Ggs\JsonApiEngine\Plan\HashNestJoinNode;
use Ggs\JsonApiEngine\Plan\JoinNode;
use Ggs\JsonApiEngine\Plan\MergeNestJoinNode;
use Ggs\JsonApiEngine\Plan\ResourceNode;
use Ggs\JsonApiEngine\Plan\Rule;
use Ggs\JsonApiEngine\Plan\SharedRowJoinNode;
use Ggs\JsonApiEngine\Plan\SharedScanNode;

/**
 * HashNestJoinNode → MergeNestJoinNode, если обе стороны можно упорядочить по ключу связи:
 * outer — по колонке родителя, inner — по колонке ребёнка.
 *
 * Порядок задаёт только владелец запроса (SharedScanNode); ветки отдают записи
 * в порядке своего outer, поэтому до владельца правило идёт по outer. У владельца одна ориентация:
 * он отказывает, если уже упорядочен другим Merge или занят клиентской сортировкой.
 *
 * Правило идёт снизу вверх, поэтому глубокие соединения занимают ориентацию первыми:
 * logs получает Merge (домены по id), а domains над ним остаётся Hash.
 *
 * Запускается после SharedScanPass: у каждого запроса к этому моменту уже есть владелец SharedScanNode,
 * а 1:N внутри строк стали HashJoinNode — их правило не трогает.
 * Порядок записывается во владельца как order; в ORDER BY его кладёт SharedScanNode::withSelect() (шаг sql).
 *
 * TODO: колонки не квалифицированы — одинаковый ключ разных таблиц не отличить, поэтому владелец,
 * занятый любым ключом, считается занятым.
 */
final class HashToMergeRule extends Rule
{
    #[\Override]
    public function apply(ResourceNode|SharedScanNode|JoinNode $node): ResourceNode|SharedScanNode|JoinNode|null
    {
        if (!$node instanceof HashNestJoinNode) {
            return null;
        }

        // $parentKey — порядок для outer, $childKey — для inner
        [$parentKey, $childKey] = $node->relation;

        // Обе стороны должны принять порядок; если хоть одна не может — остаётся Hash
        $outer = $this->claim(node: $node->outer, key: $parentKey);
        $inner = $this->claim(node: $node->inner, key: $childKey);
        if ($outer === null || $inner === null) {
            return null;
        }

        // Новый класс, те же имя и связь; стороны — с записанным порядком у владельцев
        return new MergeNestJoinNode(name: $node->name, relation: $node->relation, outer: $outer, inner: $inner);
    }

    /**
     * Закрепляет за владельцем потока порядок по ключу.
     *
     * @param ResourceNode|SharedScanNode|JoinNode $node корень стороны соединения
     * @param string                               $key  колонка, по которой должен быть упорядочен поток
     * @return ResourceNode|SharedScanNode|JoinNode|null сторона с порядком у владельца или null, если владелец занят
     */
    private function claim(ResourceNode|SharedScanNode|JoinNode $node, string $key): ResourceNode|SharedScanNode|JoinNode|null
    {
        // После SharedScanPass у каждого запроса есть владелец; голый ресурс или строка — ошибка порядка шагов
        if ($node instanceof SharedRowJoinNode) {
            throw new \LogicException(message: 'Nest join side leads to an unclosed shared row: ' . $node->name);
        }
        if ($node instanceof ResourceNode) {
            throw new \LogicException(message: 'Nest join side leads to a resource without query owner: ' . $node->resource);
        }

        // Ветка отдаёт записи в порядке своего outer — идём по outer до владельца
        if ($node instanceof JoinNode) {
            $outer = $this->claim(node: $node->outer, key: $key);

            return $outer === null
                ? null
                : $node->withOuter(outer: $outer);
        }

        // Владелец: порядок уже занят — другим Merge или клиентом
        if ($node->order !== null || $node->sort() !== []) {
            return null;
        }

        return $node->withOrder(order: $key);
    }
}
