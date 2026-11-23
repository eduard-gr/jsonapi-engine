<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Example\Container;

use LogicException;
use Psr\Container\NotFoundExceptionInterface;

/**
 * В `ArrayContainer` нет объекта с таким именем: ошибка сборки примера, а не запроса клиента.
 */
final class ServiceNotFoundException extends LogicException implements NotFoundExceptionInterface {}
