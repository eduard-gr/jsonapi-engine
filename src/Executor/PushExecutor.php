<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor;

/**
 * Контракт push-узла дерева исполнителя уровня ресурсов: получает сверху ресурс с поддеревом детей, обрабатывает его
 * (и, если это связь, детей), возвращает ключи строки ресурса. Пара к PullExecutor: тот отдаёт поток наверх (pull),
 * этот принимает данные сверху (push). Общий для операций записи: сейчас его реализуют исполнители вставки
 * (`Executor\Insert\…Writer`), позже — обновления и удаления. Суффикс `…Writer` — как `…Iterator` у pull-узлов,
 * `…Node` остаётся за узлами плана. Узел-маршрутизатор (`InsertJoinWriter`) сам в базу не пишет, но он тоже push
 * и тоже `…Writer`.
 *
 * Поток: ресурс из дерева `Parser\Insert\DocumentParser` (объект JSON:API, новые дети подставлены в `relationships`) +
 * колонки связи от родителя → строки в базе → ключи ресурса.
 *
 * Дерево вставки — два уровня (как SharedRowJoin и HashNestJoin в SELECT): над `InsertResourceWriter` по дереву идут
 * ресурсы (PushExecutor: `InsertJoinWriter` раздаёт детей по связи), под ним — строки «колонка модели → значение»
 * (TableWriter: `InsertTableJoinWriter` делит строку по таблицам модели, `InsertTableWriter` пишет одну таблицу).
 * Корень — `InsertRootWriter`: отправляет в дерево каждый корень документа; под ним при вложенном маршруте —
 * `InsertRouteLinkWriter` с колонками связи из маршрута. По плану (`Plan\Insert\InsertPlanner`)
 * дерево строит `WriterBuilder`.
 *
 * Общего контекста нет: ключи возвращаются из `push()` и уходят детям колонкой связи (`$link`). Узлы без состояния
 * между вызовами (кроме PDO), но дерево строится на одно выполнение, как и дерево итераторов.
 */
interface PushExecutor
{
    /**
     * @param array                $resource ресурс из дерева `DocumentParser` (с детьми в `relationships`)
     * @param array<string, mixed> $link     колонки связи от родительского узла (`certificate_id => 21`)
     * @return array<string, mixed> ключи строки ресурса: записанные колонки + первичные ключи его таблиц
     */
    public function push(array $resource, array $link = []): array;

    /**
     * Описание узла и поддерева без выполнения.
     *
     * @return array<string, mixed> Ключ 'node' — класс узла, остальные — его параметры и дочерние узлы
     */
    public function explain(): array;
}
