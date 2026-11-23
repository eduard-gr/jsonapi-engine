<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Example\Container;

use Ggs\JsonApiEngine\Example\Resource\CertificateResource;
use Ggs\JsonApiEngine\Example\Resource\CertificateWithDetailResource;
use Ggs\JsonApiEngine\Example\Resource\DeliveryResource;
use Ggs\JsonApiEngine\Example\Resource\DetailResource;
use Ggs\JsonApiEngine\Example\Resource\DomainResource;
use Ggs\JsonApiEngine\Example\Resource\LogResource;
use Ggs\JsonApiEngine\Example\Resource\ProviderResource;
use Ggs\JsonApiEngine\Resource;

/**
 * Объекты всех ресурсов примера (`example/Resource/`) для `ArrayContainer`: сборщики (`ReaderBuilder`,
 * `WriterBuilder`) берут ресурс из контейнера зависимостей по имени класса, поэтому контейнер примера или теста
 * должен их содержать. В приложении ресурсы создаёт его собственный контейнер вместе с зависимостями конструктора;
 * у ресурсов примера зависимостей нет, они создаются через `new` без аргументов.
 *
 * Каждый вызов создаёт новые объекты. Ресурсы, объявленные в самих файлах примеров и в тестах, сюда не входят: их
 * добавляет в контейнер тот, кто их объявил.
 */
final class ExampleResources
{
    /**
     * @return list<Resource>
     */
    public static function getAll(): array
    {
        return [
            new CertificateResource(),
            new CertificateWithDetailResource(),
            new DeliveryResource(),
            new DetailResource(),
            new DomainResource(),
            new LogResource(),
            new ProviderResource(),
        ];
    }
}
