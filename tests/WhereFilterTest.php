<?php

declare(strict_types=1);

namespace Light\Entity\Tests;

use Light\Entity\WhereFilter;
use Light\Entity\Sql\{SqlQueryCompiler, SqliteDialect};
use Light\Linq\{Queryable, InMemoryQueryProvider};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WhereFilterTest extends TestCase
{
    private function compile(array $filter): \Light\Entity\Sql\CompiledQuery
    {
        $query = Queryable::from(new InMemoryQueryProvider([]), 'users')
            ->where(WhereFilter::parse($filter));
        return (new SqlQueryCompiler(new SqliteDialect()))->compile($query->expression());
    }

    public function testMixedConditionsAreGroupedAndParameterized(): void
    {
        $compiled = $this->compile([
            'age' => ['gte' => 18, 'lt' => 65],
            'status' => 'Active',
            '_or' => [['role' => 'Admin'], ['role' => ['eq' => 'Manager']]],
        ]);
        self::assertStringContainsString('("age" >= :p0)', $compiled->sql);
        self::assertStringContainsString('("age" < :p1)', $compiled->sql);
        self::assertStringContainsString('(("role" = :p3) OR ("role" = :p4))', $compiled->sql);
        self::assertSame(['p0'=>18, 'p1'=>65, 'p2'=>'Active', 'p3'=>'Admin', 'p4'=>'Manager'], $compiled->parameters);
    }

    public function testNotNullMembershipAndRepeatedFields(): void
    {
        $compiled = $this->compile([
            '_and' => [['age'=>['gt'=>18]], ['age'=>['lte'=>65]]],
            '_not' => ['status'=>['neq'=>null]],
            'role' => ['in'=>['Admin', 'Manager']],
            'deleted_at'=>null,
        ]);
        self::assertStringContainsString('(NOT ("status" IS NOT NULL))', $compiled->sql);
        self::assertStringContainsString('("deleted_at" IS NULL)', $compiled->sql);
        self::assertStringContainsString(' OR ', $compiled->sql);
        self::assertSame(['p0'=>18, 'p1'=>65, 'p2'=>'Admin', 'p3'=>'Manager'], $compiled->parameters);
    }

    public function testValuesRemainParameters(): void
    {
        $payload = "'; DROP TABLE users; --";
        $compiled = $this->compile(['name'=>$payload]);
        self::assertStringNotContainsString($payload, $compiled->sql);
        self::assertSame(['p0'=>$payload], $compiled->parameters);
    }

    public function testEmptyGroupsHaveExplicitBooleanIdentities(): void
    {
        self::assertSame(['p0'=>1, 'p1'=>1], $this->compile([])->parameters);
        self::assertSame(['p0'=>1, 'p1'=>1], $this->compile(['_and'=>[]])->parameters);
        self::assertSame(['p0'=>1, 'p1'=>0], $this->compile(['_or'=>[]])->parameters);
        self::assertSame(['p0'=>1, 'p1'=>0], $this->compile(['id'=>['in'=>[]]])->parameters);
    }

    public static function invalidFilters(): iterable
    {
        yield [['age'=>['wat'=>18]]];
        yield [['_or'=>['age'=>18]]];
        yield [['_and'=>[18]]];
        yield [['_not'=>false]];
        yield [['age'=>['gt'=>null]]];
        yield [['age'=>[]]];
        yield [['role'=>['in'=>'Admin']]];
        yield [['role'=>['in'=>[new \stdClass()]]]];
        yield [['age'=>['eq'=>['nested'=>1]]]];
        yield [[0=>['age'=>18]]];
        yield [['$or'=>[]]];
    }

    #[DataProvider('invalidFilters')]
    public function testInvalidFiltersAreRejected(array $filter): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WhereFilter::parse($filter);
    }
}
