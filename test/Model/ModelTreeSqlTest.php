<?php

declare(strict_types=1);

namespace Ggs\JsonApiEngine\Test\Model;

use Ggs\JsonApiEngine\Model\ModelTree;
use Ggs\JsonApiEngine\Test\Model\Fixture\ActiveCertificateModel;
use Ggs\JsonApiEngine\Test\Model\Fixture\CertificateModel;
use Ggs\JsonApiEngine\Test\Model\Fixture\KnownKeyCertificateModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use sad_spirit\pg_builder\Lexer;
use sad_spirit\pg_builder\nodes\range\JoinExpression;
use sad_spirit\pg_builder\nodes\range\RelationReference;
use sad_spirit\pg_builder\Parser;
use sad_spirit\pg_builder\SqlBuilderWalker;

/**
 * SQL модели узлами pg_builder: `ModelTree::from()` (только нужные таблицы, скобки, INNER) и `expression()`.
 * Ожидаемый SQL разбирается тем же pg_builder и выводится тем же walker'ом, поэтому сравнение не зависит
 * от регистра и форматирования.
 */
final class ModelTreeSqlTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string, list<string>, string, array<string, string>}>
     */
    public static function models(): iterable
    {
        yield 'main table only' => [
            CertificateModel::class,
            ['id', 'status'],
            'certificates AS t0',
            ['id' => 't0.id', 'status' => 't0.status'],
        ];

        yield 'one relation' => [
            CertificateModel::class,
            ['id', 'csr'],
            'certificates AS t0 LEFT JOIN certificates_csr AS t0_1 ON t0_1.certificate_id = t0.id',
            ['id' => 't0.id', 'csr' => 't0_1.csr'],
        ];

        yield 'nested relation pulls its parent' => [
            CertificateModel::class,
            ['id', 'key_algorithm'],
            'certificates AS t0'
            . ' LEFT JOIN certificate_keys AS t0_2 ON t0_2.certificate_id = t0.id'
            . ' LEFT JOIN key_algorithms AS t0_3 ON t0_3.id = t0_2.algorithm_id',
            ['id' => 't0.id', 'key_algorithm' => 't0_3.name'],
        ];

        yield 'parent without nested relation' => [
            CertificateModel::class,
            ['id', 'key_size'],
            'certificates AS t0 LEFT JOIN certificate_keys AS t0_2 ON t0_2.certificate_id = t0.id',
            ['id' => 't0.id', 'key_size' => 't0_2.size'],
        ];

        yield 'inner is always joined' => [
            ActiveCertificateModel::class,
            ['id'],
            'certificates AS t0 JOIN orders AS t0_1 ON t0_1.id = t0.order_id',
            ['id' => 't0.id'],
        ];

        yield 'right subtree without needed columns is not joined' => [
            KnownKeyCertificateModel::class,
            ['id'],
            'certificates AS t0',
            ['id' => 't0.id'],
        ];

        yield 'right subtree in parentheses' => [
            KnownKeyCertificateModel::class,
            ['id', 'key_algorithm'],
            'certificates AS t0 LEFT JOIN (certificate_keys AS t0_1'
            . ' JOIN key_algorithms AS t0_2 ON t0_2.id = t0_1.algorithm_id) ON t0_1.certificate_id = t0.id',
            ['id' => 't0.id', 'key_algorithm' => 't0_2.name'],
        ];

        yield 'inner in the right subtree is kept with it' => [
            KnownKeyCertificateModel::class,
            ['id', 'key_size'],
            'certificates AS t0 LEFT JOIN (certificate_keys AS t0_1'
            . ' JOIN key_algorithms AS t0_2 ON t0_2.id = t0_1.algorithm_id) ON t0_1.certificate_id = t0.id',
            ['id' => 't0.id', 'key_size' => 't0_1.size'],
        ];

        yield 'left under inner' => [
            ActiveCertificateModel::class,
            ['id', 'customer_name'],
            'certificates AS t0'
            . ' JOIN orders AS t0_1 ON t0_1.id = t0.order_id'
            . ' LEFT JOIN customers AS t0_2 ON t0_2.id = t0_1.customer_id',
            ['id' => 't0.id', 'customer_name' => 't0_2.name'],
        ];
    }

    /**
     * @param class-string          $class
     * @param list<string>          $columns
     * @param array<string, string> $expressions
     */
    #[DataProvider('models')]
    public function testFromAndExpression(string $class, array $columns, string $from, array $expressions): void
    {
        $tree = ModelTree::of($class);
        $parser = new Parser(new Lexer());
        $walker = new SqlBuilderWalker();

        self::assertSame(
            $parser->parseFromElement($from)->dispatch($walker),
            $tree->from('t0', array_fill_keys($columns, true))->dispatch($walker),
        );
        foreach ($expressions as $column => $expected) {
            self::assertSame(
                $parser->parseExpression($expected)->dispatch($walker),
                $tree->expression($column, 't0')->dispatch($walker),
            );
        }
    }

    public function testNodeTypes(): void
    {
        $tree = ModelTree::of(CertificateModel::class);

        self::assertInstanceOf(RelationReference::class, $tree->from('t0', ['id' => true]));
        self::assertInstanceOf(JoinExpression::class, $tree->from('t0', ['csr' => true]));
    }

    public function testNewNodesOnEveryCall(): void
    {
        $tree = ModelTree::of(CertificateModel::class);

        self::assertNotSame($tree->from('t0', ['csr' => true]), $tree->from('t0', ['csr' => true]));
        self::assertNotSame($tree->expression('csr', 't0'), $tree->expression('csr', 't0'));
    }

    public function testUnknownColumn(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('column nope is not a model column');
        ModelTree::of(CertificateModel::class)->expression('nope', 't0');
    }
}
