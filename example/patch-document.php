<?php

/**
 * Прототип записи (POST, PATCH и DELETE) ресурса со связями: документ в формате вставки (example/insert-document.php:
 * `included` + `lid`) и операция над элементом связи в `meta.op` идентификатора. Один парсер, планировщик и набор
 * исполнителей на оба запроса: POST — частный случай PATCH (корень новый, всё по `lid`). Весь прототип — в этом файле,
 * `src/` не меняется; из `src/` взяты `InsertPlanner::tables()` + `WriterBuilder` (вставка новых детей),
 * `InsertTableWriter` (вставка отсутствующей необязательной части модели) и контракты `PushExecutor`, `TableWriter`.
 *
 * Конвейер, как у SELECT и вставки: документ → `WriteDocumentParser` → дерево операций → `PatchPlanner` → план
 * (неизменяемые узлы `…Node`) → `PatchWriterBuilder` → дерево исполнителей (`…Writer`) → `push()`.
 *
 * Дерево операций — типизированный результат разбора (`WriteOperation`): `InsertOperation`, `UpdateOperation`,
 * `KeepOperation`, `DeleteOperation`, связи по владению — `RelationshipOperation`; ресурс назван `PersistentIdentity`
 * (`id`) или `LocalIdentity` (`lid`). Объекты документа и пометка `meta.op` дальше разбора не идут. Исполнители
 * уровня ресурсов принимают операцию (`OperationPushExecutor`); вставке из `src/`, которая принимает массив, её
 * передаёт переходник `InsertOperationWriter`.
 *
 *     php example/patch-document.php            # документ → дерево операций → план → дерево исполнителей → ключи;
 *                                               # состояние до и после; SQL каждого запроса — в stderr
 *     php example/patch-document.php 2>sql.log  # SQL отдельно
 *
 * Пошаговый разбор обновления на сценарии 1 (как строится и меняется дерево, как по нему идут данные, с диаграммами) —
 * example/patch-document.md.
 *
 * `POST /certificates` (`WriteDocumentParser::insert()`): `data` — ресурс или список, каждый элемент — новый корень
 * (без `id`, `lid` не обязателен), потомки — только по `lid`; ответ на один ресурс — `201 Created` + `Location` +
 * `{data: {type, id}}`, на список — `201` без `Location`, `data` — идентификаторы в порядке запроса. Вложенный маршрут
 * `POST /certificates/1/domains` — в плане `RouteParentNode` (родитель и колонки связи, без id), при выполнении
 * `RouteParentWriter` один раз проверяет, что родитель есть (иначе 404; `FOR KEY SHARE` в PostgreSQL), и даёт корням
 * колонку связи. Остальные ссылки на существующие ресурсы (связи по внешнему ключу) пока не проверяются:
 * несуществующая цель — ошибка внешнего ключа базы, 500; их проверка ляжет в `WriteRootNode` тем же запросом
 * `Tables::existing()`.
 * `PATCH /certificates/{id}`: корень — существующий ресурс точки входа (`update()`, id из маршрута, `data.id` обязан
 * совпасть), ответ `204 No Content`. `DELETE /certificates/{id}` (`delete()`): тела нет, корень с операцией `remove`,
 * ресурс `Deletable`; удаляется строка главной таблицы, всё зависимое — каскад базы (`DeleteResourceWriter`); ответ
 * `204`, ресурса нет — 404; во вложенном маршруте (`/certificates/1/domains/5`) чужой ребёнок — 404.
 *
 * Элемент связи по владению (`OneToMany`, первичный ключ на стороне родителя):
 *
 *     {type, lid} + ресурс в included                  add     — новый ребёнок (и его новые дети)
 *     {type, id}  + ресурс в included                  update  — свой ребёнок: WHERE <колонка связи> = родитель
 *     {type, id}                                       keep    — только проверка, что ребёнок свой
 *     {type, id, meta: {op: remove}}                   remove  — удалить ребёнка (его детей удаляет каскад базы)
 *
 * `meta.op` можно написать и там, где операция выводится (`add` у `lid`, `update` у `id` + included); несовпадение —
 * 400. Связь без единой пометки `meta.op` — стандартная полная замена JSON:API: дети, не названные в списке,
 * удаляются (`DELETE … WHERE <колонка связи> = родитель AND <pk> NOT IN (названные id)`). Хоть одна пометка —
 * частичное изменение: неназванные дети не трогаются.
 *
 * Связь to-one по владению (`certificate.delivery`, первичный ключ на стороне родителя) — без `meta.op`, по форме:
 *
 *     {type, lid} + ресурс в included                  replace — текущий ребёнок удаляется, новый вставляется
 *     {type, id}  + ресурс в included                  update  — текущий ребёнок (чужой id — 404)
 *     {type, id}                                       keep    — только проверка
 *     null                                             replace — текущий ребёнок удаляется (его нет — не ошибка)
 *
 * Связь многие-ко-многим (через таблицу связки) прототип пока не поддерживает: решение убрано до необходимости,
 * договорённости — в памяти проекта.
 *
 * Ресурс из нескольких таблиц (`CertificateWithDetailResource` = `LeftJoin(certificates, certificate_details)`):
 * изменение — дерево таблиц по `model()` (`PatchPlanner::tables()`, из example/update-document.php): сначала главная
 * таблица (её ключ — id ресурса), потом присоединённая по ключу, который вернула главная; неизменённые значения не
 * перезаписываются (`IS DISTINCT FROM`). `null` в атрибуте — NULL в колонку (атрибут без `nullable` — 400); строка
 * правой стороны `LeftJoin` есть — пишется NULL, строка не удаляется; строки нет — вставляется, если хоть одно
 * присланное значение не NULL, иначе вставлять нечего. Удаление — строка главной таблицы, присоединённые удаляет
 * каскад базы.
 *
 * Возможности ресурсов (маркеры на классе, проверяет парсер до первого запроса, без маркера — 403 с указателем):
 * `add` — `Insertable`; `update` — `Updatable` (корень PATCH, даже если меняются только связи; ребёнок `{id}` +
 * included); `remove`, полная замена to-many, замена to-one через `lid`/`null` — `Deletable` цели, по форме документа.
 * `Updatable` и `Deletable` пока объявлены в example/Resource/ (их реализуют ресурсы примера, а значит, нужна
 * автозагрузка), при переносе в `src/` — рядом с `Insertable`.
 *
 * Правило области запроса: создаются только корень и его потомки по владению; связи по внешнему ключу — только на
 * существующие ресурсы (`{type, id}`). Каждый ресурс `included` используется ровно
 * один раз. Весь документ проверяется до первого запроса (`WriteDocumentParser`), ошибки — WriteDocumentException с JSON
 * Pointer. Ошибка выполнения (404) называет ресурс типом и `id` в `meta` ошибки: указателя у операций нет.
 *
 * Порядок выполнения: родитель, затем связи (связи одного родителя — левый глубокий список в порядке объявления,
 * `PatchPlanner`), в связи по владению — удаление неназванных (полная замена), `remove`, `keep`/`update`, `add`:
 * освобождённое значение уникальной колонки (`domain_name`) можно занять новым ребёнком в том же запросе.
 *
 * Девять сценариев, каждый в своей транзакции, которая откатывается: `PATCH /certificates/1` над
 * `CertificateWithDetailResource` (обе таблицы, NULL в колонку деталей, связь по владению `domains`); PATCH
 * сертификата без строки деталей (вставлен в транзакции: сначала только NULL — не вставляется, потом значения —
 * вставляется); `POST /certificates` с деталями, доставкой и доменами (документ и SQL —
 * как у example/insert-plan.php); `POST /certificates/1/domains` со списком из двух доменов (документ и SQL — как у
 * example/insert-route.php) и тот же план с несуществующим родителем `POST /certificates/999/domains` (404);
 * `DELETE /certificates/1` (строки до и после: каскад удалил детали, доставку, домены, логи и конфиги) и тот же план
 * для `DELETE /certificates/999` (404); `PATCH /certificates/1` с полной заменой списка `domains` (`PatchReplaceNode`
 * под `PatchJoinNode`) и с заменой пустым — `delivery: null`, `domains: []` (только `PatchReplaceNode`, узлов связи
 * нет). План и дерево исполнителей печатаются текстом (`planTree()`, `executionTree()`), порядок выполнения виден по
 * SQL в stderr.
 *
 * Ограничения прототипа: необязательная правая сторона модели — одна таблица; вставка отсутствующей строки деталей
 * без её NOT NULL колонки — ошибка базы; у ребёнка колонка связи с родителем должна лежать в главной таблице его
 * модели; составные атрибуты не поддержаны; многие-ко-многим нет; перенос существующего ребёнка к другому родителю
 * не поддерживается. Ответ на успешный PATCH — `204 No Content` без тела (модификации не возвращают документ; POST —
 * `201 Created` + `Location` + `{data: {type, id}}`); ключи корня печатаются только для наглядности.
 */

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Example\PatchPrototype;

use Ggs\JsonApiEngine\Example\Resource\CertificateResource;
use Ggs\JsonApiEngine\Example\Resource\CertificateWithDetailResource;
use Ggs\JsonApiEngine\Example\Resource\Deletable;
use Ggs\JsonApiEngine\Example\Resource\DomainResource;
use Ggs\JsonApiEngine\Example\Resource\Updatable;
use Ggs\JsonApiEngine\Executor\Insert\InsertTableWriter;
use Ggs\JsonApiEngine\Executor\Insert\TableWriter;
use Ggs\JsonApiEngine\Executor\Insert\WriterBuilder;
use Ggs\JsonApiEngine\Executor\PushExecutor;
use Ggs\JsonApiEngine\Model\Entity;
use Ggs\JsonApiEngine\Model\Join;
use Ggs\JsonApiEngine\Model\LeftJoin;
use Ggs\JsonApiEngine\Model\ModelTree;
use Ggs\JsonApiEngine\Plan\Insert\InsertPlanner;
use Ggs\JsonApiEngine\Plan\Insert\InsertResourceNode;
use Ggs\JsonApiEngine\Plan\Insert\InsertTableNode;
use Ggs\JsonApiEngine\Resource;
use Ggs\JsonApiEngine\Resource\Insertable;
use Ggs\JsonApiEngine\Resource\OneToMany;
use Ggs\JsonApiEngine\Resource\OneToOne;
use LogicException;
use PDO;
use RuntimeException;
use sad_spirit\pg_builder\Delete;
use sad_spirit\pg_builder\enums\LockingStrength;
use sad_spirit\pg_builder\enums\LogicalOperator;
use sad_spirit\pg_builder\nodes\ColumnReference;
use sad_spirit\pg_builder\nodes\expressions\InExpression;
use sad_spirit\pg_builder\nodes\expressions\IsDistinctFromExpression;
use sad_spirit\pg_builder\nodes\expressions\LogicalExpression;
use sad_spirit\pg_builder\nodes\expressions\NamedParameter;
use sad_spirit\pg_builder\nodes\expressions\OperatorExpression;
use sad_spirit\pg_builder\nodes\lists\ExpressionList;
use sad_spirit\pg_builder\nodes\lists\SetClauseList;
use sad_spirit\pg_builder\nodes\lists\TargetList;
use sad_spirit\pg_builder\nodes\LockingElement;
use sad_spirit\pg_builder\nodes\QualifiedName;
use sad_spirit\pg_builder\nodes\range\RelationReference;
use sad_spirit\pg_builder\nodes\range\UpdateOrDeleteTarget;
use sad_spirit\pg_builder\nodes\SetTargetElement;
use sad_spirit\pg_builder\nodes\SingleSetClause;
use sad_spirit\pg_builder\nodes\TargetElement;
use sad_spirit\pg_builder\Select;
use sad_spirit\pg_builder\SqlBuilderWalker;
use sad_spirit\pg_builder\Statement;
use sad_spirit\pg_builder\Update;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/db/connection.php';

/**
 * Ошибка записи для ответа: статус, описание и место ошибки. 400/403/409 — при разборе, до первого запроса: место —
 * JSON Pointer (`source.pointer`), разбор его знает. 404 — при выполнении (ребёнка или цели нет, ребёнок принадлежит
 * другому родителю): место — ресурс, названный типом и `id` в `meta` ошибки; у операций указателя нет. Родителя из
 * маршрута нет в документе — у его 404 нет ни указателя, ни `meta`.
 *
 * @temporary Только для прототипа. В `src/` ошибки разойдутся по исключениям слоёв: 400 разбора —
 *            `Parser\InvalidDocumentException` (у него нет статуса, только 400), 404 — `ResourceNotFoundException`;
 *            для 403 (нет возможности ресурса) и 409 (тип или id не совпали с маршрутом) исключений в `src/` нет, их
 *            нужно будет добавить.
 */
final class WriteDocumentException extends RuntimeException
{
    /**
     * @param array<string, string>|null $meta ресурс ошибки выполнения: `{type, id}`
     */
    public function __construct(
        public readonly string $status,
        public readonly ?string $pointer,
        string $detail,
        public readonly ?array $meta = null,
    ) {
        parent::__construct($detail);
    }

    /**
     * @return array{errors: list<array<string, mixed>>}
     */
    public function toDocument(): array
    {
        return ['errors' => [[
            'status' => $this->status,
            ...($this->pointer === null ? [] : ['source' => ['pointer' => $this->pointer]]),
            'detail' => $this->getMessage(),
            ...($this->meta === null ? [] : ['meta' => $this->meta]),
        ]]];
    }
}

// ---------------------------------------------------------------------------------------------------------------
// Дерево операций: результат разбора документа, вход планировщика и исполнителей

/**
 * Чем ресурс назван в документе: `id` (`PersistentIdentity`) или `lid` (`LocalIdentity`). Один тип вместо двух
 * свойств, допускающих null: «ровно одно из двух» нельзя нарушить, а вид имени — главное, по чему разбор выбирает
 * операцию (`lid` — ресурс новый, `id` — существующий).
 *
 * Слово «идентификатор» оставлено за объектом `{type, id}` из связи (resource identifier object в JSON:API); это —
 * его значение.
 */
interface ResourceIdentity
{
    /**
     * Имя текстом: `id:3` или `lid:d1`.
     */
    public function key(): string;

    /**
     * Как ресурс называется в `meta` ошибки: `{id: "3"}` или `{lid: "d1"}`.
     *
     * @return array{id: string}|array{lid: string}
     */
    public function toMeta(): array;
}

/**
 * `id`: ресурс существует в базе. Значение — целое, проверенное при чтении документа (в документе это строка цифр).
 */
final readonly class PersistentIdentity implements ResourceIdentity
{
    public function __construct(
        public int $id,
    ) {}

    #[\Override]
    public function key(): string
    {
        return "id:{$this->id}";
    }

    #[\Override]
    public function toMeta(): array
    {
        return ['id' => (string) $this->id];
    }
}

/**
 * `lid`: новый ресурс. Имя действует только внутри документа — по нему разбор находит ресурс `included` для
 * идентификатора из связи; в базу оно не попадает. В дереве операций остаётся только для ошибок.
 */
final readonly class LocalIdentity implements ResourceIdentity
{
    public function __construct(
        public string $lid,
    ) {}

    #[\Override]
    public function key(): string
    {
        return "lid:{$this->lid}";
    }

    #[\Override]
    public function toMeta(): array
    {
        return ['lid' => $this->lid];
    }
}

