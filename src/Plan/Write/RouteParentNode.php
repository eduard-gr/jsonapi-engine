<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Write;

use Ggs\JsonApiEngine\Resource;

/**
 * Узел плана записи: родитель из вложенного маршрута (`POST /certificates/{id}/domains`). Форма — какой ресурс
 * родитель и колонки связи; id — значение из маршрута, в плане его нет (его получает `WriterBuilder::root()`).
 * Исполнитель — `Executor\Write\RouteParentWriter`: проверка, что родитель есть, и колонка связи для корней.
 *
 * Связь должна быть по владению (первичный ключ на стороне родителя) и вести к ресурсу точки входа: это проверяет
 * `WritePlanner` (иначе LogicException). Неизменяемый, без состояния.
 */
final readonly class RouteParentNode
{
    /**
     * @param class-string<Resource>      $parent
     * @param array{0: string, 1: string} $relation [первичный ключ родителя, колонка связи ребёнка]
     */
    public function __construct(
        public string $parent,
        public array $relation,
    ) {}
}
