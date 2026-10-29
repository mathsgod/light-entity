<?php

declare(strict_types=1);

namespace PhpEntity\Tests;

use PDO;
use PhpLinq\Expr;
use PhpLinq\Queryable;
use PhpLinq\Sql\SqliteDialect;
use PhpEntity\SqlQueryProvider;
use PHPUnit\Framework\TestCase;

final class SqlQueryProviderTest extends TestCase
{
    private Queryable $users;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not installed.');
        }

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE users (id INTEGER, name TEXT, active INTEGER, age INTEGER)');
        $pdo->exec("INSERT INTO users VALUES
            (1, 'Ada', 1, 36),
            (2, 'Bob', 0, 22),
            (3, 'Cara', 1, 29),
            (4, 'Dan', 1, 41)");

        $this->users = Queryable::from(
            new SqlQueryProvider($pdo, new SqliteDialect()),
            'users',
        );
    }

    public function testExecutesSequenceAndScalarQueries(): void
    {
        $names = $this->users
            ->where(Expr::eq(Expr::field('active'), 1))
            ->orderByDescending(Expr::field('age'))
            ->select(Expr::field('name'))
            ->take(2);

        self::assertSame(['Dan', 'Ada'], $names->toArray());
        self::assertSame(3, $this->users->where(Expr::eq(Expr::field('active'), 1))->count());
        self::assertTrue($this->users->any(Expr::lt(Expr::field('age'), 25)));
        self::assertSame('Cara', $this->users->select(Expr::field('name'))->first(
            Expr::eq(Expr::field('id'), 3),
        ));
    }

    public function testExecutesMultiFieldProjection(): void
    {
        self::assertSame(
            [
                ['id' => 1, 'name' => 'Ada'],
                ['id' => 2, 'name' => 'Bob'],
            ],
            $this->users
                ->orderBy(Expr::field('id'))
                ->select(Expr::fields('id', 'name'))
                ->take(2)
                ->toArray(),
        );
    }
}