/**
 * Операция над одним ресурсом: узел дерева, которое выдаёт разбор (`WriteDocumentParser`). Вид операции — класс:
 * `InsertOperation`, `UpdateOperation`, `KeepOperation`, `DeleteOperation`; у каждого только те свойства, которые у
 * операции бывают.
 *
 * Общее у всех — описание ресурса: по нему строится место плана. Указателя на место в документе у операций нет:
 * ошибка выполнения называет ресурс типом и `id` (в `meta` ошибки), а они у операции есть.
 */
abstract readonly class WriteOperation
{
    /**
     * @param class-string<Resource> $resource
     */
    public function __construct(
        public string $resource,
    ) {}

    /**
     * Связь по владению `name` этой операции; null — связи нет в документе (детей не трогают) или у операции связей
     * не бывает (`KeepOperation`, `DeleteOperation`). Узлы связей плана проходят все операции места, поэтому
     * спрашивают так, а не по классу операции.
     */
    public function relationship(string $name): ?RelationshipOperation
    {
        return null;
    }
}

/**
 * Вставить ресурс: корень POST или идентификатор `{type, lid}` с ресурсом в `included`.
 *
 * Своего id нет: его даст база. Имя — только `lid` (у корня POST его может не быть): вставить ресурс с `id` нельзя
 * по построению. Атрибуты — только присланные: остальным колонкам значение даёт `DEFAULT` базы. У нового ресурса
 * все дети тоже новые, а полной замены в связях не бывает: удалять нечего.
 */
final readonly class InsertOperation extends WriteOperation
{
    /**
     * @param class-string<Resource>                 $resource      описание ресурса (по `type` документа)
     * @param LocalIdentity|null                     $identity      `lid`; null — корень POST без `lid`
     * @param array<string, mixed>                   $attributes    присланные атрибуты: имя → значение
     * @param array<string, PersistentIdentity|null> $references    связи по внешнему ключу: имя → цель (только
     *                                                              существующий ресурс), null — без связи
     * @param array<string, RelationshipOperation>   $relationships связи по владению: имя → операции над детьми
     */
    public function __construct(
        string $resource,
        public ?LocalIdentity $identity,
        public array $attributes,
        public array $references,
        public array $relationships,
    ) {
        parent::__construct(
            resource: $resource,
        );
    }

    #[\Override]
    public function relationship(string $name): ?RelationshipOperation
    {
        return $this->relationships[$name] ?? null;
    }
}

/**
 * Изменить ресурс: корень PATCH или идентификатор `{type, id}` с ресурсом в `included`. id — `identity->id`: имя
 * всегда `id`, изменить ресурс с `lid` нельзя по построению.
 *
 * Атрибуты — только присланные: присутствие ключа, а не значение, решает, что колонку трогать (`null` — это NULL в
 * колонку). Их может не быть вовсе (изменяются только связи) — тогда строка ресурса только ищется.
 */
final readonly class UpdateOperation extends WriteOperation
{
    /**
     * @param class-string<Resource>                 $resource      описание ресурса (по `type` документа)
     * @param array<string, mixed>                   $attributes    присланные атрибуты: имя → значение
     * @param array<string, PersistentIdentity|null> $references    связи по внешнему ключу: имя → цель (только
     *                                                              существующий ресурс), null — снять связь
     * @param array<string, RelationshipOperation>   $relationships связи по владению: имя → операции над детьми
     */
    public function __construct(
        string $resource,
        public PersistentIdentity $identity,
        public array $attributes,
        public array $references,
        public array $relationships,
    ) {
        parent::__construct(
            resource: $resource,
        );
    }

    #[\Override]
    public function relationship(string $name): ?RelationshipOperation
    {
        return $this->relationships[$name] ?? null;
    }
}

/**
 * Оставить ребёнка как есть: идентификатор `{type, id}` без ресурса в `included`. Данных нет — только проверка, что
 * ребёнок существует и принадлежит этому родителю. Своего узла плана нет: исполняет ветвь изменения.
 */
final readonly class KeepOperation extends WriteOperation
{
    /**
     * @param class-string<Resource> $resource
     */
    public function __construct(
        string $resource,
        public PersistentIdentity $identity,
    ) {
        parent::__construct(
            resource: $resource,
        );
    }
}

/**
 * Удалить ресурс: корень DELETE или идентификатор `{type, id}` с пометкой `remove`. Данных нет. Имя — только `id`:
 * удалить ресурс с `lid` нельзя по построению (как и оставить — `KeepOperation`).
 */
final readonly class DeleteOperation extends WriteOperation
{
    /**
     * @param class-string<Resource> $resource
     */
    public function __construct(
        string $resource,
        public PersistentIdentity $identity,
    ) {
        parent::__construct(
            resource: $resource,
        );
    }
}

/**
 * Связь по владению: что сделать с детьми одного владельца. Связи нет в документе — нет и ключа в `relationships`
 * владельца, детей не трогают; связь прислана пустой — ключ есть, `children` пуст, это не одно и то же.
 *
 * `replace` — полная замена: дети владельца, которых нет среди `children`, удаляются до остальных операций
 * (`PatchReplaceNode` плана). `children` — уже в порядке выполнения: удаления, затем оставленные и изменённые, затем
 * вставки — освобождённое значение уникальной колонки может занять новый ребёнок. To-one (`delivery`) — тот же
 * список: одна операция или пусто.
 *
 * Как разбор получает операцию из идентификатора связи to-many (правила PATCH):
 *
 *     пометка   идентификатор   ресурс в included   операция
 *     нет       {lid}           есть                InsertOperation
 *     нет       {id}            есть                UpdateOperation
 *     нет       {id}            нет                 KeepOperation
 *     add       {lid}           есть                InsertOperation
 *     update    {id}            есть                UpdateOperation
 *     remove    {id}            нет                 DeleteOperation
 *
 * Остальные сочетания — ошибка документа. Пометка `meta.op` в дерево не попадает: она нужна только для этого выбора
 * и для `replace`. `replace` у to-many — в списке нет ни одной пометки; у to-one (пометок у него нет) — прислан
 * `{lid}` или `null`. У нового владельца (POST, вставленный ребёнок) замены не бывает: удалять нечего.
 */
final readonly class RelationshipOperation
{
    /**
     * @param list<WriteOperation> $children
     */
    public function __construct(
        public bool $replace,
        public array $children,
    ) {}
}

/**
 * Один парсер для POST, PATCH и DELETE: документ → дерево операций (`WriteOperation`). Заменяет оба:
 * `Parser\Insert\DocumentParser` (POST) и прежний парсер PATCH этого файла; POST — частный случай: корень новый, у
 * его потомков только `lid` (существующих детей у нового ресурса нет), пометок `meta.op` нет, полной замены нет.
 *
 * Поток: документ + класс ресурса точки входа (+ id из маршрута у PATCH) → корни дерева операций. Ресурс с данными —
 * `InsertOperation` или `UpdateOperation` (атрибуты, связи по внешнему ключу `references`, связи по владению
 * `relationships`); идентификатор без ресурса — `KeepOperation` или `DeleteOperation`; связь по владению —
 * `RelationshipOperation` (to-one — тот же список из одной операции или пустой), дети уже в порядке выполнения
 * (удаления, оставленные и изменённые, вставки). Объекты документа, `included`, `lid` как ключ поиска и пометка
 * `meta.op` дальше разбора не идут; место в документе (JSON Pointer) разбор знает сам и в дерево не кладёт.
 *
 * Три входа, общее ядро (`included`, `lid`, атрибуты, связи):
 *  - `insert()` — POST: `data` — ресурс или список ресурсов, каждый — новый корень (`InsertOperation`, `Insertable`,
 *    иначе 403): `type` — ресурс точки входа (иначе 409), `id` нет (клиентский id не поддерживается — 403), `lid` не
 *    обязателен; возвращает список корней. Ответ: один ресурс — `201` + `Location` + `{data: {type, id}}`; список —
 *    `201` без `Location`, `data` — идентификаторы в порядке запроса. Вложенный маршрут
 *    (`POST /certificates/1/domains`) — не дело парсера: родитель — `RouteParentNode` плана, id — у исполнителя
 *    (`RouteParentWriter`);
 *  - `update()` — PATCH: `data` — один ресурс, `type` и `id` совпадают с маршрутом (иначе 409); `UpdateOperation`;
 *  - `delete()` — DELETE: документа нет, корень — `DeleteOperation`, ресурс `Deletable` (иначе 403); ответ `204`,
 *    ресурса нет — 404 при выполнении.
 *
 * Проверяет весь документ до первого запроса: форма `attributes`/`relationships` (объекты), атрибут известен,
 * не первичный ключ, не `mode` READ, `null` только у `nullable`; связь известна и у неё есть `data`; по внешнему
 * ключу — `{type, id}` нужного типа или null; по владению — to-many (список) и to-one (ресурс или null); тип и
 * `meta.op` элементов; возможности ресурсов (403): вставка — `Insertable`, изменение (корень PATCH, ребёнок `{id}` +
 * included; изменение связи по внешнему ключу — изменение владельца) — `Updatable`, удаление, полная замена to-many
 * и замена to-one (`lid`, `null`) — `Deletable` цели (по форме документа); у нового ресурса — только новые дети;
 * повторы; каждый ресурс `included` использован ровно один раз (дерево, циклов нет). Ошибки —
 * WriteDocumentException 400/403/409 с JSON Pointer.
 *
 * Не проверяет (как `DocumentParser`): типы значений атрибутов и обязательные атрибуты при вставке (NOT NULL без
 * значения по умолчанию — ошибка базы), существование цели связи по внешнему ключу (ошибка внешнего ключа базы).
 */
final class WriteDocumentParser
{
    /** @var array<string, array{resource: array, pointer: string}> "type:id" или "type:lid" → ресурс из included */
    private array $included = [];

    /** @var array<string, true> использованные ресурсы included */
    private array $used = [];

    /**
     * POST: каждый элемент `data` (ресурс или непустой список ресурсов) — новый корень. У корня нет `id` (403),
     * `lid` не обязателен, `type` — ресурс точки входа (409). Колонку связи вложенного маршрута
     * (`POST /certificates/1/domains`) парсер не знает: это `RouteParentNode` плана и id из маршрута у исполнителя.
     *
     * @param class-string<Resource> $resource ресурс точки входа (`POST /certificates` → CertificateResource)
     * @return list<InsertOperation> корни дерева операций в порядке `data`
     * @throws WriteDocumentException
     */
    public function insert(array $document, string $resource): array
    {
        $data = $document['data'] ?? null;
        if (!is_array($data) || $data === []) {
            throw new WriteDocumentException(
                status: '400',
                pointer: '/data',
                detail: 'data must be a resource or a non-empty list of resources',
            );
        }
        $items = array_is_list($data) ? $data : [$data];
        $pointers = ['/data'];
        if (array_is_list($data)) {
            $pointers = array_map(static fn(int $index): string => "/data/{$index}", array_keys($data));
        }

        $root_keys = [];
        foreach ($items as $index => $item) {
            $pointer = $pointers[$index];
            self::rootType(item: $item, resource: $resource, pointer: $pointer);
            if (isset($item['id'])) {
                throw new WriteDocumentException(
                    status: '403',
                    pointer: "{$pointer}/id",
                    detail: 'client-generated ids are not supported',
                );
            }
            if (!isset($item['lid'])) {
                continue;
            }
            if (!is_string($item['lid']) || $item['lid'] === '') {
                throw new WriteDocumentException(
                    status: '400',
                    pointer: "{$pointer}/lid",
                    detail: 'lid must be a non-empty string',
                );
            }
            $key = "{$resource::type()}:lid:{$item['lid']}";
            if (isset($root_keys[$key])) {
                throw new WriteDocumentException(
                    status: '400',
                    pointer: $pointer,
                    detail: "duplicate resource {$key}",
                );
            }
            $root_keys[$key] = true;
        }
        $this->index(document: $document, root_keys: $root_keys);

        $roots = array_map(
            fn(int $index): InsertOperation => $this->insertOperation(
                item: $items[$index],
                class: $resource,
                pointer: $pointers[$index],
            ),
            array_keys($items),
        );
        $this->checkUsed();

        return $roots;
    }

    /**
     * DELETE `/certificates/{id}`: тела нет (документ JSON:API у DELETE не передаётся) — только проверка возможности и
     * корень `DeleteOperation`. Ресурс не `Deletable` — 403; id не из цифр — 404 (такого ресурса быть не может).
     * Указателя у этих ошибок нет: документа нет. Что удаляется вместе с ресурсом, решает база (каскад, см.
     * `DeleteResourceWriter`); `Deletable` потомков не проверяется.
     *
     * @param class-string<Resource> $resource ресурс точки входа
     * @param string                 $id       id из маршрута
     * @throws WriteDocumentException
     */
    public function delete(string $resource, string $id): DeleteOperation
    {
        if (!ctype_digit($id)) {
            throw new WriteDocumentException(
                status: '404',
                pointer: null,
                detail: "{$resource::type()} {$id} not found",
            );
        }
        if (!is_a($resource, Deletable::class, true)) {
            throw new WriteDocumentException(
                status: '403',
                pointer: null,
                detail: "resource {$resource::type()} can not be deleted",
            );
        }

        return new DeleteOperation(
            resource: $resource,
            identity: new PersistentIdentity(
                id: (int) $id,
            ),
        );
    }

    /**
     * PATCH: `data` — один существующий ресурс точки входа; `type` и `id` совпадают с маршрутом (409).
     *
     * @param class-string<Resource> $resource ресурс точки входа (`PATCH /certificates/{id}` → CertificateResource)
     * @param string                 $id       id из маршрута
     * @throws WriteDocumentException
     */
    public function update(array $document, string $resource, string $id): UpdateOperation
    {
        $data = $document['data'] ?? null;
        if (!is_array($data) || $data === [] || array_is_list($data)) {
            throw new WriteDocumentException(
                status: '400',
                pointer: '/data',
                detail: 'data must be a single resource object',
            );
        }
        self::rootType(item: $data, resource: $resource, pointer: '/data');
        if (($data['id'] ?? null) !== $id) {
            throw new WriteDocumentException(
                status: '409',
                pointer: '/data/id',
                detail: "id must be {$id}, as in the URL",
            );
        }
        $this->index(document: $document, root_keys: ["{$resource::type()}:id:{$id}" => true]);

        $root = $this->updateOperation(item: $data, class: $resource, pointer: '/data');
        $this->checkUsed();

        return $root;
    }

    /**
     * @param class-string<Resource> $resource
     */
    private static function rootType(mixed $item, string $resource, string $pointer): void
    {
        if (!is_array($item) || ($item['type'] ?? null) !== $resource::type()) {
            throw new WriteDocumentException(
                status: '409',
                pointer: "{$pointer}/type",
                detail: "type must be {$resource::type()}",
            );
        }
    }

