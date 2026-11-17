<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Write;

/**
 * Корень плана записи: дерево ресурсов (`input`) и привязка к родителю из вложенного маршрута (`route`, null — маршрут
 * не вложенный). Исполнитель — `WriteRootWriter`: сначала проверки по всему запросу, потом корни документа по
 * очереди. Сюда же лягут проверки целей связей по внешнему ключу (пока не делаются: несуществующая цель — ошибка
 * внешнего ключа базы, 500): им тоже нужны id всех элементов места до первой записи.
 */
final readonly class WriteRootNode
{
    public function __construct(
        public WriteJoinNode|WriteReplaceNode|WriteOperationNode $input,
        public ?RouteParentNode $route,
    ) {}
}
