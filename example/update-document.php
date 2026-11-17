<?php

/**
 * Изменение с деревом исполнителей, собранным руками: `PATCH /certificates/{id}` над
 * `CertificateWithDetailResource` (`LeftJoin(certificates, certificate_details)`). Документ →
 * `Parser\Write\WriteDocumentParser::update()` (дерево операций) → дерево исполнителей (`…Writer`, push) → строки
 * в таблицах SQLite базы example/db. То же дерево по плану строит `WritePlanner` + `WriterBuilder` —
 * example/patch-document.php (там же связи по владению, POST и DELETE); вставка руками — example/insert-document.php.
 *
 *     php example/update-document.php            # дерево (explain), по каждому сценарию ключи и строка после UPDATE;
 *                                                # SQL каждого запроса — в stderr
 *     php example/update-document.php 2>sql.log  # SQL отдельно
 *
 * Руками собраны оба уровня дерева:
 *  - уровень ресурсов: `WriteRootWriter` → `WriteOperationWriter` с одной ветвью `update` (в документе других
 *    операций нет) → `UpdateResourceWriter` (операция → строка «колонка модели → значение» и ключ: id ресурса —
 *    колонка первичного ключа главной таблицы);
 *  - уровень таблиц (контракт `TableWriter`): `UpdateTableJoinWriter(relation, main, joined)` — сначала сторона
 *    с главной таблицей (её ключ известен из id ресурса), потом присоединённая по ключу, который вернула первая;
 *    `UpdateTableWriter` — `UPDATE … SET <присланные колонки> WHERE <ключ> = :key AND (<колонка> IS DISTINCT FROM …
 *    OR …) RETURNING …`: неизменённые значения не перезаписываются; если ни одна колонка таблицы не прислана или
 *    все значения уже такие же — `SELECT` тех же колонок (ключи для присоединённых таблиц и проверка, что строка
 *    есть); `UpdateOptionalTableWriter` — правая сторона `LeftJoin`: без её колонок в строке пропускается, а если
 *    строки нет — вставляется (`InsertTableWriter`), как отсутствующая часть модели.
 *
 * Порядок сторон отличается от вставки: там первым идёт родитель по ключу связи (тот, чей первичный ключ нужен
 * другому), здесь — сторона, ключ которой уже известен (главная таблица ресурса), независимо от направления
 * внешнего ключа. Для `certificates → certificate_details` это совпадает.
 *
 * Сценарии (каждый — в своей транзакции, которая откатывается: база не меняется):
 *  1. обе таблицы: `nominal` (certificates) и `buyer_email` (certificate_details);
 *  2. только связь по внешнему ключу `provider`: `certificate_details` пропускается;
 *  3. сертификат без строки деталей (вставлен в той же транзакции): certificates — только `SELECT` (проверка, что он
 *     есть), certificate_details — `UPDATE` не нашёл строку, деталь вставляется;
 *  4. те же значения, что уже записаны: `UPDATE … AND (… IS DISTINCT FROM …)` строку не трогает, `SELECT` находит
 *     её — изменений нет, но и не 404;
 *  5. несуществующий id — `WriteNotFoundException` (404).
 */

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Example\UpdateDocument;

use Ggs\JsonApiEngine\Example\Entity\CertificateDetailsEntity;
use Ggs\JsonApiEngine\Example\Entity\CertificatesEntity;
use Ggs\JsonApiEngine\Example\Resource\CertificateWithDetailResource;
use Ggs\JsonApiEngine\Executor\Insert\InsertTableWriter;
use Ggs\JsonApiEngine\Executor\PushExecutor;
use Ggs\JsonApiEngine\Executor\Update\UpdateOptionalTableWriter;
use Ggs\JsonApiEngine\Executor\Update\UpdateResourceWriter;
use Ggs\JsonApiEngine\Executor\Update\UpdateTableJoinWriter;
use Ggs\JsonApiEngine\Executor\Update\UpdateTableWriter;
use Ggs\JsonApiEngine\Executor\Write\WriteNotFoundException;
use Ggs\JsonApiEngine\Executor\Write\WriteOperationWriter;
use Ggs\JsonApiEngine\Executor\Write\WriteRootWriter;
use Ggs\JsonApiEngine\Parser\InvalidDocumentException;
use Ggs\JsonApiEngine\Parser\Write\WriteDocumentParser;
use PDO;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/db/connection.php';

/**
 * Строка сертификата с деталями после обновления — чтобы увидеть, что изменилось.
 *
 * @return array<string, mixed>|false
 */
function certificateRow(PDO $pdo, int $id): array|false
{
    $statement = $pdo->prepare(
        'select c.id, c.provider_id, c.code, c.nominal, d.certificate_id as detail, d.buyer_email, d.recipient_name
           from certificates c left join certificate_details d on d.certificate_id = c.id where c.id = :id',
    );
    $statement->execute(['id' => $id]);

    return $statement->fetch(PDO::FETCH_ASSOC);
}

