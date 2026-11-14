<?php

/**
 * Набросок результата разбора документа записи: одно дерево операций («что сделать»).
 * Запуск: php example/resource-data.php
 *
 * Здесь только форма: классы дерева и один пример, собранный руками. Кода разбора документа и проверок в файле нет;
 * прототип записи example/patch-document.php этими классами пока не пользуется.
 *
 * Дерево одно. Разбор читает документ и сразу выдаёт операции (`InsertOperation`, `UpdateOperation`,
 * `KeepOperation`, `DeleteOperation`), связи по владению — `RelationshipOperation`. Данные ресурса (атрибуты, связи
 * по внешнему ключу) лежат в самой операции; ресурс назван `ResourceIdentity`: `PersistentIdentity` для `id`,
 * `LocalIdentity` для `lid`. Дерево читают планировщик и исполнители.
 *
 * Промежуточного дерева «документ как прислан» нет. Объекты спецификации JSON:API (resource object, relationship
 * object, resource identifier object) и пометка `meta.op` существуют только внутри разбора: он находит для
 * идентификатора связи его ресурс в `included`, проверяет, что каждый ресурс `included` использован ровно один раз,
 * и по правилам метода запроса выбирает операцию. Правила — в докблоке `RelationshipOperation`.
 *
 * Ошибки: указателя на место в документе (`pointer`) в классах нет. Ошибка формы документа возникает при чтении,
 * когда место известно и так, — она отдаётся с `source.pointer`. Ошибка выполнения называет ресурс типом и
 * `id` / `lid` в `meta` ошибки (плюс имя атрибута или связи); у корня POST без `lid` — номером в `data`.
 *
 * Пример — документ сценария 1 прототипа (`PATCH /certificates/1`): номинал и детали сертификата, доставка
 * заменяется через `lid`, в списке доменов домен 3 изменяется, домен 2 удаляется, новый домен добавляется.
 */

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Example\ResourceDataPrototype;

use Ggs\JsonApiEngine\Example\Resource\CertificateWithDetailResource;
use Ggs\JsonApiEngine\Example\Resource\DeliveryResource;
use Ggs\JsonApiEngine\Example\Resource\DomainResource;
use Ggs\JsonApiEngine\Resource;
use LogicException;

require __DIR__ . '/../vendor/autoload.php';

// ---------------------------------------------------------------------------------------------------------------
// Имя ресурса

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
     * Ключ для поиска внутри одного типа: `id:3` или `lid:d1`. Вместе с типом ресурса однозначен в документе — по
     * нему разбор находит ресурс `included` для идентификатора из связи.
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

// ---------------------------------------------------------------------------------------------------------------
// Операции

/**
 * Операция над одним ресурсом: узел дерева, которое выдаёт разбор. Вид операции — класс: `InsertOperation`,
 * `UpdateOperation`, `KeepOperation`, `DeleteOperation`; у каждого только те свойства, которые у операции бывают.
 *
 * Общее у всех — описание ресурса: по нему строится место плана. Указателя на место в документе у операций нет:
 * ошибка выполнения называет ресурс типом и `id` / `lid` (в `meta` ошибки), а они у операции есть.
 */
