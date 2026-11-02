<?php

declare(strict_types=1);

namespace PhpEntity\Tests\Support;

use PDO;

/** Isolates each test connection in its own disposable MySQL 8 schema. */
trait MySqlDatabase
{
    /** @var list<array{PDO, string}> */
    private array $databases = [];

    protected function connection(): PDO
    {
        $dsn = getenv('PHP_ENTITY_MYSQL_DSN');
        if (!$dsn) {
            self::fail('Set PHP_ENTITY_MYSQL_DSN or run composer test:mysql.');
        }
        $pdo = new PDO($dsn, getenv('PHP_ENTITY_MYSQL_USER') ?: 'root', getenv('PHP_ENTITY_MYSQL_PASSWORD') ?: 'php_entity_test', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        self::assertSame('mysql', $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        self::assertMatchesRegularExpression('/^8\./', $pdo->query('SELECT VERSION()')->fetchColumn());

        // Each connection owns its schema; never drop or modify an existing database.
        $schema = 'php_entity_test_'.bin2hex(random_bytes(8));
        $pdo->exec('CREATE DATABASE `'.$schema.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');
        $this->databases[] = [$pdo, $schema];
        $pdo->exec('USE `'.$schema.'`');
        return $pdo;
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->databases as [$pdo, $schema]) {
                $pdo->exec('DROP DATABASE `'.$schema.'`');
            }
        } finally {
            $this->databases = [];
            parent::tearDown();
        }
    }
}
