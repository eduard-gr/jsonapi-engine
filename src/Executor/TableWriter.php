<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor;

/**
 * Контракт узла дерева записи уровня таблиц: получает строку одного ресурса «колонка модели → значение», пишет её
 * части в таблицы своего поддерева, возвращает их ключи. Общий для вставки (`Executor\Insert\…Table…Writer`) и
 * изменения (`Executor\Update\…Table…Writer`). Строит `Executor\Write\WriterBuilder` по плану таблиц из `model()`
 * ресурса (`InsertPlanner::tables()`, `UpdatePlanner::tables()`); сверху его вызывает исполнитель ресурса
 * (`InsertResourceWriter`, `UpdateResourceWriter`; см. PushExecutor).
 *
 * Поток: строка ресурса + колонки связи (`$link`) → строки в таблицах → ключи «колонка → значение» (записанные
 * колонки и ключи всех затронутых таблиц, слитые в одну карту); у изменения пусто — строки нет.
 */
interface TableWriter
{
    /**
     * @param array<string, mixed> $row  колонки модели ресурса → значение
     * @param array<string, mixed> $link колонки связи (не обязательно колонки модели: правая колонка `relation`
     *                                   соединения или колонка связи между ресурсами). Лист вставки пишет те, что
     *                                   есть в его сущности; лист изменения ищет по ним строку (ключ и условие
     *                                   принадлежности родителю)
     * @return array<string, mixed> ключи; пусто — часть модели пропущена (вставка) или строки нет (изменение)
     */
    public function push(array $row, array $link = []): array;

    /**
     * Колонки модели, которые пишет поддерево: по ним `InsertOptionalTableWriter` и `UpdateOptionalTableWriter`
     * (правая сторона `LeftJoin`) понимают, что этой части модели в строке нет.
     *
     * @return list<string>
     */
    public function columns(): array;

    /**
     * Описание узла и поддерева без выполнения.
     *
     * @return array<string, mixed> Ключ 'node' — класс узла, остальные — его параметры и дочерние узлы
     */
    public function explain(): array;
}
