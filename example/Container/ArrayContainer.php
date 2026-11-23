<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Example\Container;

use Psr\Container\ContainerInterface;

/**
 * Контейнер зависимостей примеров и тестов: готовые объекты по имени класса. Библиотека знает только интерфейс PSR-11
 * (`Psr\Container\ContainerInterface`); в приложении на этом месте стоит его собственный контейнер, который создаёт
 * клиентские классы (ресурсы `Resource`, источники `DataSource`) вместе с их зависимостями. Объекты ресурсов примера
 * даёт `ExampleResources::getAll()`.
 *
 * Поток: список объектов → `get(класс)` отдаёт тот же объект при каждом вызове. Сам ничего не создаёт: класса нет в
 * списке — `ServiceNotFoundException`. Два объекта одного класса — остаётся последний.
 */
final readonly class ArrayContainer implements ContainerInterface
{
    /** @var array<string, object> */
    private array $services;

    /**
     * @param list<object> $services
     */
    public function __construct(array $services)
    {
        $this->services = array_combine(
            array_map(static fn(object $service): string => $service::class, $services),
            $services,
        );
    }

    #[\Override]
    public function get(string $id): object
    {
        return $this->services[$id] ?? throw new ServiceNotFoundException("Service {$id} is not registered");
    }

    #[\Override]
    public function has(string $id): bool
    {
        return isset($this->services[$id]);
    }
}
