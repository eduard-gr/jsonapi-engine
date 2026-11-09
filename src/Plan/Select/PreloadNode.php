<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Plan\Select;

/**
 * MANY_TO_ONE, ветка: справочник inner загружается отдельным запросом, поток outer проходит без изменений.
 *
 * Связь на справочник пишет сериализатор главного ресурса из внешнего ключа своей строки; узел нужен
 * только ради included. Порядок outer сохраняется, поэтому узел может стоять и внутри строки
 * (ветка 1-1 ресурса), и в outer MergeNestJoinNode.
 */
final class PreloadNode extends JoinNode {}
