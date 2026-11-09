<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Example\Controller;

use Ggs\JsonApiEngine\Document\Document;
use Ggs\JsonApiEngine\Example\Resource\CertificateResource;
use Ggs\JsonApiEngine\Executor\Select\IteratorBuilder;
use Ggs\JsonApiEngine\Parser\InvalidQueryException;
use Ggs\JsonApiEngine\Parser\QueryParser;
use Ggs\JsonApiEngine\Parser\ResourceNotFoundException;
use Ggs\JsonApiEngine\Plan\Select\JoinNode;
use Ggs\JsonApiEngine\Plan\Select\SelectPlanner;
use Ggs\JsonApiEngine\Plan\Select\SqlScanNode;
use LogicException;
use PDO;

/**
 * Точка входа endpoint'а `GET /certificates`: корневой ресурс — `CertificateResource`.
 *
 * Поток: вход — параметры запроса JSON:API (`$_GET`) → выход — `Document` (get()) или план select после всех
 * шагов планировщика, где каждый владелец запроса — `SqlScanNode` с pg_builder `Select` (plan()).
 *
 * get() = plan() + `IteratorBuilder::document()`: дерево итераторов с новым реестром на каждый вызов. Запросы
 * выполняются, только когда документ сериализуют (`json_encode()`).
 *
 * Что клиенту можно (include, fields, filter, sort), описывает сам ресурс: схемы endpoint'а пока нет.
 * Роутинг и HTTP — снаружи: вызывающий код выбирает контроллер по пути и превращает исключения
 * в ответ 400/404.
 */
final class CertificatesController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly QueryParser $parser = new QueryParser(),
        private readonly SelectPlanner $planner = new SelectPlanner(),
    ) {}

    /**
     * Документ JSON:API по запросу.
     *
     * @param array<string, mixed> $query параметры запроса в виде, в котором их разбирает PHP (`$_GET`)
     *
     * @throws InvalidQueryException     запрос клиента не прошёл проверку по ресурсу (400)
     * @throws ResourceNotFoundException ресурс не найден (404)
     */
    public function get(array $query): Document
    {
        return (new IteratorBuilder(pdo: $this->pdo))->document(plan: $this->plan(query: $query));
    }

    /**
     * План select по запросу (для отладки: `example/certificates.php` печатает его).
     *
     * @param array<string, mixed> $query параметры запроса в виде, в котором их разбирает PHP (`$_GET`)
     * @return SqlScanNode|JoinNode корень плана после шага sql: владелец запроса с собранным Select (SqlScanNode)
     *                              или соединение над такими владельцами
     *
     * @throws InvalidQueryException     запрос клиента не прошёл проверку по ресурсу (400)
     * @throws ResourceNotFoundException ресурс не найден (404)
     */
    public function plan(array $query): SqlScanNode|JoinNode
    {
        $tree = $this->parser->parse(resource: CertificateResource::class, query: $query);
        $plan = $this->planner->plan(tree: $tree)['sql'];

        // После шага sql каждый владелец запроса — SqlScanNode; ResourceNode или SharedScanNode в корне — ошибка планировщика
        if (!$plan instanceof SqlScanNode && !$plan instanceof JoinNode) {
            throw new LogicException('Plan after the sql step must start with SqlScanNode or JoinNode, got ' . $plan::class);
        }

        return $plan;
    }
}
