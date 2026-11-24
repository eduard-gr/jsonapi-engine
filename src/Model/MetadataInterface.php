<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Model;

/**
 * Значения метаданных, общие для источников и описаний поверх них: типы, ограничения и режимы.
 *
 * `DataSource` реализует интерфейс (с ним — `DatabaseEntitySource`; и `Resource`), поэтому в наследниках значения доступны как
 * `self::INT`.
 */
interface MetadataInterface
{
    public const string INT = 'int';
    public const string FLOAT = 'float';
    public const string STRING = 'string';
    public const string BOOL = 'bool';
    public const string DATE = 'date';
    public const string DATETIME = 'datetime';

    public const string PRIMARY = 'primary';
    public const string FOREIGN = 'foreign';

    /**
     * Режим колонки или атрибута — битовая маска (`'mode' => self::READ | self::WRITE`), чтобы режимы можно было
     * сочетать и добавлять новые. Без ключа `mode` — `READ | WRITE`.
     *  - `READ` — значение отдаётся клиенту;
     *  - `WRITE` — клиент может прислать значение при вставке и изменении;
     *  - `FIXED` — постоянная колонка сущности (`DatabaseEntitySource`): значение задано в описании ключом `value`,
     *    движок сам подставляет его условием при чтении, изменении и удалении и значением при вставке. С `WRITE`
     *    не сочетается; у атрибута ресурса и у чужого источника не объявляется;
     *  - `OPTIONAL` — атрибут ресурса отдаётся только по запросу: клиент получает его, если назвал в `fields`, а
     *    без `fields` атрибута в ответе нет. Только у атрибута ресурса; у колонки источника не объявляется.
     */
    public const int READ = 1;
    public const int WRITE = 2;
    public const int FIXED = 4;
    public const int OPTIONAL = 8;

    /** `READ | WRITE` — режим по умолчанию */
    public const int READ_WRITE = self::READ | self::WRITE;

    /** все известные биты режима колонки источника */
    public const int MODES = self::READ | self::WRITE | self::FIXED;

    /** все известные биты режима атрибута ресурса */
    public const int ATTRIBUTE_MODES = self::READ | self::WRITE | self::OPTIONAL;

    public const array TYPES = [self::INT, self::FLOAT, self::STRING, self::BOOL, self::DATE, self::DATETIME];
}