    /**
     * Индекс `included` по "type:id:<id>" / "type:lid:<lid>"; повтор (в том числе с корнем) — 400.
     *
     * @param array<string, true> $root_keys ключи корней
     */
    private function index(array $document, array $root_keys): void
    {
        $this->included = [];
        $this->used = [];
        $included = $document['included'] ?? [];
        if (!is_array($included) || !array_is_list($included)) {
            throw new WriteDocumentException(
                status: '400',
                pointer: '/included',
                detail: 'included must be a list of resources',
            );
        }
        foreach ($included as $index => $item) {
            $pointer = "/included/{$index}";
            $key = self::identity(item: $item, pointer: $pointer);
            if (isset($this->included[$key]) || isset($root_keys[$key])) {
                throw new WriteDocumentException(
                    status: '400',
                    pointer: $pointer,
                    detail: "duplicate resource {$key}",
                );
            }
            $this->included[$key] = ['resource' => $item, 'pointer' => $pointer];
        }
    }

    /**
     * Каждый ресурс `included` использован (достижим из `data`).
     */
    private function checkUsed(): void
    {
        foreach ($this->included as $key => $item) {
            if (!isset($this->used[$key])) {
                throw new WriteDocumentException(
                    status: '400',
                    pointer: $item['pointer'],
                    detail: "resource {$key} is not referenced from data",
                );
            }
        }
    }

    /**
     * Новый ресурс места `$class` (корень POST или ресурс `included` с `lid`) → `InsertOperation`. Ресурс не
     * `Insertable` — 403.
     *
     * @param class-string<Resource> $class
     */
    private function insertOperation(array $item, string $class, string $pointer): InsertOperation
    {
        self::type(item: $item, class: $class, pointer: $pointer);
        if (!is_a($class, Insertable::class, true)) {
            throw new WriteDocumentException(
                status: '403',
                pointer: $pointer,
                detail: "resource {$class::type()} can not be created",
            );
        }
        $content = $this->content(item: $item, class: $class, pointer: $pointer, owner_is_new: true);

        return new InsertOperation(
            resource: $class,
            identity: isset($item['lid'])
                ? new LocalIdentity(
                    lid: (string) $item['lid'],
                )
                : null,
            attributes: $content['attributes'],
            references: $content['references'],
            relationships: $content['relationships'],
        );
    }

    /**
     * Существующий ресурс места `$class` (корень PATCH или ресурс `included` с `id`) → `UpdateOperation`. Ресурс не
     * `Updatable` — 403, даже если меняются только связи.
     *
     * @param class-string<Resource> $class
     */
    private function updateOperation(array $item, string $class, string $pointer): UpdateOperation
    {
        self::type(item: $item, class: $class, pointer: $pointer);
        if (!is_a($class, Updatable::class, true)) {
            throw new WriteDocumentException(
                status: '403',
                pointer: $pointer,
                detail: "resource {$class::type()} can not be changed",
            );
        }
        $content = $this->content(item: $item, class: $class, pointer: $pointer, owner_is_new: false);

        return new UpdateOperation(
            resource: $class,
            identity: new PersistentIdentity(
                id: (int) $item['id'],
            ),
            attributes: $content['attributes'],
            references: $content['references'],
            relationships: $content['relationships'],
        );
    }

    /**
     * @param class-string<Resource> $class
     */
    private static function type(array $item, string $class, string $pointer): void
    {
        if (($item['type'] ?? null) !== $class::type()) {
            throw new WriteDocumentException(
                status: '400',
                pointer: "{$pointer}/type",
                detail: "type must be {$class::type()}",
            );
        }
    }

    /**
     * Данные ресурса места `$class`: присланные атрибуты, связи по внешнему ключу и разобранные связи по владению.
     *
     * @param class-string<Resource> $class
     * @param bool                   $owner_is_new ресурс вставляется: у его связей только новые дети и нет замены
     * @return array{
     *     attributes: array<string, mixed>,
     *     references: array<string, PersistentIdentity|null>,
     *     relationships: array<string, RelationshipOperation>,
     * }
     */
    private function content(array $item, string $class, string $pointer, bool $owner_is_new): array
    {
        foreach (['attributes', 'relationships'] as $member) {
            $value = $item[$member] ?? [];
            if (!is_array($value) || ($value !== [] && array_is_list($value))) {
                throw new WriteDocumentException(
                    status: '400',
                    pointer: "{$pointer}/{$member}",
                    detail: "{$member} must be an object",
                );
            }
        }

        /** @var array<string, mixed> $attributes */
        $attributes = $item['attributes'] ?? [];
        foreach (array_keys($attributes) as $name) {
            if (!isset($class::ATTRIBUTES[$name])) {
                throw new WriteDocumentException(
                    status: '400',
                    pointer: "{$pointer}/attributes/{$name}",
                    detail: "unknown attribute {$name} of {$class::type()}",
                );
            }
            $read_only = ($class::ATTRIBUTES[$name]['mode'] ?? null) === Resource::READ;
            if ($name === $class::primaryAttribute() || $read_only) {
                throw new WriteDocumentException(
                    status: '400',
                    pointer: "{$pointer}/attributes/{$name}",
                    detail: "attribute {$name} is read-only",
                );
            }
            if ($attributes[$name] === null && !($class::ATTRIBUTES[$name]['nullable'] ?? false)) {
                throw new WriteDocumentException(
                    status: '400',
                    pointer: "{$pointer}/attributes/{$name}",
                    detail: "{$name} can not be null",
                );
            }
        }

        $references = [];
        $relationships = [];
        /** @var array<string, mixed> $sent */
        $sent = $item['relationships'] ?? [];
        foreach ($sent as $name => $value) {
            $name = (string) $name;
            $relationship_pointer = "{$pointer}/relationships/{$name}";
            $relationship = $class::relationship($name);
            if ($relationship === null) {
                throw new WriteDocumentException(
                    status: '400',
                    pointer: $relationship_pointer,
                    detail: "unknown relationship {$name} of {$class::type()}",
                );
            }
            if (!is_array($value) || !array_key_exists('data', $value)) {
                throw new WriteDocumentException(
                    status: '400',
                    pointer: $relationship_pointer,
                    detail: 'data is required',
                );
            }
            $owner_side = $relationship->relation[0] === $class::primaryColumn();
            if ($class::foreignRelationship($name) !== null) {
                $references[$name] = self::reference(
                    name: $name,
                    target: $relationship->resource,
                    value: $value['data'],
                    pointer: $relationship_pointer,
                );
            } elseif ($relationship instanceof OneToMany && $owner_side) {
                $relationships[$name] = $this->children(
                    target: $relationship->resource,
                    value: $value,
                    pointer: $relationship_pointer,
                    parent_is_new: $owner_is_new,
                );
            } elseif ($relationship instanceof OneToOne && $owner_side) {
                $relationships[$name] = $this->child(
                    target: $relationship->resource,
                    value: $value,
                    pointer: $relationship_pointer,
                    parent_is_new: $owner_is_new,
                );
            } else {
                throw new WriteDocumentException(
                    status: '400',
                    pointer: $relationship_pointer,
                    detail: "{$name} can not be changed here",
                );
            }
        }

        return ['attributes' => $attributes, 'references' => $references, 'relationships' => $relationships];
    }

    /**
     * Связь по внешнему ключу: `{type, id}` нужного типа → цель, `null` → null (без связи). `lid` недопустим: цель —
     * только существующий ресурс.
     *
     * @param class-string<Resource> $target
     */
    private static function reference(string $name, string $target, mixed $value, string $pointer): ?PersistentIdentity
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || ($value['type'] ?? null) !== $target::type()) {
            throw new WriteDocumentException(
                status: '400',
                pointer: "{$pointer}/data/type",
                detail: "type must be {$target::type()}",
            );
        }
        $id = $value['id'] ?? null;
        if (isset($value['lid']) || !is_string($id) || !ctype_digit($id)) {
            throw new WriteDocumentException(
                status: '400',
                pointer: "{$pointer}/data",
                detail: "{$name} refers to an existing resource: {type, id} or null",
            );
        }

        return new PersistentIdentity(
            id: (int) $id,
        );
    }

    /**
     * Список связи по владению → операции над детьми в порядке выполнения и признак полной замены. Правила — в
     * докблоке `RelationshipOperation`.
     *
     * @param class-string<Resource> $target
     */
    private function children(string $target, mixed $value, string $pointer, bool $parent_is_new): RelationshipOperation
    {
        $identifiers = is_array($value) ? ($value['data'] ?? null) : null;
        if (!is_array($identifiers) || !array_is_list($identifiers)) {
            throw new WriteDocumentException(
                status: '400',
                pointer: "{$pointer}/data",
                detail: 'expected a list of resource identifiers',
            );
        }
        $marked = array_filter($identifiers, static fn(mixed $identifier): bool => isset($identifier['meta']['op']));
        if ($marked === [] && !$parent_is_new) {
            self::deletable(
                target: $target,
                pointer: "{$pointer}/data",
                detail: "a list without meta.op replaces the relationship and deletes unlisted {$target::type()}, "
                    . 'which can not be deleted: mark elements with meta.op for a partial change',
            );
        }

        $children = [];
        $seen = [];
        foreach ($identifiers as $position => $identifier) {
            $identifier_pointer = "{$pointer}/data/{$position}";
            $key = self::identity(item: $identifier, pointer: $identifier_pointer);
            if (($identifier['type'] ?? null) !== $target::type()) {
                throw new WriteDocumentException(
                    status: '400',
                    pointer: "{$identifier_pointer}/type",
                    detail: "type must be {$target::type()}",
                );
            }
            if (isset($seen[$key])) {
                throw new WriteDocumentException(
                    status: '400',
                    pointer: $identifier_pointer,
                    detail: "{$key} is listed twice",
                );
            }
            $seen[$key] = true;
            $marker = $identifier['meta']['op'] ?? null;
            if ($marker !== null && !in_array($marker, ['add', 'update', 'remove'], true)) {
                throw new WriteDocumentException(
                    status: '400',
                    pointer: "{$identifier_pointer}/meta/op",
                    detail: 'op must be add, update or remove',
                );
            }
            $included = $this->included[$key] ?? null;

            // Новый ребёнок: только с ресурсом в included
            if (isset($identifier['lid'])) {
                if ($marker !== null && $marker !== 'add') {
                    throw new WriteDocumentException(
                        status: '400',
                        pointer: "{$identifier_pointer}/meta/op",
                        detail: 'a resource with lid can only be added',
                    );
                }
                if ($included === null) {
                    throw new WriteDocumentException(
                        status: '400',
                        pointer: $identifier_pointer,
                        detail: "{$key} is not in included",
                    );
                }
                $this->used[$key] = true;
                $children[] = $this->insertOperation(
                    item: $included['resource'],
                    class: $target,
                    pointer: $included['pointer'],
                );
                continue;
            }

            // Существующий ребёнок: у нового родителя их нет
            if ($parent_is_new) {
                throw new WriteDocumentException(
                    status: '400',
                    pointer: $identifier_pointer,
                    detail: 'a new resource has no existing children: use lid',
                );
            }
            if ($marker === 'add') {
                throw new WriteDocumentException(
                    status: '400',
                    pointer: "{$identifier_pointer}/meta/op",
                    detail: 'moving an existing resource to another parent is not supported',
                );
            }
            $identity = new PersistentIdentity(
                id: (int) $identifier['id'],
            );
            if ($marker === 'remove') {
                self::deletable(
                    target: $target,
                    pointer: $identifier_pointer,
                    detail: "{$target::type()} can not be deleted",
                );
                if ($included !== null) {
                    throw new WriteDocumentException(
                        status: '400',
                        pointer: $included['pointer'],
                        detail: "{$key} is removed, it can not be in included",
                    );
                }
                $children[] = new DeleteOperation(
                    resource: $target,
                    identity: $identity,
                );
                continue;
            }
            if ($included !== null) {
                $this->used[$key] = true;
                $children[] = $this->updateOperation(
                    item: $included['resource'],
                    class: $target,
                    pointer: $included['pointer'],
                );
                continue;
            }
            if ($marker === 'update') {
                throw new WriteDocumentException(
                    status: '400',
                    pointer: $identifier_pointer,
                    detail: "update needs {$key} in included",
                );
            }
            $children[] = new KeepOperation(
                resource: $target,
                identity: $identity,
            );
        }

        usort(
            $children,
            static fn(WriteOperation $left, WriteOperation $right): int => self::order(operation: $left)
                <=> self::order(operation: $right),
        );

        return new RelationshipOperation(
            replace: $marked === [] && !$parent_is_new,
            children: $children,
        );
    }

    /**
     * Место операции в порядке выполнения внутри связи: удаления, затем оставленные и изменённые, затем вставки.
     */
    private static function order(WriteOperation $operation): int
    {
        if ($operation instanceof DeleteOperation) {
            return 0;
        }
        if ($operation instanceof InsertOperation) {
            return 2;
        }

        return 1;
    }

    /**
     * Связь to-one по владению (`delivery`) → операция над ребёнком (список из одной или пустой) и признак замены.
     * `meta.op` не нужен, операцию даёт форма:
     *
     *     {type, lid} + ресурс в included   замена: текущий ребёнок удаляется, новый вставляется (InsertOperation)
     *     {type, id}  + ресурс в included   UpdateOperation текущего ребёнка (чужой — 404)
     *     {type, id}                        KeepOperation: только проверка, что это текущий ребёнок
     *     null                              замена без нового: текущий ребёнок удаляется (его нет — не ошибка)
     *
     * У нового родителя: `{lid}` — вставка, `null` — ничего, `{id}` — 400. Замена исполняется тем же
     * `PatchReplaceWriter`, что и полная замена to-many: `DELETE … WHERE <колонка связи> = родитель` без названных id,
     * потом вставка (`PatchJoinWriter`).
     *
     * @param class-string<Resource> $target
     */
    private function child(string $target, mixed $value, string $pointer, bool $parent_is_new): RelationshipOperation
    {
        $is_list = is_array($value) && is_array($value['data'] ?? null) && array_is_list($value['data']);
        if (!is_array($value) || !array_key_exists('data', $value) || ($is_list && $value['data'] !== [])) {
            throw new WriteDocumentException(
                status: '400',
                pointer: "{$pointer}/data",
                detail: 'expected a resource identifier or null',
            );
        }
        $identifier = $value['data'];
        $identifier_pointer = "{$pointer}/data";
        if ($identifier === null || $identifier === []) {
            if (!$parent_is_new) {
                self::deletable(
                    target: $target,
                    pointer: $identifier_pointer,
                    detail: "null deletes the current {$target::type()}, which can not be deleted",
                );
            }

            return new RelationshipOperation(
                replace: !$parent_is_new,
                children: [],
            );
        }
        $key = self::identity(item: $identifier, pointer: $identifier_pointer);
        if (($identifier['type'] ?? null) !== $target::type()) {
            throw new WriteDocumentException(
                status: '400',
                pointer: "{$identifier_pointer}/type",
                detail: "type must be {$target::type()}",
            );
        }
        if (isset($identifier['meta']['op'])) {
            throw new WriteDocumentException(
                status: '400',
                pointer: "{$identifier_pointer}/meta/op",
                detail: 'a to-one relationship takes no op: lid replaces, id keeps or updates, null removes',
            );
        }
        $included = $this->included[$key] ?? null;

        if (isset($identifier['lid'])) {
            if ($included === null) {
                throw new WriteDocumentException(
                    status: '400',
                    pointer: $identifier_pointer,
                    detail: "{$key} is not in included",
                );
            }
            $this->used[$key] = true;
            if (!$parent_is_new) {
                self::deletable(
                    target: $target,
                    pointer: $identifier_pointer,
                    detail: "lid replaces the current {$target::type()}, which can not be deleted",
                );
            }

            return new RelationshipOperation(
                replace: !$parent_is_new,
                children: [
                    $this->insertOperation(
                        item: $included['resource'],
                        class: $target,
                        pointer: $included['pointer'],
                    ),
                ],
            );
        }

        if ($parent_is_new) {
            throw new WriteDocumentException(
                status: '400',
                pointer: $identifier_pointer,
                detail: 'a new resource has no existing children: use lid',
            );
        }
        if ($included !== null) {
            $this->used[$key] = true;

            return new RelationshipOperation(
                replace: false,
                children: [
                    $this->updateOperation(
                        item: $included['resource'],
                        class: $target,
                        pointer: $included['pointer'],
                    ),
                ],
            );
        }

        return new RelationshipOperation(
            replace: false,
            children: [
                new KeepOperation(
                    resource: $target,
                    identity: new PersistentIdentity(
                        id: (int) $identifier['id'],
                    ),
                ),
            ],
        );
    }

    /**
     * Документ может удалить ресурсы цели связи — цель обязана быть `Deletable`, иначе 403.
     *
     * @param class-string<Resource> $target
     */
    private static function deletable(string $target, string $pointer, string $detail): void
    {
        if (!is_a($target, Deletable::class, true)) {
            throw new WriteDocumentException(
                status: '403',
                pointer: $pointer,
                detail: $detail,
            );
        }
    }

    /**
     * "type:id:<id>" или "type:lid:<lid>"; ровно одно из `id`/`lid`.
     */
    private static function identity(mixed $item, string $pointer): string
    {
        if (!is_array($item) || !is_string($item['type'] ?? null) || isset($item['id']) === isset($item['lid'])) {
            throw new WriteDocumentException(
                status: '400',
                pointer: $pointer,
                detail: 'expected type and exactly one of id, lid',
            );
        }
        if (isset($item['id']) && !(is_string($item['id']) && ctype_digit($item['id']))) {
            throw new WriteDocumentException(
                status: '400',
                pointer: "{$pointer}/id",
                detail: 'id must be a string of digits',
            );
        }

        return isset($item['id']) ? "{$item['type']}:id:{$item['id']}" : "{$item['type']}:lid:{$item['lid']}";
    }
}

