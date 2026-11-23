<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Executor\Write;

use Ggs\JsonApiEngine\Example\Container\ArrayContainer;
use Ggs\JsonApiEngine\Example\Resource\CertificateResource;
use Ggs\JsonApiEngine\Executor\Write\ManualResourceDeserializer;
use Ggs\JsonApiEngine\Executor\Write\ResourceDeserializer;
use Ggs\JsonApiEngine\Executor\Write\WriteInvalidAttributeException;
use Ggs\JsonApiEngine\Executor\Write\WriterBuilder;
use Ggs\JsonApiEngine\Parser\Write\PersistentIdentity;
use Ggs\JsonApiEngine\Parser\Write\WriteDocumentParser;
use Ggs\JsonApiEngine\Plan\Write\WritePlanner;
use Ggs\JsonApiEngine\Resource\DeserializeInterface;
use Ggs\JsonApiEngine\Resource\InvalidAttributeException;
use Ggs\JsonApiEngine\Resource\SerializeInterface;
use Ggs\JsonApiEngine\Test\Executor\Select\Fixture\VoucherResource;
use Ggs\JsonApiEngine\Test\Executor\Write\Fixture\BrokenDeserializeResource;
use Ggs\JsonApiEngine\Test\Executor\Write\Fixture\PricedCertificateResource;
use LogicException;
use PDO;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../example/db/connection.php';

/**
 * Десериализация — строка «колонка модели → значение» из данных операции: по описанию ресурса
 * (`ResourceDeserializer`) и методом `deserialize()` ресурса (`ManualResourceDeserializer`, интерфейс
 * `DeserializeInterface`); выбор между ними в `WriterBuilder`; правила `Resource::validate()` для составного атрибута.
 */
final class ResourceDeserializerTest extends TestCase
{
    public function testRowIsBuiltFromDescription(): void
    {
        $deserializer = new ResourceDeserializer(
            resource: new CertificateResource(),
        );

        // только присланное: атрибут — в свою колонку, связь по внешнему ключу — id цели, null — NULL
        self::assertSame(
            ['code' => 'X-1', 'provider_id' => 3],
            $deserializer->getRow(attributes: ['code' => 'X-1'], references: ['provider' => new PersistentIdentity(id: 3)], is_insert: false, id: 1, link: []),
        );
        self::assertSame(
            ['provider_id' => null],
            $deserializer->getRow(attributes: [], references: ['provider' => null], is_insert: false, id: 1, link: []),
        );
    }