/**
 * Сценарий `PATCH /certificates/{id}` в своей транзакции, которая откатывается.
 *
 * @param int|null             $id   id сертификата; null — сертификат без строки деталей вставляется в этой же
 *                                   транзакции, и изменяется он
 * @param array<string, mixed> $data члены ресурса документа: `attributes`, `relationships`
 */
function scenario(PDO $pdo, PushExecutor $tree, string $title, ?int $id, array $data): void
{
    echo "######## {$title}\n";

    $pdo->beginTransaction();
    try {
        if ($id === null) {
            $pdo->exec("insert into certificates (provider_id, code, nominal) values (1, 'NO-DETAIL', '1.00')");
            $id = (int) $pdo->lastInsertId();
        }
        $root = new WriteDocumentParser()->update(
            document: ['data' => ['type' => 'certificate', 'id' => (string) $id, ...$data]],
            resource: CertificateWithDetailResource::class,
            id: (string) $id,
        );
        $keys = new WriteRootWriter(
            root: $tree,
            data: [$root],
            route: null,
        )->execute();
        echo 'keys:  ', json_encode($keys), "\n";
        echo 'after: ', json_encode(certificateRow(pdo: $pdo, id: $id)), "\n\n";
    } catch (InvalidDocumentException|WriteNotFoundException $exception) {
        echo json_encode($exception->toError()), "\n\n";
    } finally {
        $pdo->rollBack();
    }
}

$pdo = getPDO();

$certificate_columns = [
    CertificatesEntity::ID,
    CertificatesEntity::PROVIDER_ID,
    CertificatesEntity::CODE,
    CertificatesEntity::NOMINAL,
    CertificatesEntity::CURRENCY,
    CertificatesEntity::STATUS,
    CertificatesEntity::CREATED_AT,
];
$detail_columns = [
    CertificateDetailsEntity::BUYER_EMAIL,
    CertificateDetailsEntity::RECIPIENT_NAME,
    CertificateDetailsEntity::RECIPIENT_EMAIL,
    CertificateDetailsEntity::GREETING_TEXT,
    CertificateDetailsEntity::DESIGN_TEMPLATE_ID,
];

// Место сертификата: в документе только изменение корня, поэтому у развилки одна ветвь update
$tree = new WriteOperationWriter(
    resource: CertificateWithDetailResource::class,
    add: null,
    update: new UpdateResourceWriter(
        resource: CertificateWithDetailResource::class,
        input: new UpdateTableJoinWriter(
            relation: [CertificatesEntity::ID, CertificateDetailsEntity::CERTIFICATE_ID],
            // Главная таблица: строка ищется по id ресурса; id нужен присоединённой, поэтому он в returning
            main: new UpdateTableWriter(
                pdo: $pdo,
                entity: CertificatesEntity::class,
                columns: $certificate_columns,
                key: CertificatesEntity::ID,
                returning: [CertificatesEntity::ID],
            ),
            // certificate_details — правая сторона LeftJoin: изменить, а если строки нет — вставить
            joined: new UpdateOptionalTableWriter(
                update: new UpdateTableWriter(
                    pdo: $pdo,
                    entity: CertificateDetailsEntity::class,
                    columns: $detail_columns,
                    key: CertificateDetailsEntity::CERTIFICATE_ID,
                ),
                insert: new InsertTableWriter(
                    pdo: $pdo,
                    entity: CertificateDetailsEntity::class,
                    columns: $detail_columns,
                ),
            ),
        ),
    ),
    remove: null,
);

$explain = new WriteRootWriter(
    root: $tree,
    data: [],
    route: null,
)->explain();
echo json_encode($explain, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n\n";

scenario(
    pdo: $pdo,
    tree: $tree,
    title: 'PATCH /certificates/1: nominal (certificates) and buyer_email (certificate_details)',
    id: 1,
    data: ['attributes' => ['nominal' => '2500.00', 'buyer_email' => 'new-buyer@example.com']],
);

scenario(
    pdo: $pdo,
    tree: $tree,
    title: 'PATCH /certificates/1: only the foreign-key relationship provider, certificate_details is skipped',
    id: 1,
    data: ['relationships' => ['provider' => ['data' => ['type' => 'provider', 'id' => '2']]]],
);

scenario(
    pdo: $pdo,
    tree: $tree,
    title: 'PATCH of a certificate without details: SELECT certificates, UPDATE finds no detail row, INSERT it',
    id: null,
    data: ['attributes' => ['buyer_email' => 'first-buyer@example.com', 'recipient_name' => 'Boris']],
);

scenario(
    pdo: $pdo,
    tree: $tree,
    title: 'PATCH /certificates/1 with the values already stored: nothing is rewritten, no 404',
    id: 1,
    data: ['attributes' => ['nominal' => '1000', 'buyer_email' => 'buyer1@example.com']],
);

scenario(
    pdo: $pdo,
    tree: $tree,
    title: 'PATCH /certificates/999999: no such certificate',
    id: 999999,
    data: ['attributes' => ['nominal' => '1.00']],
);
