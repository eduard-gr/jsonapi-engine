<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Write;

/**
 * Корень плана записи: дерево ресурсов (`input`) и привязка к родителю из вложенного маршрута (`route`, null — маршрут
 * не вложенный). Строит `WritePlanner::plan()`. Исполнитель — `Executor\Write\WriteRootWriter`: сначала проверки
 * по всему запросу, потом корни дерева операций по очереди.
 *
 * Сюда же лягут проверки целей связей по внешнему ключу (пока не делаются: несуществующая цель — ошибка внешнего
 * ключа базы): им тоже нужны id всех элементов места до первой записи.
 *
 * Неизменяемый, без состояния.
 */
final readonly class WriteRootNode
{
    public function __construct(
        public WriteJoinNode|EntityReplaceNode|WriteOperationNode $input,
        public ?EntityRouteParentNode $route,
    ) {}
}