abstract readonly class WriteOperation
{
    /**
     * @param class-string<Resource> $resource
     */
    public function __construct(
        public string $resource,
    ) {}
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
 * Как разбор получает операцию из идентификатора связи (правила PATCH):
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
 * и для `replace`. `replace` у to-many — в списке нет ни одной пометки; у to-one — прислан `{lid}` или `null`. У
 * нового владельца (POST, вставленный ребёнок) замены не бывает: удалять нечего.
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

// ---------------------------------------------------------------------------------------------------------------
// Пример: документ сценария 1 прототипа и его дерево операций

$document = [
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

// Операции PATCH /certificates/1. Корень PATCH — изменение. Связей по внешнему ключу в документе нет — будь в нём
// `provider: {data: {type: provider, id: 2}}`, у корня было бы
// `references: ['provider' => new PersistentIdentity(id: 2)]`, а при `data: null` — `['provider' => null]`
$operation = new UpdateOperation(
    resource: CertificateWithDetailResource::class,
    identity: new PersistentIdentity(
        id: 1,
    ),
    attributes: [
        'nominal' => '2500.00',
        'buyer_email' => 'new-buyer@example.com',
        'recipient_name' => null,
    ],
    references: [],
    relationships: [
        // {lid del1} у to-one — замена: текущая доставка удаляется, новая вставляется
        'delivery' => new RelationshipOperation(
            replace: true,
            children: [
                new InsertOperation(
                    resource: DeliveryResource::class,
                    identity: new LocalIdentity(
                        lid: 'del1',
                    ),
                    attributes: ['delivery_channel' => 'SMS', 'is_sent' => false],
                    references: [],
                    relationships: [],
                ),
            ],
        ),
        // В списке есть пометка (remove у домена 2) — изменение частичное; порядок: удаление, изменение, вставка
        'domains' => new RelationshipOperation(
            replace: false,
            children: [
                new DeleteOperation(
                    resource: DomainResource::class,
                    identity: new PersistentIdentity(
                        id: 2,
                    ),
                ),
                new UpdateOperation(
                    resource: DomainResource::class,
                    identity: new PersistentIdentity(
                        id: 3,
                    ),
                    attributes: ['is_primary' => true],
                    references: [],
                    relationships: [],
                ),
                new InsertOperation(
                    resource: DomainResource::class,
                    identity: new LocalIdentity(
                        lid: 'd1',
                    ),
                    attributes: ['domain_name' => 'new.green1.net', 'is_primary' => false],
                    references: [],
                    relationships: [],
                ),
            ],
        ),
    ],
);

/**
 * Имя класса без пространства имён.
 */
function shortClassName(string $class): string
{
    return substr((string) strrchr('\\' . $class, '\\'), 1);
}

/**
 * Дерево операций текстом: класс операции, ресурс, id или lid; под ним присланные значения и связи с признаком
 * замены и операциями над детьми.
 */
function describe(WriteOperation $operation, string $indent = ''): string
{
    $output = $indent . shortClassName($operation::class) . ' ' . shortClassName($operation->resource);
    if ($operation instanceof KeepOperation || $operation instanceof DeleteOperation) {
        return "{$output} {$operation->identity->key()}\n";
    }
    if (!$operation instanceof InsertOperation && !$operation instanceof UpdateOperation) {
        throw new LogicException('Unknown operation ' . $operation::class);
    }
    $output .= ' ' . ($operation->identity === null ? 'без имени' : $operation->identity->key()) . "\n";
    $output .= "{$indent}    attributes: " . json_encode($operation->attributes, JSON_UNESCAPED_UNICODE) . "\n";
    $references = [];
    foreach ($operation->references as $name => $target) {
        $references[] = "{$name} → " . ($target === null ? 'null' : $target->key());
    }
    $output .= "{$indent}    references: " . ($references === [] ? 'нет' : implode(', ', $references)) . "\n";
    foreach ($operation->relationships as $name => $relationship) {
        $replace = $relationship->replace ? 'true' : 'false';
        $output .= "{$indent}    RelationshipOperation {$name}, replace: {$replace}\n";
        foreach ($relationship->children as $child) {
            $output .= describe(operation: $child, indent: "{$indent}        ");
        }
    }

    return $output;
}

$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
echo "######## document\n", json_encode($document, $flags), "\n\n";
echo "######## operations of PATCH /certificates/1\n", describe(operation: $operation);

// Вне сценария 1: операция со связями по внешнему ключу — цель названа `id`, снятая связь — null
$with_references = new UpdateOperation(
    resource: CertificateWithDetailResource::class,
    identity: new PersistentIdentity(
        id: 1,
    ),
    attributes: [],
    references: [
        'provider' => new PersistentIdentity(
            id: 2,
        ),
    ],
    relationships: [],
);
echo "\n######## references (not a part of scenario 1)\n", describe(operation: $with_references);

// Вне сценария 1: корень POST без `lid` — вставка без имени
$without_identity = new InsertOperation(
    resource: DomainResource::class,
    identity: null,
    attributes: ['domain_name' => 'new.green1.net'],
    references: [],
    relationships: [],
);
echo "\n######## POST root without lid (not a part of scenario 1)\n", describe(operation: $without_identity);
