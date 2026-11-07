<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor;

use Generator;
use Ggs\JsonApiEngine\Document\ResourceRecord;

/**
 * Узел соединения: на выходе всегда ResourceRecord — главный ресурс с записанной связью
 * (и никогда null).
 *
 * Реализуют SharedRowJoinIterator (1:1), HashNestJoinIterator и MergeNestJoinIterator (1:N). Связь N:1
 * соединения не требует: её пишет сериализатор из внешнего ключа своей строки, а PreloadIterator
 * только загружает справочник в реестр — поэтому JoinIterator он не реализует.
 * Узлы соединения только записывают связи в relationships главных ресурсов; сами связанные
 * ресурсы никуда не копируются — в документ их собирает Document обходом связей.
 *
 * Метка нужна узлам, которые принимают на вход только результат соединения (например, SortIterator):
 * тип параметра JoinIterator не даст подставить туда другой узел — ошибка будет при сборке дерева,
 * а не при выполнении.
 *
 * Зачем SortIterator ограничен результатом соединения. По типу элементов выход узла соединения
 * почти не отличается от выхода SerializeIterator — оба отдают ResourceRecord (SerializeIterator ещё
 * и null). Различие в том, кто задаёт порядок потока:
 *  - у SerializeIterator над SqlScanIterator порядок задаёт запрос — нужный порядок дешевле получить
 *    в базе (ORDER BY), чем сортировать в памяти PHP;
 *  - у узла соединения порядок задаёт сам узел (порядок его $outer), и SQL изменить его
 *    не может — например, выход domains ← logs идёт по domain id, а для слияния
 *    с сертификатами нужен порядок по certificate_id. Только здесь и нужна SortIterator.
 * Так метка закрепляет правило «сортировать в SQL, где это возможно»: план, в котором SortIterator
 * стоит над запросом, не соберётся. Заодно на вход SortIterator не попадает null — узлы соединения
 * его не отдают.
 */
interface JoinIterator extends ExecutorIterator
{
    /**
     * @return Generator<int, ResourceRecord>
     */
    #[\Override]
    public function getIterator(): Generator;
}
