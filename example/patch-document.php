<?php

/**
 * Прототип записи (POST, PATCH и DELETE) ресурса со связями: документ в формате вставки (example/insert-document.php:
 * `included` + `lid`) и операция над элементом связи в `meta.op` идентификатора. Один парсер, планировщик и набор
 * исполнителей на оба запроса: POST — частный случай PATCH (корень новый, всё по `lid`). Весь прототип — в этом файле,
 * `src/` не меняется; из `src/` взяты `InsertPlanner::tables()` + `WriterBuilder` (вставка новых детей),
 * `InsertTableWriter` (вставка отсутствующей необязательной части модели) и контракты `PushExecutor`, `TableWriter`.
 *
 * Конвейер, как у SELECT и вставки: документ → `WriteDocumentParser` → дерево изменений → `PatchPlanner` → план
 * (неизменяемые узлы `…Node`) → `PatchWriterBuilder` → дерево исполнителей (`…Writer`) → `push()`.
 *
 *     php example/patch-document.php            # документ → дерево изменений → план → дерево исполнителей → ключи;
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
 * Pointer.
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
 * Ошибка документа POST/PATCH для ответа: статус, JSON Pointer, описание. 400/403/409 — при разборе, до первого
 * запроса; 404 — при выполнении (ребёнка или цели нет, ребёнок принадлежит другому родителю, нет родителя из
 * маршрута — без указателя: его нет в документе).
 *
 * @temporary Только для прототипа. В `src/` ошибки разойдутся по исключениям слоёв: 400 разбора —
 *            `Parser\InvalidDocumentException` (у него нет статуса, только 400), 404 — `ResourceNotFoundException`;
 *            для 403 (нет возможности ресурса) и 409 (тип или id не совпали с маршрутом) исключений в `src/` нет, их
 *            нужно будет добавить.
 */
final class WriteDocumentException extends RuntimeException
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $pointer,
        string $detail,
    ) {
        parent::__construct($detail);
    }

    /**
     * @return array{errors: list<array{status: string, source: array{pointer: string}, detail: string}>}
     */
    public function toDocument(): array
    {
        return ['errors' => [[
            'status' => $this->status,
            ...($this->pointer === null ? [] : ['source' => ['pointer' => $this->pointer]]),
            'detail' => $this->getMessage(),
        ]]];
    }
}

/**
 * Один парсер для POST и PATCH: документ → дерево изменений. Заменяет оба: `Parser\Insert\DocumentParser` (POST) и
 * прежний парсер PATCH этого файла; POST — частный случай: корень новый, у его потомков только `lid` (существующих
 * детей у нового ресурса нет), пометок `meta.op` нет, полной замены нет.
 *
 * Поток: документ + класс ресурса точки входа (+ id из маршрута у PATCH) → корни дерева изменений. Узел —
 * ресурс (`type`, `id` и/или `lid`, `attributes`, связи по внешнему ключу `{data: {type, id} | null}`) со служебными
 * `patch:op` (`add` | `update` | `keep` | `remove`) и `patch:pointer` (куда указывать ошибку); связь по владению —
 * `{data: list<узел>, patch:replace: bool}` (to-one — тот же список из одного или пустой, плюс `patch:to-one: true`),
 * дети уже в порядке выполнения (remove, keep/update, add). `InsertResourceWriter` служебные ключи и эти связи не
 * читает.
 *
 * Три входа, общее ядро (`included`, `lid`, атрибуты, связи):
 *  - `insert()` — POST: `data` — ресурс или список ресурсов, каждый — новый корень (`add`, `Insertable`, иначе 403):
 *    `type` — ресурс точки входа (иначе 409), `id` нет (клиентский id не поддерживается — 403), `lid` не обязателен;
 *    возвращает список корней. Ответ: один ресурс — `201` + `Location` + `{data: {type, id}}`; список — `201` без
 *    `Location`, `data` — идентификаторы в порядке запроса. Вложенный маршрут (`POST /certificates/1/domains`) — не
 *    дело парсера: родитель — `RouteParentNode` плана, id — у исполнителя (`RouteParentWriter`);
 *  - `update()` — PATCH: `data` — один ресурс, `type` и `id` совпадают с маршрутом (иначе 409); операция `update`;
 *  - `delete()` — DELETE: документа нет, корень с операцией `remove`, ресурс `Deletable` (иначе 403); ответ `204`,
 *    ресурса нет — 404 при выполнении.
 *
 * Проверяет весь документ до первого запроса: форма `attributes`/`relationships` (объекты), атрибут известен,
 * не первичный ключ, не `mode` READ, `null` только у `nullable`; связь известна и у неё есть `data`; по внешнему
 * ключу — `{type, id}` нужного типа или null; по владению — to-many (список) и to-one (ресурс или null); тип и
 * `meta.op` элементов; возможности ресурсов (403): `add` — `Insertable`, `update` (корень PATCH, ребёнок `{id}` +
 * included; изменение связи по внешнему ключу — изменение владельца) — `Updatable`, `remove`, полная замена to-many и
 * замена to-one (`lid`, `null`) — `Deletable` цели (по форме документа); у нового ресурса — только новые дети; повторы;
 * каждый ресурс `included` использован ровно один раз (дерево, циклов нет). Ошибки — WriteDocumentException
 * 400/403/409 с JSON Pointer.
 *
 * Не проверяет (как `DocumentParser`): типы значений атрибутов и обязательные атрибуты при вставке (NOT NULL без
 * значения по умолчанию — ошибка базы), существование цели связи по внешнему ключу (ошибка внешнего ключа базы).
 */
