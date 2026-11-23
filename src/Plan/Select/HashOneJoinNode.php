<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Select;

/**
 * ONE_TO_ONE между потоками: одна из сторон читается из чужого источника (`Model\ForeignDataSource`), поэтому
 * в общую строку SQL связь не входит. Появляется на шаге `separate` (`SeparateForeignDataRule`) вместо
 * `EntitySharedJoinNode`; дальше это обычная ветка — её выносит над строкой `LiftBranchFromOuterRule`.
 * Исполнитель — `Executor\Select\HashOneJoinReader`.
 *
 * Сам узел от вида источника не зависит (он соединяет два потока по ключу), но план ставит его только рядом с
 * чужим источником: две сущности соединяет база в одном запросе (`EntitySharedJoinNode`). Внутри строки стоять не
 * может — `EntityScanNodeDraftPass` бросает `LogicException`.
 */
final class HashOneJoinNode extends JoinNode {}