    public function testCompositeAttributeIsNotWrittenFromDescription(): void
    {
        // у VoucherResource составной validity и нет DeserializeInterface: разложить значение по колонкам некому
        $deserializer = new ResourceDeserializer(
            resource: new VoucherResource(),
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('composite attribute validity can not be written without ' . DeserializeInterface::class);
        $deserializer->getRow(attributes: ['validity' => ['from' => '2026-01-01', 'to' => '2026-12-31']], references: [], is_insert: false, id: 1, link: []);
    }

    /**
     * Ресурс, который строит строку сам, общий класс не принимает: разложить его по описанию значило бы молча
     * записать не то.
     */
    public function testGenericDeserializerRejectsResourceWithInterface(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('implements ' . DeserializeInterface::class . ': use ' . ManualResourceDeserializer::class);
        new ResourceDeserializer(
            resource: new PricedCertificateResource(),
        );
    }

    public function testResourceRejectsValueWithInvalidAttributeException(): void
    {
        $deserializer = new ManualResourceDeserializer(
            resource: new PricedCertificateResource(),
        );

        try {
            $deserializer->getRow(attributes: ['price' => 'abc'], references: [], is_insert: false, id: 1, link: []);
            self::fail('InvalidAttributeException expected');
        } catch (InvalidAttributeException $exception) {
            self::assertSame('price', $exception->attribute);
            self::assertSame('price must be an object with amount and currency', $exception->getMessage());
        }
    }

    /**
     * Плохое значение в документе: ресурс называет атрибут, развилка места добавляет тип и id ресурса — клиенту 400,
     * а не 500.
     */
    public function testInvalidValueBecomesClientError(): void
    {
        $pdo = getPDO();
        $root = new WriteDocumentParser()->update(
            document: ['data' => [
                'type' => PricedCertificateResource::TYPE,
                'id' => '1',
                'attributes' => ['price' => 'abc'],
            ]],
            resource: PricedCertificateResource::class,
            id: '1',
        );
        $plan = new WritePlanner()->plan(resource: PricedCertificateResource::class, roots: [$root]);
        $writer = new WriterBuilder(
            pdo: $pdo,
            lock: false,
            container: new ArrayContainer(
                services: [new PricedCertificateResource()],
            ),
        )->getRoot(plan: $plan, data: [$root]);

        try {
            $writer->execute();
            self::fail('WriteInvalidAttributeException expected');
        } catch (WriteInvalidAttributeException $exception) {
            self::assertSame(
                [
                    'status' => '400',
                    'title' => 'Invalid attribute',
                    'detail' => 'price must be an object with amount and currency',
                    'meta' => ['type' => 'priced-certificate', 'id' => '1', 'attribute' => 'price'],
                ],
                $exception->toError(),
            );
            self::assertInstanceOf(InvalidAttributeException::class, $exception->getPrevious());
        }
    }

    public function testResourceDeserializeMethodBuildsTheRow(): void
    {
        $deserializer = new ManualResourceDeserializer(
            resource: new PricedCertificateResource(),
        );

        self::assertSame(
            ['code' => 'P-1', 'nominal' => '15.50', 'currency' => 'EUR'],
            $deserializer->getRow(
                attributes: ['code' => 'P-1', 'price' => ['amount' => '15.50', 'currency' => 'EUR']],
                references: [],
                is_insert: false,
                id: 1,
                link: [],
            ),
        );
        // не прислано — в строке нет
        self::assertSame([], $deserializer->getRow(attributes: [], references: [], is_insert: false, id: 1, link: []));
        // контекст операции доходит до метода: значение по умолчанию — только при вставке
        self::assertSame(
            ['currency' => 'RUB', 'code' => 'P-2'],
            $deserializer->getRow(attributes: ['code' => 'P-2'], references: [], is_insert: true, id: null, link: []),
        );
    }

    public function testManualDeserializerNeedsDeserializeInterface(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('does not implement ' . DeserializeInterface::class);
        new ManualResourceDeserializer(
            resource: new CertificateResource(),
        );
    }

    public function testColumnOutsideOfModelFails(): void
    {
        $deserializer = new ManualResourceDeserializer(
            resource: new BrokenDeserializeResource(),
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('deserialize() returned no_such_column');
        $deserializer->getRow(attributes: ['code' => 'X'], references: [], is_insert: false, id: 1, link: []);
    }

    /**
     * `PATCH` ресурса с `DeserializeInterface` через весь конвейер: сборщик даёт писателю `ManualResourceDeserializer`,
     * составной атрибут записывается в обе колонки.
     */
    public function testBuilderPicksManualDeserializerAndWritesCompositeAttribute(): void
    {
        $pdo = getPDO();
        $root = new WriteDocumentParser()->update(
            document: ['data' => [
                'type' => PricedCertificateResource::TYPE,
                'id' => '1',
                'attributes' => ['price' => ['amount' => '777.00', 'currency' => 'EUR']],
            ]],
            resource: PricedCertificateResource::class,
            id: '1',
        );
        $plan = new WritePlanner()->plan(resource: PricedCertificateResource::class, roots: [$root]);
        $builder = new WriterBuilder(
            pdo: $pdo,
            lock: false,
            container: new ArrayContainer(
                services: [new PricedCertificateResource()],
            ),
        );

        self::assertSame(
            ManualResourceDeserializer::class,
            $builder->build(plan: $plan->input)->explain()['update']['deserializer'],
        );

        // Исполнители пишут каждый запрос в error_log(): PHPUnit перехватывает его и не печатает
        $this->expectErrorLog();
        $pdo->beginTransaction();
        try {
            $builder->getRoot(plan: $plan, data: [$root])->execute();
            $written = $pdo->query('select nominal, currency from certificates where id = 1')->fetch(PDO::FETCH_ASSOC);
        } finally {
            $pdo->rollBack();
        }
        self::assertEquals(['nominal' => 777, 'currency' => 'EUR'], $written);
    }

    public function testValidateRulesOfCompositeAttribute(): void
    {
        // оба интерфейса реализованы — составной атрибут можно и читать, и писать
        PricedCertificateResource::validate();

        try {
            VoucherResource::validate();
            self::fail('LogicException expected');
        } catch (LogicException $exception) {
            // без SerializeInterface составной атрибут не прочитать, без DeserializeInterface и без mode READ — не записать
            self::assertStringContainsString('attribute validity: composite attribute requires ' . SerializeInterface::class, $exception->getMessage());
            self::assertStringContainsString("attribute validity: composite attribute can not be written, declare 'mode' => self::READ", $exception->getMessage());
        }
    }

    public function testMethodWithoutInterfaceIsAnError(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('method serialize() is never called: implement ' . SerializeInterface::class);
        Fixture\SerializeWithoutInterfaceResource::validate();
    }
}
