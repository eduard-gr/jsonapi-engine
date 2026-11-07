<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine;

/**
 * Оператор фильтра: `filter[<имя>][<оператор>]=<значение>`. Значение case — имя оператора в запросе.
 */
enum Operator: string
{
    case Eq = 'eq';
    case Ne = 'ne';
    case Lt = 'lt';
    case Gt = 'gt';
    case Lte = 'lte';
    case Gte = 'gte';
    /**
     * Список значений через запятую. TODO: в SQL плана пока не строится (SharedScanNode::withSelect() бросает
     * LogicException): через PDO массив одним параметром не передать.
     */
    case In = 'in';
}
