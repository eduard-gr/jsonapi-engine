<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor;

/**
 * Узел дерева вставки уровня ресурсов (push): получает сверху ресурс с поддеревом новых детей, вставляет его (и, если
 * это связь, детей), возвращает ключи строки ресурса. Пара к ExecutorIterator: тот отдаёт поток наверх (pull),
 * этот принимает данные сверху (push); суффикс `…Writer` у исполнителей вставки — как `…Iterator` у исполнителей
 * чтения, а `…Node` остаётся за узлами плана.
 *
 * Поток: ресурс из дерева `Parser\DocumentParser` (объект JSON:API, новые дети подставлены в `relationships`) +
 * колонки связи от родителя → строки в базе → ключи ресурса.
 *
 * Дерево вставки — два уровня (как SharedRowJoin и HashNestJoin в SELECT): над `InsertResourceWriter` по дереву идут
 * ресурсы (ExecutorWriter: `InsertJoinWriter` раздаёт детей по связи), под ним — строки «колонка модели → значение»
 * (TableWriter: `InsertTableJoinWriter` делит строку по таблицам модели, `InsertTableWriter` пишет одну таблицу).
 * Корень — `InsertDocument`: отправляет в дерево каждый корень документа.
 *
 * Общего контекста нет: ключи возвращаются из `push()` и уходят детям колонкой связи (`$link`). Узлы без состояния
 * между вызовами (кроме PDO), но дерево строится на одно выполнение, как и дерево итераторов.
 */
interface ExecutorWriter
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