// ---------------------------------------------------------------------------------------------------------------
// План PATCH: неизменяемые узлы и планировщик (пара к Plan\Insert); исполнителей по плану строит PatchWriterBuilder

/**
 * Узел плана PATCH: место дерева операций — ресурс и ветви по операциям, которые встретились у ресурсов этого места.
 * Исполнитель — `PatchOperationWriter`. Ветви нет (`null`) — операции нет в документе: `add` — поддерево вставки
 * (`Plan\Insert`) для `InsertOperation`, `update` — изменения для `UpdateOperation` (его же исполняет
 * `KeepOperation`), `remove` — удаления для `DeleteOperation`.
 */
final readonly class PatchOperationNode
{
    /**
     * @param class-string<Resource> $resource
     */
    public function __construct(
        public string $resource,
        public ?InsertResourceNode $add,
        public ?UpdateResourceNode $update,
        public ?DeleteResourceNode $remove,
    ) {}
}

/**
 * Узел плана PATCH: связь по владению (to-many и to-one) `name` между ресурсами. Исполнитель — `PatchJoinWriter`.
 * Связи одного владельца — левый глубокий список, как `InsertJoinNode`. Только передаёт детей: полная замена —
 * отдельный узел `PatchReplaceNode` под `parent`. У связи нет ни одного элемента — узла связи нет.
 */
final readonly class PatchJoinNode
{
    /**
     * @param array{0: string, 1: string} $relation [колонка владельца, колонка ребёнка]
     */
    public function __construct(
        public string $name,
        public array $relation,
        public PatchJoinNode|PatchReplaceNode|PatchOperationNode $parent,
        public PatchJoinNode|PatchReplaceNode|PatchOperationNode $child,
    ) {}
}

/**
 * Узел плана PATCH: полная замена связи по владению `name` — удаление детей владельца, не названных в документе.
 * Исполнитель — `PatchReplaceWriter`. Один вход — владелец (`input`); стоит под `PatchJoinNode` той же связи как его
 * `parent`, а при замене пустым списком (`[]`, `null` у to-one) — без узла связи над собой.
 *
 * Узел есть, если связь заменяет хотя бы один владелец этого места; какой именно — говорит
 * `RelationshipOperation::$replace` в дереве операций (как класс операции для ветвей `PatchOperationNode`). У POST
 * его не бывает.
 */
final readonly class PatchReplaceNode
{
    /**
     * @param array{0: string, 1: string} $relation       [колонка владельца, колонка ребёнка]
     * @param class-string<Resource>      $child_resource
     */
    public function __construct(
        public string $name,
        public array $relation,
        public string $child_resource,
        public PatchJoinNode|PatchReplaceNode|PatchOperationNode $input,
    ) {}
}

/**
 * Узел плана изменения: граница уровней — ресурс и поддерево таблиц его модели. Исполнитель — `UpdateResourceWriter`.
 */
final readonly class UpdateResourceNode
{
    /**
     * @param class-string<Resource> $resource
     */
    public function __construct(
        public string $resource,
        public UpdateTableJoinNode|UpdateTableNode $input,
    ) {}
}

/**
 * Узел плана изменения: соединение двух частей модели, `main` (её ключ известен) раньше `joined`. Исполнитель —
 * `UpdateTableJoinWriter`.
 */
final readonly class UpdateTableJoinNode
{
    /**
     * @param array{0: string, 1: string} $relation [колонка стороны main, колонка стороны joined]
     */
    public function __construct(
        public array $relation,
        public UpdateTableJoinNode|UpdateTableNode $main,
        public UpdateTableJoinNode|UpdateTableNode|UpdateOptionalTableNode $joined,
    ) {}
}

/**
 * Узел плана изменения: необязательная часть модели (правая сторона `LeftJoin`, одна таблица) — изменение, а если
 * строки нет, вставка. Исполнитель — `UpdateOptionalTableWriter`.
 */
final readonly class UpdateOptionalTableNode
{
    public function __construct(
        public UpdateTableNode $update,
        public InsertTableNode $insert,
    ) {}
}

/**
 * Узел плана изменения: одна таблица. Исполнитель — `UpdateTableWriter`.
 */
final readonly class UpdateTableNode
{
    /**
     * @param class-string<Entity> $entity
     * @param list<string>         $columns   колонки модели ресурса в этой таблице
     * @param string               $key       колонка поиска строки
     * @param list<string>         $returning колонки, которые нужны правым сторонам
     */
    public function __construct(
        public string $entity,
        public array $columns,
        public string $key,
        public array $returning,
    ) {}
}

/**
 * Узел плана PATCH: удаление ресурса (строка главной таблицы). Исполнитель — `DeleteResourceWriter`.
 */
final readonly class DeleteResourceNode
{
    /**
     * @param class-string<Resource> $resource
     */
    public function __construct(
        public string $resource,
    ) {}
}

/**
 * Корень плана записи: дерево ресурсов (`input`) и привязка к родителю из вложенного маршрута (`route`, null — маршрут
 * не вложенный). Исполнитель — `WriteRootWriter`: сначала проверки по всему запросу, потом корни документа по
 * очереди. Сюда же лягут проверки целей связей по внешнему ключу (пока не делаются: несуществующая цель — ошибка
 * внешнего ключа базы, 500): им тоже нужны id всех элементов места до первой записи.
 */
final readonly class WriteRootNode
{
    public function __construct(
        public PatchJoinNode|PatchReplaceNode|PatchOperationNode $input,
        public ?RouteParentNode $route,
    ) {}
}

/**
 * Узел плана записи: родитель из вложенного маршрута (`POST /certificates/{id}/domains`). Форма — какой ресурс
 * родитель и колонки связи; id — значение из маршрута, в плане его нет (его получает `PatchWriterBuilder::root()`).
 * Исполнитель — `RouteParentWriter`: проверка, что родитель есть, и колонка связи для корней.
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

/**
 * Планировщик PATCH: ресурс точки входа + дерево операций (`WriteDocumentParser`) → план (неизменяемые узлы выше).
 * Пара к `InsertPlanner`; исполнителей по плану строит `PatchWriterBuilder`.
 *
 * Поток: класс ресурса, корни дерева операций (+ родитель и связь вложенного маршрута) → `WriteRootNode` над
 * `PatchJoinNode|PatchReplaceNode|PatchOperationNode`. Корни — одно место (несколько корней POST — объединение, как у
 * `InsertPlanner`). Вложенный маршрут — форма (`RouteParentNode`: кто родитель, колонки связи), его id — значение,
 * в план не попадает.
 *
 * План строится по форме дерева операций, как план вставки по документу: место — ресурс и все его операции этого
 * места (корень; все дети одной связи у всех родителей места). У места:
 *  - `PatchOperationNode` с ветвями только тех операций, что встретились в месте (`InsertOperation` → `add`:
 *    `InsertResourceNode` с таблицами `InsertPlanner::tables()`; `UpdateOperation`/`KeepOperation` → `update`:
 *    `UpdateResourceNode` с таблицами `tables()`; `DeleteOperation` → `remove`: `DeleteResourceNode`);
 *  - связи, которые есть хотя бы у одного элемента места, — левый глубокий список над ним: связи по владению
 *    (`relationships()`, в порядке объявления) — сначала `PatchReplaceNode`, если связь заменяет целиком хотя бы
 *    один элемент места, потом `PatchJoinNode`, если у связи есть хотя бы один ребёнок (его `child` — место из детей
 *    связи). Отсюда порядок выполнения: владелец, удаление неназванных, дети. Замена пустым списком — узел замены
 *    без узла связи; у POST узла замены нет, план равен плану `InsertPlanner`. Связи по внешнему ключу пишет строка
 *    ресурса, узла нет.
 *
 * Форма плана говорит, что в месте бывает; для какого элемента — данные: класс операции выбирает ветвь
 * `PatchOperationNode`, `RelationshipOperation::$replace` — выполнять ли удаление в `PatchReplaceNode`. Поэтому
 * вставка, удаление и изменение в одной связи — один узел места с тремя ветвями. Вход проверен парсером;
 * несоответствие — LogicException.
 *
 * Уровень таблиц изменения (`tables()`): сначала сторона, ключ которой уже известен (левая, с главной таблицей; её
 * ключ — id ресурса), потом правая — по ключу, который вернула левая, независимо от направления внешнего ключа (у
 * вставки наоборот: первым родитель по внешнему ключу). Соединение → `UpdateTableJoinNode(relation, main: левая,
 * joined: правая)`; правая сторона `LeftJoin` → `UpdateOptionalTableNode(UpdateTableNode, InsertTableNode)`; таблица
 * → `UpdateTableNode(key, columns, returning)`: `key` главной — первичный ключ ресурса, `key` правой — `relation[1]`;
 * `returning` — колонки `relation[0]` соединений, которые в ней лежат. Ограничения: `key` правой стороны — в её крайней
 * левой таблице; необязательная правая сторона — одна таблица (иначе LogicException).
 */
final class PatchPlanner
{
    /**
     * @param class-string<Resource>      $resource     ресурс точки входа
     * @param list<WriteOperation>        $roots        корни дерева операций: список из `insert()`, `[update()]`
     *                                                  или `[delete()]`
     * @param class-string<Resource>|null $parent       родитель вложенного маршрута (`/certificates/{id}/domains` →
     *                                                  CertificateResource), null — маршрут не вложенный
     * @param string|null                 $relationship связь родителя к ресурсу точки входа (`domains`)
     */
    public function plan(string $resource, array $roots, ?string $parent = null, ?string $relationship = null): WriteRootNode
    {
        return new WriteRootNode(
            input: $this->place(resource: $resource, operations: $roots),
            route: $parent === null ? null : self::route(parent: $parent, relationship: (string) $relationship, resource: $resource),
        );
    }

    /**
     * Связь родителя маршрута: первичный ключ на стороне родителя, цель — ресурс точки входа.
     *
     * @param class-string<Resource> $parent
     * @param class-string<Resource> $resource
     */
    private static function route(string $parent, string $relationship, string $resource): RouteParentNode
    {
        $definition = $parent::relationship($relationship) ?? throw new LogicException("{$parent}: unknown relationship {$relationship}");
        /** @var array{0: string, 1: string} $relation */
        $relation = $definition->relation;
        if ($definition->resource !== $resource || $relation[0] !== $parent::primaryColumn()) {
            throw new LogicException("{$parent}::{$relationship} is not an owner-side relationship to {$resource}");
        }

        return new RouteParentNode(
            parent: $parent,
            relation: $relation,
        );
    }

    /**
     * @param class-string<Resource> $resource
     * @param list<WriteOperation>   $operations операции этого места
     */
    private function place(string $resource, array $operations): PatchJoinNode|PatchReplaceNode|PatchOperationNode
    {
        $inserts = array_any(
            $operations,
            static fn(WriteOperation $operation): bool => $operation instanceof InsertOperation,
        );
        $updates = array_any(
            $operations,
            static fn(WriteOperation $operation): bool => $operation instanceof UpdateOperation
                || $operation instanceof KeepOperation,
        );
        $deletes = array_any(
            $operations,
            static fn(WriteOperation $operation): bool => $operation instanceof DeleteOperation,
        );
        $node = new PatchOperationNode(
            resource: $resource,
            add: $inserts
                ? new InsertResourceNode(
                    resource: $resource,
                    input: new InsertPlanner()->tables(resource: $resource),
                )
                : null,
            update: $updates
                ? new UpdateResourceNode(
                    resource: $resource,
                    input: $this->tables(resource: $resource),
                )
                : null,
            remove: $deletes
                ? new DeleteResourceNode(
                    resource: $resource,
                )
                : null,
        );

        foreach (array_keys($resource::relationships()) as $name) {
            $name = (string) $name;
            // Только связи по владению: связь по внешнему ключу лежит в `references`, её пишет строка ресурса
            $present = array_values(array_filter(
                array_map(
                    static fn(WriteOperation $operation): ?RelationshipOperation => $operation->relationship(
                        name: $name,
                    ),
                    $operations,
                ),
                static fn(?RelationshipOperation $relationship): bool => $relationship !== null,
            ));
            if ($present === []) {
                continue;
            }
            $relationship = $resource::relationship($name)
                ?? throw new LogicException("{$resource}: unknown relationship {$name}");
            /** @var array{0: string, 1: string} $relation */
            $relation = $relationship->relation;
            $children = array_merge(...array_map(
                static fn(RelationshipOperation $present_relationship): array => $present_relationship->children,
                $present,
            ));
            $replaces = array_any(
                $present,
                static fn(RelationshipOperation $present_relationship): bool => $present_relationship->replace,
            );
            if ($replaces) {
                $node = new PatchReplaceNode(
                    name: $name,
                    relation: $relation,
                    child_resource: $relationship->resource,
                    input: $node,
                );
            }
            if ($children !== []) {
                $node = new PatchJoinNode(
                    name: $name,
                    relation: $relation,
                    parent: $node,
                    child: $this->place(resource: $relationship->resource, operations: $children),
                );
            }
        }

        return $node;
    }

