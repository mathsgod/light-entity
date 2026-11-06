<?php

declare(strict_types=1);

namespace Light\Entity\Tests;

use Light\Entity\{DbContext, Entity, Table};
use PDO;
use Light\Entity\Sql\{SqlDialect, SqlQueryCompiler, MySqlDialect, PostgreSqlDialect, SqlServerDialect, SqliteDialect};
use Light\Linq\{Queryable, InMemoryQueryProvider};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[Table('string_customers')]
final class StringCustomer extends Entity {}

final class StringMatchTest extends TestCase
{
    public function testEntityClosureExecutesLiteralAndDynamicMatchesInSqlite(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) { self::markTestSkipped('pdo_sqlite required.'); }
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE string_customers (id INTEGER PRIMARY KEY, name TEXT, needle TEXT)');
        $insert = $pdo->prepare('INSERT INTO string_customers VALUES (?, ?, ?)');
        foreach ([[1, 'alpha', 'al'], [2, 'beta', 'et'], [3, 'a%_![z', '%_!['], [4, '', 'x'], [5, null, 'a']] as $row) {
            $insert->execute($row);
        }
        $customers = (new DbContext($pdo))->set(StringCustomer::class);
        self::assertSame([1, 3], $customers->where(fn($c) => str_starts_with($c->name, 'a'))
            ->select(fn($c) => $c->id)->toArray());
        self::assertSame([1, 2], $customers->where(fn($c) => str_ends_with($c->name, 'a'))
            ->select(fn($c) => $c->id)->toArray());
        self::assertSame([3], $customers->where(fn($c) => str_contains($c->name, '%_!['))
            ->select(fn($c) => $c->id)->toArray());
        self::assertSame([1, 2, 3], $customers->where(fn($c) => str_contains($c->name, $c->needle))
            ->select(fn($c) => $c->id)->toArray());
        self::assertSame([2, 4], $customers->where(fn($c) => !str_starts_with($c->name, 'a'))
            ->select(fn($c) => $c->id)->toArray());
    }

    public static function dialects(): iterable
    {
        yield 'mysql' => [new MySqlDialect()];
        yield 'postgresql' => [new PostgreSqlDialect()];
        yield 'sqlserver' => [new SqlServerDialect()];
        yield 'sqlite' => [new SqliteDialect()];
    }

    private function names(): Queryable
    {
        return Queryable::from(new InMemoryQueryProvider([]), 'customers');
    }

    #[DataProvider('dialects')]
    public function testStartsWithEscapesLiteralWildcardsAndParameters(SqlDialect $dialect): void
    {
        $needle = "a%_![b'";
        $query = $this->names()->where(fn($c) => str_starts_with($c->name, $needle));
        $compiled = (new SqlQueryCompiler($dialect))->compile($query->expression());
        self::assertStringContainsString($dialect->quoteIdentifier('name').' LIKE :p0 ESCAPE :p1', $compiled->sql);
        self::assertSame(['p0' => "a!%!_!!![b'%", 'p1' => '!'], $compiled->parameters);
        self::assertStringNotContainsString($needle, $compiled->sql);
    }

    #[DataProvider('dialects')]
    public function testSuffixContainsEmptyAndNegation(SqlDialect $dialect): void
    {
        $compiler = new SqlQueryCompiler($dialect);
        $suffix = $this->names()->where(fn($c) => str_ends_with($c->name, 'a'));
        self::assertSame(['p0' => '%a', 'p1' => '!'], $compiler->compile($suffix->expression())->parameters);
        $contains = $this->names()->where(fn($c) => !str_contains($c->name, 'a'));
        $compiled = $compiler->compile($contains->expression());
        self::assertSame(['p0' => '%a%', 'p1' => '!'], $compiled->parameters);
        self::assertStringContainsString('NOT ', $compiled->sql);
        $empty = $this->names()->where(fn($c) => str_starts_with($c->name, ''));
        self::assertSame(['p0' => '%', 'p1' => '!'], $compiler->compile($empty->expression())->parameters);
    }

    #[DataProvider('dialects')]
    public function testDynamicNeedleUsesEscapingAndDialectConcatenation(SqlDialect $dialect): void
    {
        $query = $this->names()->where(fn($c) => str_contains($c->name, $c->needle));
        $compiled = (new SqlQueryCompiler($dialect))->compile($query->expression());
        self::assertStringContainsString('REPLACE(REPLACE(REPLACE(REPLACE(', $compiled->sql);
        self::assertStringContainsString($dialect->quoteIdentifier('needle'), $compiled->sql);
        self::assertStringContainsString($dialect instanceof MySqlDialect ? 'CONCAT(' : ($dialect instanceof SqlServerDialect ? ' + ' : ' || '), $compiled->sql);
        self::assertSame(['!', '!!', '%', '!%', '_', '!_', '[', '![', '%', '%', '!'], array_values($compiled->parameters));
    }
}
