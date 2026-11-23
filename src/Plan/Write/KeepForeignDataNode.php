<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Write;

use Ggs\JsonApiEngine\Model\ForeignDataSource;
use Ggs\JsonApiEngine\Model\UpdateForeignDataInterface;
use Ggs\JsonApiEngine\Resource;

/**
 * Узел плана записи: ресурс над чужим источником, который в этом месте только ищут (`KeepOperation`). Пара к
 * `KeepEntityNode`; ветвь `keep` места (`WriteOperationNode`). Исполнитель — `Executor\Write\KeepForeignDataWriter`.
 *
 * Отдельного метода поиска у чужого источника нет: исполнитель вызывает `update()` с пустой строкой — источник
 * возвращает ключи найденной строки или пусто. Поэтому источник обязан реализовать `UpdateForeignDataInterface`
 * (проверяет `WritePlanner`). Неизменяемый, без состояния.
 */
final readonly class KeepForeignDataNode
{
    /**
     * @param class-string<Resource>                                     $resource
     * @param class-string<ForeignDataSource&UpdateForeignDataInterface> $source   класс источника: объект даст контейнер
     */
    public function __construct(
        public string $resource,
        public string $source,
    ) {}
}
