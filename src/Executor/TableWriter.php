<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor;

/**
 * Узел дерева вставки уровня таблиц: получает строку одного ресурса «колонка модели → значение», вставляет её части
 * в таблицы своего поддерева, возвращает их ключи. Строит `WriterBuilder` по плану `InsertPlanner::tables()` из `model()` ресурса; сверху его вызывает
 * `InsertResourceWriter` (см. ExecutorWriter).
 *
 * Поток: строка ресурса + колонки связи (`$link`) → строки в таблицах → ключи «колонка → значение» (записанные
 * колонки и первичные ключи всех вставленных таблиц, слитые в одну карту).
 */
interface TableWriter
{
    /**
     * @param array<string, mixed> $row  колонки модели ресурса → значение
     * @param array<string, mixed> $link колонки связи (не обязательно колонки модели: правая колонка `relation`
     *                                   соединения или колонка связи между ресурсами); лист берёт те, что есть
     *                                   в его сущности
     * @return array<string, mixed>
     */
    public function push(array $row, array $link = []): array;

    /**
     * Колонки модели, которые пишет поддерево: по ним необязательная сторона `LeftJoin` понимает, что её нет.
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
