<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Executor\Select;

/**
 * Проверяет, что поток ресурсов упорядочен по ключу строго по возрастанию: каждый ключ встречается один раз.
 * Наследник `AscendingKeyReader`: тот допускает повторы ключа, этот считает повтор нарушением.
 *
 * Поток: вход — ?ResourceRecord → выход — тот же поток, элемент в элемент; null проходит без проверки (как у
 * родителя).
 *
 * Ставится на вход outer `MergeNestJoinReader`: ключ главного ресурса уникален, и повтор означает, что строки
 * родителя размножились (или поток не тот). `ReaderBuilder` ставит этот узел на outer и `AscendingKeyReader` на
 * inner; дерево, собранное руками, выбирает узел само.
 *
 * Нарушение — `UnexpectedValueException` на первом ключе не по порядку, посреди потока (как у родителя).
 */
final readonly class UniqueAscendingKeyReader extends AscendingKeyReader
{
    protected const string ORDER = 'strictly ascending';

    /**
     * Нарушен ли порядок: ключ не больше предыдущего — повтор тоже нарушение.
     */
    #[\Override]
    protected function isOutOfOrder(int $key, int $previous_key): bool
    {
        return $key <= $previous_key;
    }
}
