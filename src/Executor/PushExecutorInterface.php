<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor;

use Ggs\JsonApiEngine\Parser\Write\WriteOperation;

/**
 * Контракт push-узла дерева исполнителей уровня ресурсов: получает сверху операцию дерева операций
 * (`Parser\Write\WriteOperation`) с поддеревом детей, выполняет её (и, если это связь, операции над детьми),
 * возвращает ключи строки ресурса. Пара к PullExecutorInterface: тот отдаёт поток наверх (pull), этот принимает данные сверху
 * (push). Общий для записи: его реализуют исполнители `Executor\Write\` (связь, замена, развилка), `Insert\`,
 * `Update\` и `Delete\` (ресурс). Суффикс `…Writer` — как `…Reader` у pull-узлов, `…Node` остаётся за узлами
 * плана. Узел-маршрутизатор (`WriteJoinWriter`) сам в базу не пишет, но он тоже push и тоже `…Writer`.
 *
 * Поток: операция + колонки связи от родителя (`$link`: колонка → значение) → строки в базе → ключи ресурса (колонка
 * модели → значение) наверх; пусто — строки нет (у изменения, проверки и удаления наверху это 404).
 *
 * Дерево записи — два уровня (как EntitySharedJoin и HashNestJoin в SELECT): над исполнителями ресурса
 * (`InsertResourceWriter`, `UpdateResourceWriter`, `DeleteEntityWriter`) по дереву идут операции (PushExecutorInterface:
 * `WriteJoinWriter` раздаёт детей по связи, `WriteOperationWriter` выбирает ветвь по классу операции), под ними —
 * строки «колонка модели → значение» (`EntityWriterInterface`: соединение делит строку по таблицам модели, лист пишет одну
 * таблицу). Корень — `WriteRootWriter`: проверяет родителя вложенного маршрута и отправляет в дерево каждый корень.
 * По плану (`Plan\Write\WritePlanner`) дерево строит `Executor\Write\WriterBuilder`.
 *
 * Общего контекста нет: ключи возвращаются из `push()` и уходят детям колонкой связи (`$link`). Узлы без состояния
 * между вызовами (кроме PDO), но дерево строится на одно выполнение, как и дерево читателей.
 */
interface PushExecutorInterface
{
    /**
     * @param array<string, mixed> $link колонки связи от родительского узла (`certificate_id => 21`)
     * @return array<string, mixed> ключи строки ресурса: записанные колонки + первичные ключи его таблиц; пусто —
     *                              строки нет
     */
    public function push(WriteOperation $operation, array $link = []): array;

    /**
     * Описание узла и поддерева без выполнения.
     *
     * @return array<string, mixed> Ключ 'node' — класс узла, остальные — его параметры и дочерние узлы
     */
    public function explain(): array;
}
