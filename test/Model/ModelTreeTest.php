<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Model;

use Ggs\JsonApiEngine\Model\ModelTree;
use Ggs\JsonApiEngine\Test\Model\Fixture\CertificateModel;
use Ggs\JsonApiEngine\Test\Model\Fixture\Invalid;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixture/Invalid.php';

/**
 * Разбор и правила модели данных: ошибки с путём в дереве, колонки модели, уникальность.
 */
final class ModelTreeTest extends TestCase
{
    public function testErrorsCarryTreePath(): void
    {
        $errors = implode("\n", ModelTree::build(Invalid\BadModel::class)->errors());

        foreach ([
            'model root.left.left.left: column note must be PRIMARY or unique',
            'model root.left.left.left: columns thing_id and note must have the same type',
            'model root.left.left.left: column name is defined in both',
            'model root.left.left: column missing is missing in the left subtree',
            'model root.left.right: stdClass is not a subclass of',
            'model root: column customer_name must belong to the main table of the right subtree',
            'model root: column id is defined in both',
        ] as $message) {
            self::assertStringContainsString($message, $errors);
        }
    }

    public function testOfThrowsOnInvalidModel(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(Invalid\BadModel::class . ': invalid model:');
        ModelTree::of(Invalid\BadModel::class);
    }

    public function testColumns(): void
    {
        $tree = ModelTree::of(CertificateModel::class);

        self::assertSame([], $tree->errors());
        self::assertContains('csr', $tree->columns());
        self::assertNotContains('certificate_id', $tree->columns(), 'the right relation column equals the left one');
        self::assertSame(0, $tree->columnTable('id'));
        self::assertTrue($tree->isUnique('id'));
        self::assertFalse($tree->isUnique('csr'), 'unique only in the main table');
        self::assertSame(ModelTree::of(CertificateModel::class), $tree, 'parsed once per class');
    }
}
