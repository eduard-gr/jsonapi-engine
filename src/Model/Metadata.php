<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Model;

/**
 * Значения метаданных, общие для сущностей и описаний поверх них: типы, ограничения и режимы.
 *
 * `Entity` реализует интерфейс (и `Resource`), поэтому в наследниках значения доступны как
 * `self::INT`.
 */
interface Metadata
{
    public const string INT = 'int';
    public const string FLOAT = 'float';
    public const string STRING = 'string';
    public const string BOOL = 'bool';
    public const string DATE = 'date';
    public const string DATETIME = 'datetime';

    public const string PRIMARY = 'primary';
    public const string FOREIGN = 'foreign';

    public const string READ = 'read';
    public const string READ_WRITE = 'read_write';

    public const array TYPES = [self::INT, self::FLOAT, self::STRING, self::BOOL, self::DATE, self::DATETIME];
}