    /**
     * Поддерево таблиц изменения по `model()` ресурса.
     *
     * @param class-string<Resource> $resource
     */
    public function tables(string $resource): UpdateTableJoinNode|UpdateTableNode
    {
        $tree = ModelTree::of($resource);
        $columns = [];
        foreach ($tree->columns() as $column) {
            $columns[(int) $tree->columnTable($column)][] = $column;
        }
        /** @var Join|class-string<Entity> $model */
        $model = $resource::model();
        $returning = [];
        self::returning(model: $model, tree: $tree, returning: $returning);
        $leaf = 0;

        return self::table(model: $model, key: $resource::primaryColumn(), columns: $columns, returning: $returning, leaf: $leaf);
    }

    /**
     * @param Join|class-string<Entity> $model
     * @param array<int, list<string>>  $columns   номер таблицы → её колонки модели
     * @param array<int, list<string>>  $returning номер таблицы → колонки для правых сторон
     * @param int                       $leaf      номер следующей таблицы слева направо
     */
    private static function table(Join|string $model, string $key, array $columns, array $returning, int &$leaf): UpdateTableJoinNode|UpdateTableNode
    {
        if (is_string($model)) {
            if (!isset($model::COLUMNS[$key])) {
                throw new LogicException("{$model}: key {$key} must be a column of the leftmost table of its side");
            }
            $number = $leaf++;

            return new UpdateTableNode(
                entity: $model,
                columns: $columns[$number] ?? [],
                key: $key,
                returning: $returning[$number] ?? [],
            );
        }

        /** @var array{0: string, 1: string} $relation */
        $relation = $model->relation;
        $main = self::table(model: $model->left, key: $key, columns: $columns, returning: $returning, leaf: $leaf);
        $joined = self::table(model: $model->right, key: $relation[1], columns: $columns, returning: $returning, leaf: $leaf);
        if ($model instanceof LeftJoin) {
            if (!$joined instanceof UpdateTableNode) {
                throw new LogicException('an optional side of several tables is not supported');
            }
            $joined = new UpdateOptionalTableNode(
                update: $joined,
                insert: new InsertTableNode(
                    entity: $joined->entity,
                    columns: $joined->columns,
                ),
            );
        }

        return new UpdateTableJoinNode(
            relation: $relation,
            main: $main,
            joined: $joined,
        );
    }

    /**
     * Колонка `relation[0]` каждого соединения → в `returning` таблицы, где она лежит.
     *
     * @param Join|class-string<Entity> $model
     * @param array<int, list<string>>  $returning
     */
    private static function returning(Join|string $model, ModelTree $tree, array &$returning): void
    {
        if (is_string($model)) {
            return;
        }
        $column = (string) $model->relation[0];
        $returning[(int) ($tree->columnTable($column) ?? throw new LogicException("{$column} is not a model column"))][] = $column;
        self::returning(model: $model->left, tree: $tree, returning: $returning);
        self::returning(model: $model->right, tree: $tree, returning: $returning);
    }
}

// ---------------------------------------------------------------------------------------------------------------
// Исполнители

/**
 * Контракт исполнителей уровня ресурсов этого файла: как `Executor\PushExecutor`, но на вход идёт операция дерева
 * (`WriteOperation`), а не ресурс документа массивом.
 *
 * Поток: операция + `$link` (колонки связи от родителя: колонка → значение) → ключи ресурса (колонка модели →
 * значение) наверх; пусто — строки нет.
 *
 * @temporary Только для прототипа. В `src/` это станет самим `PushExecutor`: его `push()` будет принимать операцию,
 *            когда вставка (`InsertResourceWriter`, `InsertJoinWriter`) перейдёт на дерево операций.
 */
interface OperationPushExecutor
{
    /**
     * @param array<string, mixed> $link
     * @return array<string, mixed>
     */
    public function push(WriteOperation $operation, array $link = []): array;

    /**
     * Описание поддерева без выполнения.
     *
     * @return array<string, mixed>
     */
    public function explain(): array;
}

/**
 * Корень записи: проверки по всему запросу, затем каждый корень документа в одно дерево. Вместо пары
 * `InsertRootWriter` + `InsertRouteLinkWriter` из `src/`: проверка родителя маршрута должна пройти один раз и до
 * первой записи, а не на каждом корне. Сам не исполнитель уровня ресурсов: сверху ему ничего не приходит.
 *
 * Поток: `route->link()` (проверка родителя, колонки связи; null — маршрут не вложенный) → `root->push(корень, link)`
 * по очереди → `list<ключи корня>`.
 *
 * Транзакцию не открывает: атомарность — у вызывающего.
 */
