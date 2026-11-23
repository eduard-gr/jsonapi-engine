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
     * Список значений через запятую. Временно отключён: `QueryParser` отклоняет запрос с ним (400), даже если
     * ресурс объявил оператор в `filter`. TODO: в SQL плана не строится (через PDO массив одним параметром не
     * передать); в дереве, собранном руками, `EntityScanNodeDraft::withSelect()` бросает LogicException.
     */
    case In = 'in';
}