final class WriteDocumentParser
{
    private const array ORDER = ['remove' => 0, 'keep' => 1, 'update' => 1, 'add' => 2];

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
     * @return list<array> корни дерева изменений в порядке `data`
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
        $pointers = array_is_list($data) ? array_map(static fn(int $index): string => "/data/{$index}", array_keys($data)) : ['/data'];

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
            fn(int $index): array => $this->resource(item: $items[$index], class: $resource, pointer: $pointers[$index], operation: 'add'),
            array_keys($items),
        );
        $this->checkUsed();

        return $roots;
    }

    /**
     * DELETE `/certificates/{id}`: тела нет (документ JSON:API у DELETE не передаётся) — только проверка возможности и
     * корень с операцией `remove`. Ресурс не `Deletable` — 403; id не из цифр — 404 (такого ресурса быть не может).
     * Указателя у этих ошибок нет: документа нет. Что удаляется вместе с ресурсом, решает база (каскад, см.
     * `DeleteResourceWriter`); `Deletable` потомков не проверяется.
     *
     * @param class-string<Resource> $resource ресурс точки входа
     * @param string                 $id       id из маршрута
     * @return array корень дерева изменений
     * @throws WriteDocumentException
     */
    public function delete(string $resource, string $id): array
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

        return [
            'type' => $resource::type(),
            'id' => $id,
            'attributes' => [],
            'relationships' => [],
            'patch:op' => 'remove',
            'patch:pointer' => null,
        ];
    }

    /**
     * PATCH: `data` — один существующий ресурс точки входа; `type` и `id` совпадают с маршрутом (409).
     *
     * @param class-string<Resource> $resource ресурс точки входа (`PATCH /certificates/{id}` → CertificateResource)
     * @param string                 $id       id из маршрута
     * @return array корень дерева изменений
     * @throws WriteDocumentException
     */
    public function update(array $document, string $resource, string $id): array
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

        $root = $this->resource(item: $data, class: $resource, pointer: '/data', operation: 'update');
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
     * Ресурс места `$class` → узел дерева с разобранными связями.
     *
     * @param class-string<Resource> $class
     */
    private function resource(array $item, string $class, string $pointer, string $operation): array
    {
        if (($item['type'] ?? null) !== $class::type()) {
            throw new WriteDocumentException(
                status: '400',
                pointer: "{$pointer}/type",
                detail: "type must be {$class::type()}",
            );
        }

        if ($operation === 'add' && !is_a($class, Insertable::class, true)) {
            throw new WriteDocumentException(
                status: '403',
                pointer: $pointer,
                detail: "resource {$class::type()} can not be created",
            );
        }
        if ($operation === 'update' && !is_a($class, Updatable::class, true)) {
            throw new WriteDocumentException(
                status: '403',
                pointer: $pointer,
                detail: "resource {$class::type()} can not be changed",
            );
        }

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
            if ($name === $class::primaryAttribute() || ($class::ATTRIBUTES[$name]['mode'] ?? null) === Resource::READ) {
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
            if ($class::foreignRelationship($name) !== null) {
                $target = $value['data'];
                if ($target !== null && (!is_array($target) || ($target['type'] ?? null) !== $relationship->resource::type())) {
                    throw new WriteDocumentException(
                        status: '400',
                        pointer: "{$relationship_pointer}/data/type",
                        detail: "type must be {$relationship->resource::type()}",
                    );
                }
                if ($target !== null && (!isset($target['id']) || isset($target['lid']) || !(is_string($target['id']) && ctype_digit($target['id'])))) {
                    throw new WriteDocumentException(
                        status: '400',
                        pointer: "{$relationship_pointer}/data",
                        detail: "{$name} refers to an existing resource: {type, id} or null",
                    );
                }
                $relationships[$name] = ['data' => $target === null ? null : ['type' => $target['type'], 'id' => $target['id']]];
            } elseif ($relationship instanceof OneToMany && $relationship->relation[0] === $class::primaryColumn()) {
                $relationships[$name] = $this->children(
                    target: $relationship->resource,
                    value: $value,
                    pointer: $relationship_pointer,
                    parent_is_new: $operation === 'add',
                );
            } elseif ($relationship instanceof OneToOne && $relationship->relation[0] === $class::primaryColumn()) {
                $relationships[$name] = $this->child(
                    target: $relationship->resource,
                    value: $value,
                    pointer: $relationship_pointer,
                    parent_is_new: $operation === 'add',
                );
            } else {
                throw new WriteDocumentException(
                    status: '400',
                    pointer: $relationship_pointer,
                    detail: "{$name} can not be changed here",
                );
            }
        }

        return [
            'type' => $item['type'],
            ...(isset($item['id']) ? ['id' => (string) $item['id']] : []),
            ...(isset($item['lid']) ? ['lid' => (string) $item['lid']] : []),
            'attributes' => $attributes,
            'relationships' => $relationships,
            'patch:op' => $operation,
            'patch:pointer' => $pointer,
        ];
    }

    /**
     * Список связи по владению → дети в порядке выполнения и признак полной замены.
     *
     * @param class-string<Resource> $target
     * @return array{data: list<array>, 'patch:replace': bool}
     */
    private function children(string $target, mixed $value, string $pointer, bool $parent_is_new): array
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
            self::deletable(target: $target, pointer: "{$pointer}/data", detail: "a list without meta.op replaces the relationship and deletes unlisted {$target::type()}, which can not be deleted: mark elements with meta.op for a partial change");
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
            $operation = $identifier['meta']['op'] ?? null;
            if ($operation !== null && !in_array($operation, ['add', 'update', 'remove'], true)) {
                throw new WriteDocumentException(
                    status: '400',
                    pointer: "{$identifier_pointer}/meta/op",
                    detail: 'op must be add, update or remove',
                );
            }
            $included = $this->included[$key] ?? null;

            // Новый ребёнок: только с ресурсом в included
            if (isset($identifier['lid'])) {
                if ($operation !== null && $operation !== 'add') {
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
                $children[] = $this->resource(item: $included['resource'], class: $target, pointer: $included['pointer'], operation: 'add');
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
            if ($operation === 'add') {
                throw new WriteDocumentException(
                    status: '400',
                    pointer: "{$identifier_pointer}/meta/op",
                    detail: 'moving an existing resource to another parent is not supported',
                );
            }
            if ($operation === 'remove') {
                self::deletable(target: $target, pointer: $identifier_pointer, detail: "{$target::type()} can not be deleted");
                if ($included !== null) {
                    throw new WriteDocumentException(
                        status: '400',
                        pointer: $included['pointer'],
                        detail: "{$key} is removed, it can not be in included",
                    );
                }
                $children[] = ['type' => $target::type(), 'id' => (string) $identifier['id'], 'patch:op' => 'remove', 'patch:pointer' => $identifier_pointer];
                continue;
            }
            if ($included !== null) {
                $this->used[$key] = true;
                $children[] = $this->resource(item: $included['resource'], class: $target, pointer: $included['pointer'], operation: 'update');
                continue;
            }
            if ($operation === 'update') {
                throw new WriteDocumentException(
                    status: '400',
                    pointer: $identifier_pointer,
                    detail: "update needs {$key} in included",
                );
            }
            $children[] = ['type' => $target::type(), 'id' => (string) $identifier['id'], 'patch:op' => 'keep', 'patch:pointer' => $identifier_pointer];
        }

        usort($children, static fn(array $left, array $right): int => self::ORDER[$left['patch:op']] <=> self::ORDER[$right['patch:op']]);

        return ['data' => $children, 'patch:replace' => $marked === [] && !$parent_is_new];
    }

    /**
     * Связь to-one по владению (`delivery`) → ребёнок (список из одного или пустой) и признак замены. `meta.op` не
     * нужен, операцию даёт форма:
     *
     *     {type, lid} + ресурс в included   replace: текущий ребёнок удаляется, новый вставляется
     *     {type, id}  + ресурс в included   update текущего ребёнка (чужой — 404)
     *     {type, id}                        keep: только проверка, что это текущий ребёнок
     *     null                              replace без нового: текущий ребёнок удаляется (его нет — не ошибка)
     *
     * У нового родителя: `{lid}` — add, `null` — ничего, `{id}` — 400. Замена исполняется тем же
     * `PatchReplaceWriter`, что и полная замена to-many: `DELETE … WHERE <колонка связи> = родитель` без названных id,
     * потом вставка (`PatchJoinWriter`).
     *
     * @param class-string<Resource> $target
     * @return array{data: list<array>, 'patch:replace': bool, 'patch:to-one': true}
     */
    private function child(string $target, mixed $value, string $pointer, bool $parent_is_new): array
    {
        if (!is_array($value) || !array_key_exists('data', $value) || (is_array($value['data']) && array_is_list($value['data']) && $value['data'] !== [])) {
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
                self::deletable(target: $target, pointer: $identifier_pointer, detail: "null deletes the current {$target::type()}, which can not be deleted");
            }

            return ['data' => [], 'patch:replace' => !$parent_is_new, 'patch:to-one' => true];
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
                self::deletable(target: $target, pointer: $identifier_pointer, detail: "lid replaces the current {$target::type()}, which can not be deleted");
            }

            return [
                'data' => [$this->resource(item: $included['resource'], class: $target, pointer: $included['pointer'], operation: 'add')],
                'patch:replace' => !$parent_is_new,
                'patch:to-one' => true,
            ];
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
            $child = $this->resource(item: $included['resource'], class: $target, pointer: $included['pointer'], operation: 'update');
        } else {
            $child = ['type' => $target::type(), 'id' => (string) $identifier['id'], 'patch:op' => 'keep', 'patch:pointer' => $identifier_pointer];
        }

        return ['data' => [$child], 'patch:replace' => false, 'patch:to-one' => true];
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
 * Узел плана PATCH: место дерева изменений — ресурс и ветви по операциям, которые встретились у ресурсов этого места.
 * Исполнитель — `PatchOperationWriter`. Ветви нет (`null`) — операции нет в документе: `add` — поддерево вставки
 * (`Plan\Insert`), `update` — изменения (его же исполняет `keep`), `remove` — удаления.
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
 * Узел есть, если связь заменяет хотя бы один владелец этого места; какой именно — говорит `patch:replace` связи в
 * дереве изменений (как `patch:op` для ветвей `PatchOperationNode`). У POST его не бывает.
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
 * Планировщик PATCH: ресурс точки входа + дерево изменений (`WriteDocumentParser::parse()`) → план (неизменяемые
 * узлы выше). Пара к `InsertPlanner`; исполнителей по плану строит `PatchWriterBuilder`.
 *
 * Поток: класс ресурса, корни дерева изменений (+ родитель и связь вложенного маршрута) → `WriteRootNode` над
 * `PatchJoinNode|PatchReplaceNode|PatchOperationNode`. Корни — одно место (несколько корней POST — объединение, как у
 * `InsertPlanner`). Вложенный маршрут — форма (`RouteParentNode`: кто родитель, колонки связи), его id — значение,
 * в план не попадает.
 *
 * План строится по форме дерева изменений, как план вставки по документу: место — ресурс и все его элементы этого
 * места (корень; все дети одной связи у всех родителей места). У места:
 *  - `PatchOperationNode` с ветвями только тех операций, что встретились у элементов (`add` → `InsertResourceNode` с
 *    таблицами `InsertPlanner::tables()`, `update`/`keep` → `UpdateResourceNode` с таблицами `tables()`, `remove` →
 *    `DeleteResourceNode`);
 *  - связи, которые есть хотя бы у одного элемента места, — левый глубокий список над ним: связи по владению
 *    (`relationships()`, в порядке объявления) — сначала `PatchReplaceNode`, если связь заменяет целиком хотя бы
 *    один элемент места, потом `PatchJoinNode`, если у связи есть хотя бы один ребёнок (его `child` — место из детей
 *    связи). Отсюда порядок выполнения: владелец, удаление неназванных, дети. Замена пустым списком — узел замены
 *    без узла связи; у POST узла замены нет, план равен плану `InsertPlanner`. Связи по внешнему ключу пишет строка
 *    ресурса, узла нет.
 *
 * Форма плана говорит, что в месте бывает; для какого элемента — данные: `patch:op` выбирает ветвь
 * `PatchOperationNode`, `patch:replace` связи — выполнять ли удаление в `PatchReplaceNode`. Поэтому `add`, `remove` и
 * `update` в одной связи — один узел места с тремя ветвями. Вход проверен парсером; несоответствие — LogicException.
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
     * @param list<array>                 $roots        корни дерева изменений: список из `insert()` или `[update()]`
     * @param class-string<Resource>|null $parent       родитель вложенного маршрута (`/certificates/{id}/domains` →
     *                                                  CertificateResource), null — маршрут не вложенный
     * @param string|null                 $relationship связь родителя к ресурсу точки входа (`domains`)
     */
    public function plan(string $resource, array $roots, ?string $parent = null, ?string $relationship = null): WriteRootNode
    {
        return new WriteRootNode(
            input: $this->place(resource: $resource, items: $roots),
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
     * @param list<array>            $items    элементы дерева изменений этого места
     */
    private function place(string $resource, array $items): PatchJoinNode|PatchReplaceNode|PatchOperationNode
    {
        $operations = array_map(static fn(array $item): string => (string) $item['patch:op'], $items);
        $node = new PatchOperationNode(
            resource: $resource,
            add: in_array('add', $operations, true)
                ? new InsertResourceNode(
                    resource: $resource,
                    input: new InsertPlanner()->tables(resource: $resource),
                )
                : null,
            update: array_intersect(['update', 'keep'], $operations) !== []
                ? new UpdateResourceNode(
                    resource: $resource,
                    input: $this->tables(resource: $resource),
                )
                : null,
            remove: in_array('remove', $operations, true)
                ? new DeleteResourceNode(resource: $resource)
                : null,
        );

        foreach (array_keys($resource::relationships()) as $name) {
            $name = (string) $name;
            // Связь изменения — с признаком замены; связь по внешнему ключу его не несёт: её пишет строка ресурса
            $present = array_values(array_filter($items, static fn(array $item): bool => isset($item['relationships'][$name]['patch:replace'])));
            if ($present === []) {
                continue;
            }
            $relationship = $resource::relationship($name) ?? throw new LogicException("{$resource}: unknown relationship {$name}");
            /** @var array{0: string, 1: string} $relation */
            $relation = $relationship->relation;
            $children = array_merge(...array_map(static fn(array $item): array => $item['relationships'][$name]['data'], $present));
            if (array_any($present, static fn(array $item): bool => $item['relationships'][$name]['patch:replace'])) {
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
                    child: $this->place(resource: $relationship->resource, items: $children),
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
 * Корень записи: проверки по всему запросу, затем каждый корень документа в одно дерево. Вместо пары
 * `InsertRootWriter` + `InsertRouteLinkWriter` из `src/`: проверка родителя маршрута должна пройти один раз и до
 * первой записи, а не на каждом корне. Сам не PushExecutor: сверху ему ничего не приходит.
 *
 * Поток: `route->link()` (проверка родителя, колонки связи; null — маршрут не вложенный) → `root->push(корень, link)`
 * по очереди → `list<ключи корня>`.
 *
 * Транзакцию не открывает: атомарность — у вызывающего.
 */
final readonly class WriteRootWriter
{
    /**
     * @param list<array> $data корни дерева изменений
     */
    public function __construct(
        private PushExecutor $root,
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

        return array_map(fn(array $resource): array => $this->root->push($resource, $link), $this->data);
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
 * Развилка по операции узла: `add` → вставка (`InsertResourceWriter`), `update`/`keep` → изменение или проверка,
 * `remove` → удаление. Пустые ключи у update/keep/remove — 404 с указателем узла. Ветви есть только для операций,
 * которые встретились в документе (`PatchPlanner`); операция без ветви — LogicException.
 *
 * Поток: узел (`patch:op`) + `$link` → исполнитель операции → ключи наверх (их берёт PatchJoinWriter для детей).
 */
final readonly class PatchOperationWriter implements PushExecutor
{
    /**
     * @param class-string<Resource> $resource
     */
    public function __construct(
        private string $resource,
        private ?PushExecutor $add,
        private ?PushExecutor $update,
        private ?PushExecutor $remove,
    ) {}

    #[\Override]
    public function push(array $resource, array $link = []): array
    {
        $operation = (string) $resource['patch:op'];
        $branch = match ($operation) {
            'add' => $this->add,
            'update', 'keep' => $this->update,
            'remove' => $this->remove,
            default => throw new LogicException("Unknown operation {$operation}"),
        };
        $keys = ($branch ?? throw new LogicException("{$this->resource}: no {$operation} branch in the plan"))->push($resource, $link);
        if ($keys === []) {
            throw new WriteDocumentException(
                status: '404',
                pointer: isset($resource['patch:pointer']) ? (string) $resource['patch:pointer'] : null,
                detail: "{$resource['type']} {$resource['id']} not found" . ($link === [] ? '' : ' under ' . json_encode($link)),
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
 * Связь по владению при PATCH (to-many и to-one): родитель (`parent`), затем его дети из `relationships[name].data`
 * в `child` с `link = [relation[1] => keys[relation[0]]]` — как `InsertJoinWriter`. To-one приходит от парсера тем же
 * списком (один ребёнок).
 *
 * Поток: узел с поддеревом → `parent` → ключи → каждый ребёнок в `child` по порядку (парсер уже упорядочил: remove,
 * keep/update, add) → ключи родителя наверх. Связи нет в документе — дети не трогаются. Полную замену выполняет
 * `PatchReplaceWriter` внутри `parent`, то есть до детей.
 */
final readonly class PatchJoinWriter implements PushExecutor
{
    /**
     * @param array{0: string, 1: string} $relation [колонка родителя, колонка ребёнка]
     */
    public function __construct(
        private string $name,
        private array $relation,
        private PushExecutor $parent,
        private PushExecutor $child,
    ) {}

    #[\Override]
    public function push(array $resource, array $link = []): array
    {
        $keys = $this->parent->push($resource, $link);
        $relationship = $resource['relationships'][$this->name] ?? null;
        if ($relationship === null) {
            return $keys;
        }
        $parent_key = $keys[$this->relation[0]] ?? throw new LogicException("No key {$this->relation[0]} for {$this->name}");
        foreach ($relationship['data'] as $child) {
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
 * Поток: узел с поддеревом + `$link` → `input` → ключи владельца → если у связи `patch:replace` — `DELETE FROM
 * <главная таблица ребёнка> WHERE <relation[1]> = :parent AND <pk> NOT IN (id названных детей)` (без названных — все
 * дети владельца) → те же ключи наверх. Связи нет в документе или она без `patch:replace` (частичное изменение у этого
 * владельца) — только `input`.
 *
 * Собственных детей удалённого ребёнка удаляет `ON DELETE CASCADE` базы.
 */
final readonly class PatchReplaceWriter implements PushExecutor
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
        private PushExecutor $input,
    ) {}

    #[\Override]
    public function push(array $resource, array $link = []): array
    {
        $keys = $this->input->push($resource, $link);
        $relationship = $resource['relationships'][$this->name] ?? null;
        if ($relationship === null || !$relationship['patch:replace']) {
            return $keys;
        }
        $parent_key = $keys[$this->relation[0]] ?? throw new LogicException("No key {$this->relation[0]} for {$this->name}");

        $entity = Tables::mainEntity(resource: $this->child_resource);
        $primary = $this->child_resource::primaryColumn();
        if (!isset($entity::COLUMNS[$this->relation[1]])) {
            throw new LogicException("{$entity}: link column {$this->relation[1]} is not in the main table");
        }
        $listed = array_values(array_map(
            static fn(array $child): int => (int) $child['id'],
            array_filter($relationship['data'], static fn(array $child): bool => isset($child['id'])),
        ));

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
 * Граница уровней при изменении: узел дерева изменений → строка «колонка модели → значение» → поддерево таблиц
 * (`PatchPlanner::tables()` по `model()`). Пара к `InsertResourceWriter`.
 *
 * Поток: узел (`id`, `attributes`, связи по внешнему ключу) + `$link` → `input->push(строка, link + [pk => id])` →
 * ключи всех таблиц; пусто — строки главной таблицы нет (или она чужая: колонки связи от родителя — условие
 * принадлежности), наверху это 404.
 *
 * В строку идут только присланные атрибуты; `null` — это NULL в колонку (присутствие ключа, а не значение, решает,
 * что колонку трогать). Колонка связи от родителя должна быть колонкой модели, иначе принадлежность не проверить —
 * LogicException. Если первичный ключ ресурса и есть колонка связи (`delivery.certificate_id`), id, не равный
 * родителю, — пусто (404) без запроса.
 */
final readonly class UpdateResourceWriter implements PushExecutor
{
    /**
     * @param class-string<Resource> $resource
     */
    public function __construct(
        private string $resource,
        private TableWriter $input,
    ) {}

    #[\Override]
    public function push(array $resource, array $link = []): array
    {
        $tree = ModelTree::of($this->resource);
        foreach (array_keys($link) as $column) {
            if (!$tree->hasColumn((string) $column)) {
                throw new LogicException("{$this->resource}: link column {$column} is not a model column");
            }
        }

        $primary = $this->resource::primaryColumn();
        // Первичный ключ ребёнка — сама колонка связи (`delivery.certificate_id`): id обязан совпасть с родителем
        if (isset($link[$primary]) && (int) $link[$primary] !== (int) $resource['id']) {
            return [];
        }

        return $this->input->push(
            Tables::row(resource: $this->resource, data: $resource),
            [...$link, $primary => (int) $resource['id']],
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
 * Поток: узел (`id`) + `$link` → SQL → `[pk => id]`; пусто — строки нет.
 */
final readonly class DeleteResourceWriter implements PushExecutor
{
    /**
     * @param class-string<Resource> $resource
     */
    public function __construct(
        private PDO $pdo,
        private string $resource,
    ) {}

    #[\Override]
    public function push(array $resource, array $link = []): array
    {
        $entity = Tables::mainEntity(resource: $this->resource);
        $primary = $this->resource::primaryColumn();
        $statement = new Delete(new UpdateOrDeleteTarget(new QualifiedName($entity::TABLE)));
        $statement->returning->replace([new TargetElement(new ColumnReference($entity::column($primary)))]);
        $parameters = [
            'key_id' => (int) $resource['id'],
            ...Tables::where(statement: $statement, entity: $entity, primary: $primary, link: $link),
        ];

        return Tables::run(pdo: $this->pdo, statement: $statement, parameters: $parameters) === []
            ? []
            : [$primary => (int) $resource['id']];
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
     * @param class-string<Resource> $resource
     * @return array<string, mixed> колонка модели → значение
     */
    public static function row(string $resource, array $data): array
    {
        $row = [];
        foreach ($data['attributes'] ?? [] as $name => $value) {
            $attribute = $resource::ATTRIBUTES[$name] ?? throw new LogicException("{$resource}: unknown attribute {$name}");
            $row[(string) ($attribute['column'] ?? throw new LogicException("{$resource}: composite attribute {$name} is not supported"))] = $value;
        }
        foreach ($data['relationships'] ?? [] as $name => $relationship) {
            $foreign = $resource::foreignRelationship((string) $name);
            if ($foreign !== null) {
                $row[$foreign->relation[0]] = $relationship['data'] === null ? null : (int) $relationship['data']['id'];
            }
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
 * собирает он сам.
 *
 * Поток: неизменяемое дерево плана → `WriteRootWriter` (`root()`) над деревом `PushExecutor` на одно выполнение (план
 * переиспользуемый). `WriteRootNode` → `WriteRootWriter`, `RouteParentNode` → `RouteParentWriter` (с id из маршрута).
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
     * @param list<array> $data     корни дерева изменений
     * @param int|null    $route_id id родителя из вложенного маршрута; обязателен, если в плане есть `route`
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

    public function build(PatchJoinNode|PatchReplaceNode|PatchOperationNode $plan): PushExecutor
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
            add: $plan->add === null ? null : new WriterBuilder(pdo: $this->pdo)->build(plan: $plan->add),
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
// Печать: дерево изменений, план и дерево исполнителей текстом

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
 * Дерево изменений текстом: операция, тип, id/lid, атрибуты; связи с режимом и элементами с отступом.
 */
function changes(array $node, string $indent = ''): string
{
    $identity = 'new';
    if (isset($node['lid'])) {
        $identity = "lid {$node['lid']}";
    }
    if (isset($node['id'])) {
        $identity = "id {$node['id']}";
    }
    $attributes = ($node['attributes'] ?? []) === [] ? '' : ' ' . json_encode($node['attributes'], JSON_UNESCAPED_UNICODE);
    $output = "{$indent}{$node['patch:op']} {$node['type']} {$identity}{$attributes}\n";
    foreach ($node['relationships'] ?? [] as $name => $relationship) {
        if (isset($relationship['patch:replace'])) {
            $to_one = isset($relationship['patch:to-one']);
            $kind = $to_one ? 'owned to-one' : 'owned';
            if ($node['patch:op'] === 'add') {
                $mode = 'new parent: everything listed is added';
            } elseif ($to_one) {
                $mode = $relationship['patch:replace'] ? 'replace (the current one is deleted first)' : 'keep or update the current one';
            } elseif ($relationship['patch:replace']) {
                $mode = 'replace (unlisted leave the relationship)';
            } else {
                $mode = 'partial (unlisted are kept)';
            }
            $output .= "{$indent}    {$name} ({$kind}): {$mode}\n";
            foreach ($relationship['data'] as $child) {
                $output .= changes($child, "{$indent}        ");
            }
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
    echo "######## change tree\n", changes($certificate_root), "\n";
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
    echo "######## change tree\n", implode('', array_map(static fn(array $root): string => changes(node: $root), $post_roots)), "\n";
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
    echo "######## change tree\n", implode('', array_map(static fn(array $root): string => changes(node: $root), $route_roots)), "\n";
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
    echo "######## change tree\n", changes($delete_root), "\n";
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
    echo "######## change tree\n", changes($replace_root), "\n";
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
    echo "######## change tree\n", changes($empty_root), "\n";
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