final readonly class WriteRootWriter
{
    /**
     * @param list<WriteOperation> $data корни дерева операций
     */
    public function __construct(
        private OperationPushExecutor $root,
        private array $data,
        private ?RouteParentWriter $route,
    ) {}

    /**
     * @return list<array<string, mixed>>
     * @throws WriteDocumentException 404 — родителя из маршрута нет; 404 при выполнении дерева
     */
    public function execute(): array
    {
        $link = $this->route?->link() ?? [];

        return array_map(
            fn(WriteOperation $operation): array => $this->root->push($operation, $link),
            $this->data,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function explain(): array
    {
        return [
            'node' => static::class,
            'data' => count($this->data),
            ...($this->route === null ? [] : ['route' => $this->route->explain()]),
            'root' => $this->root->explain(),
        ];
    }
}

/**
 * Родитель из вложенного маршрута: проверка, что он есть, и колонки связи для корней.
 *
 * Поток: id из маршрута → `Tables::existing(parent, [id], lock)` (`SELECT <pk> FROM <главная таблица родителя> WHERE
 * <pk> IN (:id)` [`FOR KEY SHARE`]) → нет — 404 без указателя (родителя нет в документе); невидимый пользователю
 * родитель (view/RLS) — тоже 404, а не вставка к чужому; есть — `[relation[1] => id]`.
 *
 * `lock` = `FOR KEY SHARE`: родителя не удалят между проверкой и вставкой (PostgreSQL); в SQLite выключен.
 */
final readonly class RouteParentWriter
{
    /**
     * @param class-string<Resource>      $parent
     * @param array{0: string, 1: string} $relation [первичный ключ родителя, колонка связи ребёнка]
     */
    public function __construct(
        private PDO $pdo,
        private string $parent,
        private array $relation,
        private int $id,
        private bool $lock,
    ) {}

    /**
     * @return array<string, int>
     * @throws WriteDocumentException 404
     */
    public function link(): array
    {
        if (Tables::existing(pdo: $this->pdo, resource: $this->parent, ids: [$this->id], lock: $this->lock) === []) {
            throw new WriteDocumentException(
                status: '404',
                pointer: null,
                detail: "{$this->parent::type()} {$this->id} not found",
            );
        }

        return [$this->relation[1] => $this->id];
    }

    /**
     * @return array<string, mixed>
     */
    public function explain(): array
    {
        return [
            'node' => static::class,
            'parent' => $this->parent,
            'relation' => $this->relation,
            'id' => $this->id,
            'lock' => $this->lock,
        ];
    }
}

/**
 * Развилка по классу операции: `InsertOperation` → вставка (ветвь `add`), `UpdateOperation`/`KeepOperation` →
 * изменение или проверка (ветвь `update`), `DeleteOperation` → удаление (ветвь `remove`). Пустые ключи у изменения,
 * проверки и удаления — 404 с ресурсом в `meta` (`{type, id}`). Ветви есть только для операций, которые встретились
 * в документе (`PatchPlanner`); операция без ветви — LogicException.
 *
 * Поток: операция + `$link` → исполнитель ветви → ключи наверх (их берёт PatchJoinWriter для детей).
 */
final readonly class PatchOperationWriter implements OperationPushExecutor
{
    /**
     * @param class-string<Resource> $resource
     */
    public function __construct(
        private string $resource,
        private ?OperationPushExecutor $add,
        private ?OperationPushExecutor $update,
        private ?OperationPushExecutor $remove,
    ) {}

    #[\Override]
    public function push(WriteOperation $operation, array $link = []): array
    {
        if ($operation instanceof InsertOperation) {
            $branch = $this->add ?? throw new LogicException("{$this->resource}: no add branch in the plan");

            return $branch->push($operation, $link);
        }
        if ($operation instanceof UpdateOperation || $operation instanceof KeepOperation) {
            $branch = $this->update ?? throw new LogicException("{$this->resource}: no update branch in the plan");
        } elseif ($operation instanceof DeleteOperation) {
            $branch = $this->remove ?? throw new LogicException("{$this->resource}: no remove branch in the plan");
        } else {
            throw new LogicException('Unknown operation ' . $operation::class);
        }

        $keys = $branch->push($operation, $link);
        if ($keys === []) {
            $type = $operation->resource::type();
            throw new WriteDocumentException(
                status: '404',
                pointer: null,
                detail: "{$type} {$operation->identity->id} not found"
                    . ($link === [] ? '' : ' under ' . json_encode($link)),
                meta: ['type' => $type, ...$operation->identity->toMeta()],
            );
        }

        return $keys;
    }

    #[\Override]
    public function explain(): array
    {
        return [
            'node' => static::class,
            'resource' => $this->resource,
            ...array_filter([
                'add' => $this->add?->explain(),
                'update' => $this->update?->explain(),
                'remove' => $this->remove?->explain(),
            ]),
        ];
    }
}

/**
 * Вставка ресурса по `InsertOperation`: переводит операцию в ресурс документа, который читает
 * `Executor\Insert\InsertResourceWriter` из `src/` (`input`), — `{type, attributes, relationships}`, где в связях
 * только связи по внешнему ключу (`references`) как `{data: {type, id} | null}`. Связи по владению не передаются:
 * детей вставляет `PatchJoinWriter`.
 *
 * Поток: `InsertOperation` + `$link` → `input->push(ресурс, link)` → ключи вставленного ресурса.
 *
 * @temporary Только для прототипа: переходник к вставке из `src/`, которая принимает массив. Когда
 *            `InsertResourceWriter` будет принимать `InsertOperation`, этот класс не нужен.
 */
final readonly class InsertOperationWriter implements OperationPushExecutor
{
    /**
     * @param class-string<Resource> $resource
     */
    public function __construct(
        private string $resource,
        private PushExecutor $input,
    ) {}

    #[\Override]
    public function push(WriteOperation $operation, array $link = []): array
    {
        if (!$operation instanceof InsertOperation) {
            throw new LogicException("{$this->resource}: insert needs InsertOperation, got " . $operation::class);
        }
        $relationships = [];
        foreach ($operation->references as $name => $target) {
            $relationship = $this->resource::relationship($name)
                ?? throw new LogicException("{$this->resource}: unknown relationship {$name}");
            $relationships[$name] = [
                'data' => $target === null
                    ? null
                    : ['type' => $relationship->resource::type(), 'id' => (string) $target->id],
            ];
        }

        return $this->input->push(
            [
                'type' => $this->resource::type(),
                'attributes' => $operation->attributes,
                'relationships' => $relationships,
            ],
            $link,
        );
    }

    #[\Override]
    public function explain(): array
    {
        return [
            'node' => static::class,
            'resource' => $this->resource,
            'input' => $this->input->explain(),
        ];
    }
}

/**
 * Связь по владению при PATCH (to-many и to-one): родитель (`parent`), затем его дети из
 * `relationship(name)->children` в `child` с `link = [relation[1] => keys[relation[0]]]` — как `InsertJoinWriter`.
 * To-one приходит от парсера тем же списком (один ребёнок).
 *
 * Поток: операция с поддеревом → `parent` → ключи → каждый ребёнок в `child` по порядку (парсер уже упорядочил:
 * удаления, оставленные и изменённые, вставки) → ключи родителя наверх. Связи у операции нет — дети не трогаются.
 * Полную замену выполняет `PatchReplaceWriter` внутри `parent`, то есть до детей.
 */
final readonly class PatchJoinWriter implements OperationPushExecutor
{
    /**
     * @param array{0: string, 1: string} $relation [колонка родителя, колонка ребёнка]
     */
    public function __construct(
        private string $name,
        private array $relation,
        private OperationPushExecutor $parent,
        private OperationPushExecutor $child,
    ) {}

    #[\Override]
    public function push(WriteOperation $operation, array $link = []): array
    {
        $keys = $this->parent->push($operation, $link);
        $relationship = $operation->relationship(name: $this->name);
        if ($relationship === null) {
            return $keys;
        }
        $parent_key = $keys[$this->relation[0]]
            ?? throw new LogicException("No key {$this->relation[0]} for {$this->name}");
        foreach ($relationship->children as $child) {
            $this->child->push($child, [$this->relation[1] => $parent_key]);
        }

        return $keys;
    }

    #[\Override]
    public function explain(): array
    {
        return [
            'node' => static::class,
            'name' => $this->name,
            'relation' => $this->relation,
            'parent' => $this->parent->explain(),
            'child' => $this->child->explain(),
        ];
    }
}

/**
 * Полная замена связи по владению при PATCH: удаляет детей владельца, не названных в документе. Замена to-one через
 * `lid` или `null` — тот же случай без названных id: текущий ребёнок удаляется (новый вставит `PatchJoinWriter` выше).
 *
 * Поток: операция с поддеревом + `$link` → `input` → ключи владельца → если у связи `replace` — `DELETE FROM
 * <главная таблица ребёнка> WHERE <relation[1]> = :parent AND <pk> NOT IN (id названных детей)` (без названных — все
 * дети владельца) → те же ключи наверх. Связи у операции нет или она без `replace` (частичное изменение у этого
 * владельца) — только `input`. Названные дети — операции с `id`: `UpdateOperation` и `KeepOperation`.
 *
 * Собственных детей удалённого ребёнка удаляет `ON DELETE CASCADE` базы.
 */
final readonly class PatchReplaceWriter implements OperationPushExecutor
{
    /**
     * @param array{0: string, 1: string} $relation       [колонка владельца, колонка ребёнка]
     * @param class-string<Resource>      $child_resource
     */
    public function __construct(
        private PDO $pdo,
        private string $name,
        private array $relation,
        private string $child_resource,
        private OperationPushExecutor $input,
    ) {}

    #[\Override]
    public function push(WriteOperation $operation, array $link = []): array
    {
        $keys = $this->input->push($operation, $link);
        $relationship = $operation->relationship(name: $this->name);
        if ($relationship === null || !$relationship->replace) {
            return $keys;
        }
        $parent_key = $keys[$this->relation[0]]
            ?? throw new LogicException("No key {$this->relation[0]} for {$this->name}");

        $entity = Tables::mainEntity(resource: $this->child_resource);
        $primary = $this->child_resource::primaryColumn();
        if (!isset($entity::COLUMNS[$this->relation[1]])) {
            throw new LogicException("{$entity}: link column {$this->relation[1]} is not in the main table");
        }
        $listed = [];
        foreach ($relationship->children as $child) {
            if ($child instanceof UpdateOperation || $child instanceof KeepOperation) {
                $listed[] = $child->identity->id;
            }
        }

        $delete = new Delete(new UpdateOrDeleteTarget(new QualifiedName($entity::TABLE)));
        $delete->where->and(new OperatorExpression('=', new ColumnReference($entity::column($this->relation[1])), new NamedParameter('parent_key')));
        $parameters = ['parent_key' => (int) $parent_key];
        if ($listed !== []) {
            $delete->where->and(new InExpression(
                new ColumnReference($entity::column($primary)),
                new ExpressionList(array_map(static fn(int $position): NamedParameter => new NamedParameter("listed_{$position}"), array_keys($listed))),
                true,
            ));
            foreach ($listed as $position => $id) {
                $parameters["listed_{$position}"] = $id;
            }
        }
        Tables::run(pdo: $this->pdo, statement: $delete, parameters: $parameters);

        return $keys;
    }

    #[\Override]
    public function explain(): array
    {
        return [
            'node' => static::class,
            'name' => $this->name,
            'relation' => $this->relation,
            'child_resource' => $this->child_resource,
            'input' => $this->input->explain(),
        ];
    }
}

/**
 * Граница уровней при изменении: операция → строка «колонка модели → значение» → поддерево таблиц
 * (`PatchPlanner::tables()` по `model()`). Пара к `InsertResourceWriter`. Исполняет `UpdateOperation` и
 * `KeepOperation`; другая операция — LogicException.
 *
 * Поток: операция (`identity->id`, `attributes`, `references`) + `$link` → `input->push(строка, link + [pk => id])`
 * → ключи всех таблиц; пусто — строки главной таблицы нет (или она чужая: колонки связи от родителя — условие
 * принадлежности), наверху это 404. У `KeepOperation` строка пустая: ресурс только ищется.
 *
 * В строку идут только присланные атрибуты; `null` — это NULL в колонку (присутствие ключа, а не значение, решает,
 * что колонку трогать). Колонка связи от родителя должна быть колонкой модели, иначе принадлежность не проверить —
 * LogicException. Если первичный ключ ресурса и есть колонка связи (`delivery.certificate_id`), id, не равный
 * родителю, — пусто (404) без запроса.
 */
final readonly class UpdateResourceWriter implements OperationPushExecutor
{
    /**
     * @param class-string<Resource> $resource
     */
    public function __construct(
        private string $resource,
        private TableWriter $input,
    ) {}

    #[\Override]
    public function push(WriteOperation $operation, array $link = []): array
    {
        if (!$operation instanceof UpdateOperation && !$operation instanceof KeepOperation) {
            throw new LogicException("{$this->resource}: update needs UpdateOperation or KeepOperation");
        }
        $tree = ModelTree::of($this->resource);
        foreach (array_keys($link) as $column) {
            if (!$tree->hasColumn((string) $column)) {
                throw new LogicException("{$this->resource}: link column {$column} is not a model column");
            }
        }

        $primary = $this->resource::primaryColumn();
        $id = $operation->identity->id;
        // Первичный ключ ребёнка — сама колонка связи (`delivery.certificate_id`): id обязан совпасть с родителем
        if (isset($link[$primary]) && (int) $link[$primary] !== $id) {
            return [];
        }
        $row = [];
        if ($operation instanceof UpdateOperation) {
            $row = Tables::row(
                resource: $this->resource,
                attributes: $operation->attributes,
                references: $operation->references,
            );
        }

        return $this->input->push($row, [...$link, $primary => $id]);
    }

    #[\Override]
    public function explain(): array
    {
        return [
            'node' => static::class,
            'resource' => $this->resource,
            'input' => $this->input->explain(),
        ];
    }
}

/**
 * Соединение двух частей модели при изменении: сначала `main` (левая сторона, её ключ известен), потом `joined` — по
 * ключу, который вернула `main`. Из example/update-document.php.
 *
 * Поток: строка + `$link` → `main->push()` → ключи; пусто — строки нет, дальше не идёт (наверху 404) →
 * `joined->push(строка, link + [relation[1] => keys[relation[0]]])` → ключи обеих сторон.
 *
 * Ограничение: пустой ответ `joined` у `InnerJoin` (рассогласование данных) не проверяется.
 */
final readonly class UpdateTableJoinWriter implements TableWriter
{
    /**
     * @param array{0: string, 1: string} $relation [колонка стороны main, колонка стороны joined]
     */
    public function __construct(
        private array $relation,
        private TableWriter $main,
        private TableWriter $joined,
    ) {}

    #[\Override]
    public function push(array $row, array $link = []): array
    {
        $main_keys = $this->main->push($row, $link);
        if ($main_keys === []) {
            return [];
        }
        $joined_link = [
            ...$link,
            $this->relation[1] => $main_keys[$this->relation[0]]
                ?? throw new LogicException("No key {$this->relation[0]} after update of the main side"),
        ];

        return [...$main_keys, ...$this->joined->push($row, $joined_link)];
    }

    #[\Override]
    public function columns(): array
    {
        return [...$this->main->columns(), ...$this->joined->columns()];
    }

    #[\Override]
    public function explain(): array
    {
        return [
            'node' => static::class,
            'relation' => $this->relation,
            'main' => $this->main->explain(),
            'joined' => $this->joined->explain(),
        ];
    }
}

/**
 * Одна таблица модели при изменении. Из example/update-document.php, плюс условие принадлежности.
 *
 * Поток: строка + `$link` → присланы колонки этой таблицы — `UPDATE <table> SET <колонки> WHERE <key> = :key AND
 * <колонки связи этой таблицы> AND (<колонка> IS DISTINCT FROM :<колонка> OR …) RETURNING <key>, <returning>`;
 * строки не вернулось или колонки не присланы — `SELECT <key>, <returning> … WHERE` то же без IS DISTINCT FROM →
 * ключи: присланные колонки и возвращённые; пусто — строки нет (или она чужая).
 *
 * Колонки связи этой таблицы — колонки `$link` из её колонок модели, кроме `key`: колонка связи с родителем
 * (`domains.certificate_id`), по ней чужой ребёнок не найдётся. NULL в присланной колонке пишется как значение;
 * IS DISTINCT FROM сравнивает NULL как значение, неизменённая строка не перезаписывается.
 */
final readonly class UpdateTableWriter implements TableWriter
{
    /**
     * @param class-string<Entity> $entity
     * @param list<string>         $columns   колонки модели ресурса в этой таблице
     * @param string               $key       колонка поиска строки: первичный ключ главной таблицы или колонка связи
     * @param list<string>         $returning колонки, которые нужны правым сторонам (кроме $key)
     */
    public function __construct(
        private PDO $pdo,
        private string $entity,
        private array $columns,
        private string $key,
        private array $returning = [],
    ) {}

    #[\Override]
    public function push(array $row, array $link = []): array
    {
        $entity = $this->entity;
        $key_value = $link[$this->key] ?? throw new LogicException("{$entity}: no value for key {$this->key}");
        /** @var array<string, mixed> $values */
        $values = array_diff_key(array_intersect_key($row, array_flip($this->columns)), [$this->key => true]);
        $conditions = array_diff_key(array_intersect_key($link, array_flip($this->columns)), [$this->key => true]);
        $returned = array_values(array_unique([$this->key, ...$this->returning]));
        $targets = array_map(static fn(string $column): TargetElement => new TargetElement(new ColumnReference($entity::column($column))), $returned);

        $found = [];
        if ($values !== []) {
            $update = new Update(
                new UpdateOrDeleteTarget(new QualifiedName($entity::TABLE)),
                new SetClauseList(array_map(
                    static fn(string $column): SingleSetClause => new SingleSetClause(new SetTargetElement($entity::column($column)), new NamedParameter($column)),
                    array_keys($values),
                )),
            );
            $parameters = [...$values, 'key' => $key_value, ...$this->where(statement: $update, conditions: $conditions)];
            // Только если хоть одно присланное значение отличается от записанного; NULL сравнивается как значение
            $update->where->and(new LogicalExpression(
                array_map(
                    static fn(string $column): IsDistinctFromExpression => new IsDistinctFromExpression(new ColumnReference($entity::column($column)), new NamedParameter($column)),
                    array_keys($values),
                ),
                LogicalOperator::OR,
            ));
            $update->returning->replace($targets);
            $found = Tables::run(pdo: $this->pdo, statement: $update, parameters: $parameters);
        }
        // Колонки не присланы или значения те же — строку только ищем: нужны её ключи и сам факт, что она есть
        if ($found === []) {
            $select = new Select(new TargetList(array_map(static fn(TargetElement $target): TargetElement => clone $target, $targets)));
            $select->from[] = new RelationReference(new QualifiedName($entity::TABLE));
            $found = Tables::run(pdo: $this->pdo, statement: $select, parameters: ['key' => $key_value, ...$this->where(statement: $select, conditions: $conditions)]);
        }

        return $found === [] ? [] : [...$values, ...$found[0]];
    }

    /**
     * Условие поиска строки: `key = :key` и колонки связи с родителем; значение `:key` подставляет вызывающий.
     *
     * @param array<string, mixed> $conditions колонка связи → значение
     * @return array<string, mixed> параметры колонок связи (`link_<колонка>`)
     */
    private function where(Update|Select $statement, array $conditions): array
    {
        $entity = $this->entity;
        $statement->where->and(new OperatorExpression('=', new ColumnReference($entity::column($this->key)), new NamedParameter('key')));
        $parameters = [];
        foreach ($conditions as $column => $value) {
            $statement->where->and(new OperatorExpression('=', new ColumnReference($entity::column($column)), new NamedParameter("link_{$column}")));
            $parameters["link_{$column}"] = $value;
        }

        return $parameters;
    }

    #[\Override]
    public function columns(): array
    {
        return $this->columns;
    }

    #[\Override]
    public function explain(): array
    {
        return [
            'node' => static::class,
            'table' => $this->entity::TABLE,
            'key' => $this->key,
            'columns' => $this->columns,
            'returning' => $this->returning,
        ];
    }
}

/**
 * Необязательная часть модели при изменении (правая сторона `LeftJoin`). Из example/update-document.php, плюс
 * правило NULL.
 *
 * Поток: строка + `$link` → нет колонок этой части → `[]`; есть → `update->push()` → ключи; строки нет → все
 * присланные значения NULL → `[]` (вставлять нечего: SELECT и так покажет null); иначе `insert->push(строка, link)`.
 *
 * Строка есть, а все её колонки прислали NULL — в колонки пишется NULL, строка не удаляется. Вставка отсутствующей
 * строки без её NOT NULL колонки (`buyer_email`) — ошибка базы как есть.
 */
final readonly class UpdateOptionalTableWriter implements TableWriter
{
    public function __construct(
        private UpdateTableWriter $update,
        private InsertTableWriter $insert,
    ) {}

    #[\Override]
    public function push(array $row, array $link = []): array
    {
        $own = array_intersect_key($row, array_flip($this->update->columns()));
        if ($own === []) {
            return [];
        }
        $keys = $this->update->push($row, $link);
        if ($keys !== [] || array_filter($own, static fn(mixed $value): bool => $value !== null) === []) {
            return $keys;
        }

        return $this->insert->push($row, $link);
    }

    #[\Override]
    public function columns(): array
    {
        return $this->update->columns();
    }

    #[\Override]
    public function explain(): array
    {
        return [
            'node' => static::class,
            'update' => $this->update->explain(),
            'insert' => $this->insert->explain(),
        ];
    }
}

/**
 * Удаление ресурса: строка главной таблицы модели — `DELETE FROM <главная> WHERE <pk> = :key_id AND <колонки связи>
 * RETURNING <pk>`. Используется для `meta.op: remove`, удаления по замене to-one и корня `DELETE /…/{id}`.
 *
 * Потомков удаляет база, а не движок (решение пользователя): строки присоединённых таблиц, которые ссылаются на главную
 * (`certificate_details`, а через неё `certificate_delivery_meta`), детей по владению (`domains` → `domain_logs`) и
 * таблицы без ресурса (`domain_configs`) — `ON DELETE CASCADE`. Без каскада — ошибка внешнего ключа базы как есть
 * (500). Присоединённая таблица, на которую ссылается главная (`vouchers.vendor_id → vendors`), остаётся: её строку
 * могут разделять другие. `Deletable` проверяется только у ресурса, которого удаляет документ; что уходит вместе с ним,
 * задаёт схема базы.
 *
 * Поток: `DeleteOperation` (`identity->id`) + `$link` → SQL → `[pk => id]`; пусто — строки нет. Другая операция —
 * LogicException.
 */
final readonly class DeleteResourceWriter implements OperationPushExecutor
{
    /**
     * @param class-string<Resource> $resource
     */
    public function __construct(
        private PDO $pdo,
        private string $resource,
    ) {}

    #[\Override]
    public function push(WriteOperation $operation, array $link = []): array
    {
        if (!$operation instanceof DeleteOperation) {
            throw new LogicException("{$this->resource}: delete needs DeleteOperation, got " . $operation::class);
        }
        $entity = Tables::mainEntity(resource: $this->resource);
        $primary = $this->resource::primaryColumn();
        $statement = new Delete(new UpdateOrDeleteTarget(new QualifiedName($entity::TABLE)));
        $statement->returning->replace([new TargetElement(new ColumnReference($entity::column($primary)))]);
        $parameters = [
            'key_id' => $operation->identity->id,
            ...Tables::where(statement: $statement, entity: $entity, primary: $primary, link: $link),
        ];

        return Tables::run(pdo: $this->pdo, statement: $statement, parameters: $parameters) === []
            ? []
            : [$primary => $operation->identity->id];
    }

    #[\Override]
    public function explain(): array
    {
        return ['node' => static::class, 'resource' => $this->resource, 'table' => Tables::mainEntity(resource: $this->resource)::TABLE];
    }
}

/**
 * Общее для исполнителей файла: главная таблица модели ресурса, строка из атрибутов и связей по внешнему ключу,
 * условие по первичному ключу и колонкам связи главной таблицы, выполнение с логом в `error_log()`.
 *
 * @temporary Только для прототипа: общий помощник файла. В `src/` его части уходят к владельцам: главная таблица —
 *            `ModelTree` (`rootEntity()` уже есть), строка ресурса — исполнитель уровня ресурсов (как
 *            `InsertResourceWriter`), условие и выполнение с логом — общая часть исполнителей, `existing()` —
 *            исполнитель проверки ссылок.
 */
final class Tables
{
    /**
     * Главная (крайняя левая) таблица модели ресурса: по ней ищется и удаляется строка ресурса.
     *
     * @param class-string<Resource> $resource
     * @return class-string<Entity>
     */
    public static function mainEntity(string $resource): string
    {
        return ModelTree::of($resource)->rootEntity() ?? throw new LogicException("{$resource}: invalid model");
    }

    /**
     * Строка ресурса из данных операции изменения: атрибут → его колонка, связь по внешнему ключу → её колонка
     * (id цели или NULL).
     *
     * @param class-string<Resource>                 $resource
     * @param array<string, mixed>                   $attributes присланные атрибуты
     * @param array<string, PersistentIdentity|null> $references связи по внешнему ключу
     * @return array<string, mixed> колонка модели → значение
     */
    public static function row(string $resource, array $attributes, array $references): array
    {
        $row = [];
        foreach ($attributes as $name => $value) {
            $attribute = $resource::ATTRIBUTES[$name] ?? throw new LogicException("{$resource}: unknown attribute {$name}");
            $row[(string) ($attribute['column'] ?? throw new LogicException("{$resource}: composite attribute {$name} is not supported"))] = $value;
        }
        foreach ($references as $name => $target) {
            $foreign = $resource::foreignRelationship($name)
                ?? throw new LogicException("{$resource}: {$name} is not a foreign-key relationship");
            $row[$foreign->relation[0]] = $target?->id;
        }

        return $row;
    }

    /**
     * WHERE <pk> = :key_id AND <колонка связи> = :link_<колонка> …; возвращает параметры колонок связи.
     *
     * @param class-string<Entity> $entity
     * @param array<string, mixed> $link
     * @return array<string, mixed>
     */
    public static function where(Update|Delete|Select $statement, string $entity, string $primary, array $link): array
    {
        $statement->where->and(new OperatorExpression('=', new ColumnReference($entity::column($primary)), new NamedParameter('key_id')));
        $parameters = [];
        foreach ($link as $column => $value) {
            if (!isset($entity::COLUMNS[$column])) {
                throw new LogicException("{$entity}: link column {$column} is not in the main table");
            }
            $statement->where->and(new OperatorExpression('=', new ColumnReference($entity::column($column)), new NamedParameter("link_{$column}")));
            $parameters["link_{$column}"] = $value;
        }

        return $parameters;
    }

    /**
     * Проверка существования — общий запрос для всех проверок ссылок на существующие ресурсы: родитель из маршрута
     * (`RouteParentWriter`), позже — цели связей по внешнему ключу.
     * `SELECT <pk> FROM <главная таблица> WHERE <pk> IN (…)` [`FOR KEY SHARE`] → найденные id. Через главную таблицу
     * модели: если это view или таблица под RLS, невидимая пользователю строка «не найдена» (внешний ключ базы RLS не
     * видит). `FOR KEY SHARE` (PostgreSQL) держит найденные строки до конца транзакции: их не удалят между проверкой
     * и записью; ту же блокировку берёт сам внешний ключ при вставке. SQLite такой конструкции не знает — `lock`
     * выключается параметром.
     *
     * @param class-string<Resource> $resource
     * @param list<int>              $ids      непустой список
     * @return list<int> найденные id
     */
    public static function existing(PDO $pdo, string $resource, array $ids, bool $lock): array
    {
        $entity = self::mainEntity(resource: $resource);
        $primary = $entity::primaryColumn();
        $ids = array_values(array_unique($ids));
        $select = new Select(new TargetList([new TargetElement(new ColumnReference($entity::column($primary)))]));
        $select->from[] = new RelationReference(new QualifiedName($entity::TABLE));
        $select->where->and(new InExpression(
            new ColumnReference($entity::column($primary)),
            new ExpressionList(array_map(static fn(int $position): NamedParameter => new NamedParameter("id_{$position}"), array_keys($ids))),
        ));
        if ($lock) {
            $select->locking[] = new LockingElement(LockingStrength::KEY_SHARE);
        }
        $parameters = [];
        foreach ($ids as $position => $id) {
            $parameters["id_{$position}"] = $id;
        }

        return array_map(
            static fn(array $row): int => (int) $row[$entity::column($primary)],
            self::run(pdo: $pdo, statement: $select, parameters: $parameters),
        );
    }

    /**
     * @param array<string, mixed> $parameters
     * @return list<array<string, mixed>> строки результата (у DELETE/UPDATE без RETURNING — пусто)
     */
    public static function run(PDO $pdo, Statement $statement, array $parameters): array
    {
        $sql = (string) preg_replace(pattern: '/\s+/', replacement: ' ', subject: $statement->dispatch(new SqlBuilderWalker()));
        $prepared = $pdo->prepare($sql);
        foreach ($parameters as $name => $value) {
            $type = PDO::PARAM_STR;
            if (is_int($value)) {
                $type = PDO::PARAM_INT;
            }
            if (is_bool($value)) {
                $type = PDO::PARAM_BOOL;
            }
            if ($value === null) {
                $type = PDO::PARAM_NULL;
            }
            $prepared->bindValue(':' . $name, $value, $type);
        }
        $prepared->execute();
        /** @var list<array<string, mixed>> $rows */
        $rows = $prepared->columnCount() > 0 ? $prepared->fetchAll(PDO::FETCH_ASSOC) : [];
        error_log("{$sql}\n    " . (string) json_encode($parameters) . ' → ' . ($prepared->columnCount() > 0 ? json_encode($rows) : "{$prepared->rowCount()} rows"));

        return $rows;
    }
}

/**
 * Собирает по плану PATCH (`PatchPlanner`) дерево исполнителей. Пара к `Executor\Insert\WriterBuilder`; ветвь `add`
 * собирает он сам, над ней — переходник `InsertOperationWriter` (операция → ресурс документа для вставки из `src/`).
 *
 * Поток: неизменяемое дерево плана → `WriteRootWriter` (`root()`) над деревом `OperationPushExecutor` на одно
 * выполнение (план переиспользуемый). `WriteRootNode` → `WriteRootWriter`, `RouteParentNode` → `RouteParentWriter` (с id из маршрута).
 *
 * Узел и исполнитель соответствуют по основе имени: `PatchJoinNode` → `PatchJoinWriter`, `PatchReplaceNode` →
 * `PatchReplaceWriter`, `PatchOperationNode` → `PatchOperationWriter`, `UpdateResourceNode` →
 * `UpdateResourceWriter`, `UpdateTableJoinNode` → `UpdateTableJoinWriter`, `UpdateOptionalTableNode` →
 * `UpdateOptionalTableWriter`, `UpdateTableNode` → `UpdateTableWriter`, `DeleteResourceNode` → `DeleteResourceWriter`.
 */
final readonly class PatchWriterBuilder
{
    /**
     * @param bool $lock `FOR KEY SHARE` в проверках существования (PostgreSQL); false — SQLite
     */
    public function __construct(
        private PDO $pdo,
        private bool $lock,
    ) {}

    /**
     * Корень записи. Значения, которых нет в плане, даёт вызывающий: корни документа и id родителя из маршрута.
     *
     * @param list<WriteOperation> $data     корни дерева операций
     * @param int|null             $route_id id родителя из вложенного маршрута; обязателен, если в плане есть `route`
     */
    public function root(WriteRootNode $plan, array $data, ?int $route_id = null): WriteRootWriter
    {
        if (($plan->route === null) !== ($route_id === null)) {
            throw new LogicException('route id must be given exactly when the plan has a route parent');
        }

        return new WriteRootWriter(
            root: $this->build(plan: $plan->input),
            data: $data,
            route: $plan->route === null ? null : new RouteParentWriter(
                pdo: $this->pdo,
                parent: $plan->route->parent,
                relation: $plan->route->relation,
                id: (int) $route_id,
                lock: $this->lock,
            ),
        );
    }

    public function build(PatchJoinNode|PatchReplaceNode|PatchOperationNode $plan): OperationPushExecutor
    {
        if ($plan instanceof PatchJoinNode) {
            return new PatchJoinWriter(
                name: $plan->name,
                relation: $plan->relation,
                parent: $this->build(plan: $plan->parent),
                child: $this->build(plan: $plan->child),
            );
        }
        if ($plan instanceof PatchReplaceNode) {
            return new PatchReplaceWriter(
                pdo: $this->pdo,
                name: $plan->name,
                relation: $plan->relation,
                child_resource: $plan->child_resource,
                input: $this->build(plan: $plan->input),
            );
        }

        return new PatchOperationWriter(
            resource: $plan->resource,
            add: $plan->add === null ? null : new InsertOperationWriter(
                resource: $plan->resource,
                input: new WriterBuilder(pdo: $this->pdo)->build(plan: $plan->add),
            ),
            update: $plan->update === null ? null : new UpdateResourceWriter(
                resource: $plan->update->resource,
                input: $this->table(plan: $plan->update->input),
            ),
            remove: $plan->remove === null ? null : new DeleteResourceWriter(
                pdo: $this->pdo,
                resource: $plan->remove->resource,
            ),
        );
    }

    public function table(UpdateTableJoinNode|UpdateTableNode|UpdateOptionalTableNode $plan): TableWriter
    {
        if ($plan instanceof UpdateTableJoinNode) {
            return new UpdateTableJoinWriter(
                relation: $plan->relation,
                main: $this->table(plan: $plan->main),
                joined: $this->table(plan: $plan->joined),
            );
        }
        if ($plan instanceof UpdateOptionalTableNode) {
            return new UpdateOptionalTableWriter(
                update: $this->updateTable(plan: $plan->update),
                insert: new InsertTableWriter(
                    pdo: $this->pdo,
                    entity: $plan->insert->entity,
                    columns: $plan->insert->columns,
                ),
            );
        }

        return $this->updateTable(plan: $plan);
    }

    private function updateTable(UpdateTableNode $plan): UpdateTableWriter
    {
        return new UpdateTableWriter(
            pdo: $this->pdo,
            entity: $plan->entity,
            columns: $plan->columns,
            key: $plan->key,
            returning: $plan->returning,
        );
    }
}

// ---------------------------------------------------------------------------------------------------------------
// Печать: дерево операций, план и дерево исполнителей текстом

function json(mixed $value): string
{
    return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * Имя класса без пространства имён.
 */
function shortClassName(string $class): string
{
    return substr((string) strrchr('\\' . $class, '\\'), 1);
}

/**
 * Дерево операций текстом: класс операции, ресурс, id или lid, присланные атрибуты; под ним связи по внешнему ключу
 * (если есть) и связи по владению с признаком замены и операциями над детьми.
 */
function operations(WriteOperation $operation, string $indent = ''): string
{
    $output = $indent . shortClassName($operation::class) . ' ' . shortClassName($operation->resource);
    if ($operation instanceof KeepOperation || $operation instanceof DeleteOperation) {
        return "{$output} {$operation->identity->key()}\n";
    }
    if (!$operation instanceof InsertOperation && !$operation instanceof UpdateOperation) {
        throw new LogicException('Unknown operation ' . $operation::class);
    }
    $output .= ' ' . ($operation->identity === null ? 'new' : $operation->identity->key());
    $output .= $operation->attributes === [] ? '' : ' ' . json_encode($operation->attributes, JSON_UNESCAPED_UNICODE);
    $output .= "\n";
    $references = [];
    foreach ($operation->references as $name => $target) {
        $references[] = "{$name} → " . ($target === null ? 'null' : $target->key());
    }
    if ($references !== []) {
        $output .= "{$indent}    references: " . implode(', ', $references) . "\n";
    }
    foreach ($operation->relationships as $name => $relationship) {
        $replace = $relationship->replace ? 'true' : 'false';
        $output .= "{$indent}    RelationshipOperation {$name}, replace: {$replace}\n";
        foreach ($relationship->children as $child) {
            $output .= operations(operation: $child, indent: "{$indent}        ");
        }
    }

    return $output;
}

/**
 * Дерево исполнителей текстом из explain(): короткое имя узла, его скалярные и списочные свойства, входы с отступом
 * под своим именем (`parent`, `child`, `add`, `input`…).
 */
function executionTree(array $explain, string $indent = '', string $role = ''): string
{
    $name = shortClassName($explain['node']);
    $properties = [];
    $inputs = [];
    foreach ($explain as $key => $value) {
        if ($key === 'node') {
            continue;
        }
        if (is_array($value) && isset($value['node'])) {
            $inputs[$key] = $value;
        } else {
            $properties[] = "{$key}: " . (is_string($value) && str_contains($value, '\\') ? shortClassName($value) : json_encode($value, JSON_UNESCAPED_SLASHES));
        }
    }
    $output = $indent . ($role === '' ? '' : "{$role}: ") . $name . ($properties === [] ? '' : ' (' . implode(', ', $properties) . ')') . "\n";
    foreach ($inputs as $key => $input) {
        $output .= executionTree($input, "{$indent}    ", (string) $key);
    }

    return $output;
}

/**
 * План текстом из публичных свойств узлов: короткое имя узла, его свойства, входы (свойства-узлы) с отступом под
 * своим именем; пустые ветви (`null`) не печатаются.
 */
function planTree(object $node, string $indent = '', string $role = ''): string
{
    $properties = [];
    $inputs = [];
    foreach (get_object_vars($node) as $key => $value) {
        if ($value === null) {
            continue;
        }
        if (is_object($value) && str_ends_with($value::class, 'Node')) {
            $inputs[$key] = $value;
        } elseif (is_object($value)) {
            $properties[] = "{$key}: " . shortClassName($value::class);
        } else {
            $properties[] = "{$key}: " . (is_string($value) && str_contains($value, '\\') ? shortClassName($value) : json_encode($value, JSON_UNESCAPED_SLASHES));
        }
    }
    $output = $indent . ($role === '' ? '' : "{$role}: ") . shortClassName($node::class) . ($properties === [] ? '' : ' (' . implode(', ', $properties) . ')') . "\n";
    foreach ($inputs as $key => $input) {
        $output .= planTree($input, "{$indent}    ", (string) $key);
    }

    return $output;
}

/**
 * Строки сертификата 1 и его детей: состояние до и после сценария.
 *
 * @return array<string, mixed>
 */
function certificateState(PDO $pdo): array
{
    return [
        'certificate' => $pdo->query('select c.nominal, d.buyer_email, d.recipient_name from certificates c left join certificate_details d on d.certificate_id = c.id where c.id = 1')->fetch(PDO::FETCH_ASSOC),
        'delivery' => $pdo->query('select certificate_id, delivery_channel, is_sent, external_provider_id from certificate_delivery_meta where certificate_id = 1')->fetch(PDO::FETCH_ASSOC),
        'domains' => $pdo->query('select id, domain_name, is_primary from domains where certificate_id = 1 order by id')->fetchAll(PDO::FETCH_ASSOC),
    ];
}

/**
 * Два документа PATCH для сертификата без строки деталей: только NULL, затем значения.
 *
 * @return list<array>
 */
function detailDocuments(string $id): array
{
    return [
        ['data' => ['type' => 'certificate', 'id' => $id, 'attributes' => ['recipient_name' => null]]],
        ['data' => ['type' => 'certificate', 'id' => $id, 'attributes' => ['buyer_email' => 'first-buyer@example.com', 'greeting_text' => 'Hello']]],
    ];
}

/**
 * @return array<string, mixed>|false строка деталей сертификата, false — строки нет
 */
function detailState(PDO $pdo, string $id): array|false
{
    return $pdo->query("select * from certificate_details where certificate_id = {$id}")->fetch(PDO::FETCH_ASSOC);
}

/**
 * Число строк сертификата 1 и его потомков по таблицам: что удалил каскад.
 *
 * @param string $domain_ids id доменов сертификата через запятую (после удаления их уже не найти)
 * @return array<string, int>
 */
function cascadeState(PDO $pdo, string $domain_ids): array
{
    return [
        'certificates' => (int) $pdo->query('select count(*) from certificates where id = 1')->fetchColumn(),
        'certificate_details' => (int) $pdo->query('select count(*) from certificate_details where certificate_id = 1')->fetchColumn(),
        'certificate_delivery_meta' => (int) $pdo->query('select count(*) from certificate_delivery_meta where certificate_id = 1')->fetchColumn(),
        'domains' => (int) $pdo->query('select count(*) from domains where certificate_id = 1')->fetchColumn(),
        'domain_logs' => (int) $pdo->query("select count(*) from domain_logs where domain_id in ({$domain_ids})")->fetchColumn(),
        'domain_configs' => (int) $pdo->query("select count(*) from domain_configs where domain_id in ({$domain_ids})")->fetchColumn(),
    ];
}

$pdo = getPDO();
// FOR KEY SHARE в проверках существования: в PostgreSQL true; SQLite примера такой конструкции не знает
$lock = false;

// ---------------------------------------------------------------------------------------------------------------
// 1. PATCH /certificates/1 над CertificateWithDetailResource (certificates + certificate_details): номинал
//    (certificates), buyer_email и recipient_name: null (certificate_details: NULL в колонку); to-one delivery —
//    замена через lid (текущая доставка удаляется, новая вставляется); связь по владению domains: домен 3 —
//    основной, домен 2 удалить, новый домен; домен 1 не назван — остаётся (частичное изменение)

$certificate_document = [
    'data' => [
        'type' => 'certificate',
        'id' => '1',
        'attributes' => ['nominal' => '2500.00', 'buyer_email' => 'new-buyer@example.com', 'recipient_name' => null],
        'relationships' => [
            'delivery' => ['data' => ['type' => 'delivery', 'lid' => 'del1']],
            'domains' => ['data' => [
                ['type' => 'domain', 'id' => '3'],
                ['type' => 'domain', 'id' => '2', 'meta' => ['op' => 'remove']],
                ['type' => 'domain', 'lid' => 'd1'],
            ]],
        ],
    ],
    'included' => [
        ['type' => 'domain', 'id' => '3', 'attributes' => ['is_primary' => true]],
        ['type' => 'domain', 'lid' => 'd1', 'attributes' => ['domain_name' => 'new.green1.net', 'is_primary' => false]],
        ['type' => 'delivery', 'lid' => 'del1', 'attributes' => ['delivery_channel' => 'SMS', 'is_sent' => false]],
    ],
];
echo "######## 1. PATCH /certificates/1\n", json($certificate_document), "\n\n";


$pdo->beginTransaction();
try {
    $certificate_root = new WriteDocumentParser()->update(document: $certificate_document, resource: CertificateWithDetailResource::class, id: '1');
    echo "######## operation tree\n", operations(operation: $certificate_root), "\n";
    $certificate_plan = new PatchPlanner()->plan(resource: CertificateWithDetailResource::class, roots: [$certificate_root]);
    echo "######## plan\n", planTree($certificate_plan), "\n";
    $certificate_writer = new PatchWriterBuilder(pdo: $pdo, lock: $lock)->root(plan: $certificate_plan, data: [$certificate_root]);
    echo "######## execution tree\n", executionTree($certificate_writer->explain()), "\n";
    echo "######## before\n", json(certificateState(pdo: $pdo)), "\n\n";
    echo "######## keys of the root\n", json($certificate_writer->execute()[0]), "\n\n";
    echo "######## after (before rollback)\n", json(certificateState(pdo: $pdo)), "\n\n";
} catch (WriteDocumentException $exception) {
    echo "######## response\n", json($exception->toDocument()), "\n\n";
} finally {
    $pdo->rollBack();
}

// ---------------------------------------------------------------------------------------------------------------
// 2. PATCH сертификата без строки certificate_details (вставлен в транзакции), два запроса подряд:
//    recipient_name: null — строки нет, все присланные значения NULL: вставлять нечего, только SELECT;
//    buyer_email + greeting_text — строки нет: UPDATE ничего не нашёл, строка вставляется


$pdo->beginTransaction();
try {
    $pdo->exec("insert into certificates (provider_id, code, nominal) values (1, 'NO-DETAIL', '1.00')");
    $detail_id = (string) $pdo->lastInsertId();
    echo "######## 2. PATCH /certificates/{$detail_id} (no certificate_details row)\n\n";
    foreach (detailDocuments(id: $detail_id) as $detail_document) {
        echo "######## document\n", json($detail_document), "\n";
        $detail_root = new WriteDocumentParser()->update(document: $detail_document, resource: CertificateWithDetailResource::class, id: $detail_id);
        $detail_plan = new PatchPlanner()->plan(resource: CertificateWithDetailResource::class, roots: [$detail_root]);
        echo "######## plan\n", planTree($detail_plan);
        $detail_writer = new PatchWriterBuilder(pdo: $pdo, lock: $lock)->root(plan: $detail_plan, data: [$detail_root]);
        echo "######## keys of the root\n", json($detail_writer->execute()[0]), "\n";
        echo "######## certificate_details after\n", json(detailState(pdo: $pdo, id: $detail_id)), "\n\n";
    }
} catch (WriteDocumentException $exception) {
    echo "######## response\n", json($exception->toDocument()), "\n\n";
} finally {
    $pdo->rollBack();
}

// ---------------------------------------------------------------------------------------------------------------
// 3. POST /certificates тем же парсером, планировщиком и исполнителями: корень новый (id нет, lid не обязателен),
//    у потомков только lid. Документ — из example/insert-plan.php: SQL тот же, что у вставки через InsertPlanner.
//    Ответ — 201 Created + Location + {data: {type, id}}

$post_document = [
    'data' => [
        'type' => 'certificate',
        'attributes' => [
            'code' => 'PLAN-1',
            'nominal' => '10.00',
            'buyer_email' => 'buyer@example.com',
            'recipient_name' => 'Anna',
        ],
        'relationships' => [
            'provider' => ['data' => ['type' => 'provider', 'id' => '1']],
            'delivery' => ['data' => ['type' => 'delivery', 'lid' => 'del1']],
            'domains' => ['data' => [['type' => 'domain', 'lid' => 'd1'], ['type' => 'domain', 'lid' => 'd2']]],
        ],
    ],
    'included' => [
        ['type' => 'delivery', 'lid' => 'del1', 'attributes' => ['delivery_channel' => 'EMAIL']],
        ['type' => 'domain', 'lid' => 'd1', 'attributes' => ['domain_name' => 'plan1.example.com']],
        ['type' => 'domain', 'lid' => 'd2', 'attributes' => ['domain_name' => 'plan2.example.com']],
    ],
];
echo "\n######## 3. POST /certificates\n", json($post_document), "\n\n";

$pdo->beginTransaction();
try {
    $post_roots = new WriteDocumentParser()->insert(document: $post_document, resource: CertificateWithDetailResource::class);
    echo "######## operation tree\n";
    echo implode('', array_map(static fn(WriteOperation $root): string => operations(operation: $root), $post_roots)), "\n";
    $post_plan = new PatchPlanner()->plan(resource: CertificateWithDetailResource::class, roots: $post_roots);
    echo "######## plan\n", planTree($post_plan), "\n";
    $post_writer = new PatchWriterBuilder(pdo: $pdo, lock: $lock)->root(plan: $post_plan, data: $post_roots);
    $post_keys = $post_writer->execute();
    $post_id = (string) $post_keys[0][CertificateWithDetailResource::primaryColumn()];
    echo "######## response\n201 Created\nLocation: /certificates/{$post_id}\n", json(['data' => ['type' => CertificateWithDetailResource::type(), 'id' => $post_id]]), "\n";
} catch (WriteDocumentException $exception) {
    echo "######## response\n", json($exception->toDocument()), "\n";
} finally {
    $pdo->rollBack();
}

// ---------------------------------------------------------------------------------------------------------------
// 4. POST /certificates/1/domains — несколько ресурсов одним запросом через вложенный маршрут: data — список, каждый
//    элемент — корень. Маршрут вложенный — в плане RouteParentNode (родитель и колонки связи, без id); id = 1 получает
//    сборщик: RouteParentWriter один раз проверяет, что сертификат есть, и даёт корням certificate_id = 1. Документ —
//    из example/insert-route.php. Ответ — 201 без Location, data — идентификаторы в порядке запроса

$route_document = [
    'data' => [
        ['type' => 'domain', 'lid' => 'd1', 'attributes' => ['domain_name' => 'api.example.org', 'is_primary' => true]],
        ['type' => 'domain', 'lid' => 'd2', 'attributes' => ['domain_name' => 'cdn.example.org', 'is_primary' => false]],
    ],
];
echo "\n######## 4. POST /certificates/1/domains\n", json($route_document), "\n\n";

$pdo->beginTransaction();
try {
    $route_roots = new WriteDocumentParser()->insert(document: $route_document, resource: DomainResource::class);
    echo "######## operation tree\n";
    echo implode('', array_map(static fn(WriteOperation $root): string => operations(operation: $root), $route_roots)), "\n";
    $route_plan = new PatchPlanner()->plan(
        resource: DomainResource::class,
        roots: $route_roots,
        parent: CertificateResource::class,
        relationship: CertificateResource::DOMAINS,
    );
    echo "######## plan\n", planTree($route_plan), "\n";
    $route_writer = new PatchWriterBuilder(pdo: $pdo, lock: $lock)->root(plan: $route_plan, data: $route_roots, route_id: 1);
    echo "######## execution tree\n", executionTree($route_writer->explain()), "\n";
    $route_keys = $route_writer->execute();
    echo "######## response\n201 Created\n", json(['data' => array_map(
        static fn(array $keys): array => ['type' => DomainResource::type(), 'id' => (string) $keys[DomainResource::primaryColumn()]],
        $route_keys,
    )]), "\n";
} catch (WriteDocumentException $exception) {
    echo "######## response\n", json($exception->toDocument()), "\n";
} finally {
    $pdo->rollBack();
}

// ---------------------------------------------------------------------------------------------------------------
// 5. POST /certificates/999/domains — тот же план (он переиспользуемый, id маршрута — значение), сертификата 999 нет:
//    RouteParentWriter отвечает 404 до первой вставки

echo "\n######## 5. POST /certificates/999/domains (same plan, route id 999)\n";
$pdo->beginTransaction();
try {
    new PatchWriterBuilder(pdo: $pdo, lock: $lock)->root(plan: $route_plan, data: $route_roots, route_id: 999)->execute();
} catch (WriteDocumentException $exception) {
    echo "######## response\n", json($exception->toDocument()), "\n";
} finally {
    $pdo->rollBack();
}

// ---------------------------------------------------------------------------------------------------------------
// 6. DELETE /certificates/1 — тела нет: WriteDocumentParser::delete() проверяет Deletable и даёт корень remove; план и
//    исполнители те же. Удаляется строка certificates, остальное — каскад базы: детали, доставка, домены, их логи и
//    конфиги (у domain_configs ресурса нет — движок о ней не знает). Ответ — 204 No Content

echo "\n######## 6. DELETE /certificates/1\n\n";

$domain_ids = implode(', ', $pdo->query('select id from domains where certificate_id = 1')->fetchAll(PDO::FETCH_COLUMN));

$pdo->beginTransaction();
try {
    $delete_root = new WriteDocumentParser()->delete(resource: CertificateWithDetailResource::class, id: '1');
    echo "######## operation tree\n", operations(operation: $delete_root), "\n";
    $delete_plan = new PatchPlanner()->plan(resource: CertificateWithDetailResource::class, roots: [$delete_root]);
    echo "######## plan\n", planTree($delete_plan), "\n";
    $delete_writer = new PatchWriterBuilder(pdo: $pdo, lock: $lock)->root(plan: $delete_plan, data: [$delete_root]);
    echo "######## execution tree\n", executionTree($delete_writer->explain()), "\n";
    echo "######## rows of certificate 1 before\n", json(cascadeState(pdo: $pdo, domain_ids: $domain_ids)), "\n\n";
    $delete_writer->execute();
    echo "######## response\n204 No Content\n\n";
    echo "######## rows of certificate 1 after (before rollback)\n", json(cascadeState(pdo: $pdo, domain_ids: $domain_ids)), "\n";
} catch (WriteDocumentException $exception) {
    echo "######## response\n", json($exception->toDocument()), "\n";
} finally {
    $pdo->rollBack();
}

// ---------------------------------------------------------------------------------------------------------------
// 7. DELETE /certificates/999 — тот же план, сертификата нет: DELETE … RETURNING пуст → 404 без указателя

echo "\n######## 7. DELETE /certificates/999 (same plan)\n";
$pdo->beginTransaction();
try {
    $missing_root = new WriteDocumentParser()->delete(resource: CertificateWithDetailResource::class, id: '999');
    new PatchWriterBuilder(pdo: $pdo, lock: $lock)->root(plan: $delete_plan, data: [$missing_root])->execute();
} catch (WriteDocumentException $exception) {
    echo "######## response\n", json($exception->toDocument()), "\n";
} finally {
    $pdo->rollBack();
}

// ---------------------------------------------------------------------------------------------------------------
// 8. PATCH /certificates/1 — полная замена списка domains: ни у одного элемента нет meta.op, названные (1 и 3)
//    остаются, остальные (2) удаляются. В плане PatchReplaceNode под PatchJoinNode той же связи; у места domains одна
//    ветвь update (её же исполняет keep)

$replace_document = [
    'data' => [
        'type' => 'certificate',
        'id' => '1',
        'relationships' => [
            'domains' => ['data' => [
                ['type' => 'domain', 'id' => '1'],
                ['type' => 'domain', 'id' => '3'],
            ]],
        ],
    ],
];
echo "\n######## 8. PATCH /certificates/1 (full replacement of domains)\n", json($replace_document), "\n\n";
$pdo->beginTransaction();
try {
    $replace_root = new WriteDocumentParser()->update(document: $replace_document, resource: CertificateWithDetailResource::class, id: '1');
    echo "######## operation tree\n", operations(operation: $replace_root), "\n";
    $replace_plan = new PatchPlanner()->plan(resource: CertificateWithDetailResource::class, roots: [$replace_root]);
    echo "######## plan\n", planTree($replace_plan), "\n";
    echo "######## before\n", json(certificateState(pdo: $pdo)), "\n\n";
    new PatchWriterBuilder(pdo: $pdo, lock: $lock)->root(plan: $replace_plan, data: [$replace_root])->execute();
    echo "######## after (before rollback)\n", json(certificateState(pdo: $pdo)), "\n";
} catch (WriteDocumentException $exception) {
    echo "######## response\n", json($exception->toDocument()), "\n";
} finally {
    $pdo->rollBack();
}

// ---------------------------------------------------------------------------------------------------------------
// 9. PATCH /certificates/1 — замена пустым: delivery: null (текущая доставка удаляется) и domains: [] (удаляются все
//    домены). Детей нет, передавать нечего: в плане только PatchReplaceNode, узлов связи нет

$empty_document = [
    'data' => [
        'type' => 'certificate',
        'id' => '1',
        'relationships' => [
            'delivery' => ['data' => null],
            'domains' => ['data' => []],
        ],
    ],
];
echo "\n######## 9. PATCH /certificates/1 (replacement by empty: delivery null, domains [])\n", json($empty_document), "\n\n";
$pdo->beginTransaction();
try {
    $empty_root = new WriteDocumentParser()->update(document: $empty_document, resource: CertificateWithDetailResource::class, id: '1');
    echo "######## operation tree\n", operations(operation: $empty_root), "\n";
    $empty_plan = new PatchPlanner()->plan(resource: CertificateWithDetailResource::class, roots: [$empty_root]);
    echo "######## plan\n", planTree($empty_plan), "\n";
    echo "######## before\n", json(certificateState(pdo: $pdo)), "\n\n";
    new PatchWriterBuilder(pdo: $pdo, lock: $lock)->root(plan: $empty_plan, data: [$empty_root])->execute();
    echo "######## after (before rollback)\n", json(certificateState(pdo: $pdo)), "\n";
} catch (WriteDocumentException $exception) {
    echo "######## response\n", json($exception->toDocument()), "\n";
} finally {
    $pdo->rollBack();
}
