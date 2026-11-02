<?php

declare(strict_types=1);

namespace PhpEntity\Tests\Integration;

use PDO;
use PhpEntity\{DbContext, Entity, EntityState, Table};
use PhpEntity\Tests\Support\MySqlDatabase;
use PhpLinq\Expr;
use PHPUnit\Framework\TestCase;

#[Table('fetch_records')]
final class MySqlFetchRecord extends Entity {}

#[Table('constructor_records')]
final class MySqlFetchConstructorRecord extends Entity
{
    public function __construct(string $required)
    {
        throw new \RuntimeException('Hydration must skip constructors');
    }
}

#[Table('constructor_records')]
final class MySqlFetchTypedRecord
{
    public int $id;
    public ?bool $active;
}

#[Table('constructor_records')]
final class MySqlFetchCustomSetterRecord extends Entity
{
    public static int $calls = 0;

    public function __set(string $name, mixed $value): void
    {
        self::$calls++;
        parent::__set($name, $value);
    }
}

final class MySqlFetchClassTest extends TestCase
{
    use MySqlDatabase;

    public function testNativeTypesBooleanMetadataAndDetachedEntities(): void
    {
        $pdo = $this->connection();
        $pdo->exec('CREATE TABLE fetch_records (id INT PRIMARY KEY, active TINYINT(1), optional BOOLEAN, unsigned_flag TINYINT(1) UNSIGNED, number TINYINT, amount DECIMAL(20,6), huge BIGINT UNSIGNED, ratio DOUBLE, body JSON, created_at DATETIME)');
        $pdo->exec("INSERT INTO fetch_records VALUES (1,0,NULL,1,2,12345678901234.123456,18446744073709551615,1.25,'{\"ok\":true}','2026-01-01 12:00:00'),(2,2,1,0,3,0.000001,1,2.5,NULL,NULL)");
        $db = new DbContext($pdo);
        $set = $db->set(MySqlFetchRecord::class);
        $mapping = $db->model->getEntityType(MySqlFetchRecord::class);
        self::assertTrue($mapping->pdoFetchClassCompatible);
        self::assertSame(['active', 'optional'], $mapping->booleanProperties);
        $query = $set->orderBy(Expr::field('id'))->asQueryable();
        $native = $query->provider()->executeNoTrackingEntities($query->expression(), $mapping);
        self::assertCount(2, $native);
        self::assertInstanceOf(MySqlFetchRecord::class, $native[0]);
        self::assertFalse($native[0]->active);
        self::assertNull($native[0]->optional);
        self::assertSame(1, $native[0]->unsigned_flag); // MySQL 8 removes display width from unsigned TINYINT metadata.
        self::assertTrue($native[1]->active); // The documented TINYINT(1) convention includes nonzero values.
        self::assertSame(2, $native[0]->number);
        self::assertSame(1, $native[0]->id);
        self::assertSame('12345678901234.123456', $native[0]->amount);
        self::assertSame('18446744073709551615', $native[0]->huge);
        self::assertSame(1.25, $native[0]->ratio);
        self::assertSame('{"ok": true}', $native[0]->body);
        self::assertSame('2026-01-01 12:00:00', $native[0]->created_at);
        $read = $set->asNoTracking()->orderBy(Expr::field('id'))->toArray();
        self::assertSame(EntityState::Detached, $db->entry($read[0])->state);
        self::assertCount(0, $db->changeTracker->entries());
        self::assertNotSame($read[0], $set->asNoTracking()->find(1));
        self::assertTrue($set->find(2)->active); // Tracking and first() use the same bool convention.
        self::assertTrue($set->asNoTracking()->where(['id' => 2])->first()->active);
        self::assertCount(1, $db->changeTracker->entries());
        self::assertSame([], $set->asNoTracking()->where(['id' => 99])->toArray());
    }

    public function testConstructorsTypedPropertiesAndStringifiedFetchesRetainFallback(): void
    {
        $pdo = $this->connection();
        $pdo->exec('CREATE TABLE constructor_records (id INT PRIMARY KEY, active BOOLEAN)');
        $pdo->exec('INSERT INTO constructor_records VALUES(1,1),(2,NULL)');
        $db = new DbContext($pdo);
        foreach ([MySqlFetchConstructorRecord::class, MySqlFetchTypedRecord::class] as $class) {
            self::assertFalse($db->model->getEntityType($class)->pdoFetchClassCompatible);
            $rows = $db->set($class)->asNoTracking()->orderBy(Expr::field('id'))->toArray();
            self::assertSame(1, $rows[0]->id);
            self::assertTrue($rows[0]->active);
            self::assertNull($rows[1]->active);
        }
        $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
        $set = $db->set(MySqlFetchRecord::class, 'constructor_records');
        $query = $set->asQueryable();
        self::assertNull($query->provider()->executeNoTrackingEntities($query->expression(), $db->model->getEntityType(MySqlFetchRecord::class)));
        $row = $set->asNoTracking()->find(1);
        self::assertSame(1, $row->id);
        self::assertTrue($row->active);
    }

    public function testRawSqlKeepsSchemaConversionAndMissingColumnValidation(): void
    {
        $pdo = $this->connection();
        $pdo->exec('CREATE TABLE fetch_records (id INT PRIMARY KEY, active BOOLEAN)');
        $pdo->exec('INSERT INTO fetch_records VALUES(1,1)');
        $db = new DbContext($pdo);
        $set = $db->set(MySqlFetchRecord::class)->asNoTracking();
        $rows = $set->fromSqlRaw('SELECT CAST(id AS CHAR) AS id, active, 99 AS extra FROM fetch_records')->toArray();
        self::assertSame(1, $rows[0]->id);
        self::assertTrue($rows[0]->active);
        self::assertFalse($rows[0]->hasValue('extra'));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('missing mapped column: active');
        $set->fromSqlRaw('SELECT id FROM fetch_records')->toArray();
    }

    public function testBitAndYearRetainExistingStringConversions(): void
    {
        $pdo = $this->connection();
        $pdo->exec('CREATE TABLE fetch_records (id INT PRIMARY KEY, bits BIT(4), manufactured YEAR)');
        $pdo->exec('INSERT INTO fetch_records VALUES(1,3,2026)');
        $raw = $pdo->query('SELECT * FROM fetch_records')->fetch(PDO::FETCH_ASSOC);
        $db = new DbContext($pdo);
        $mapping = $db->model->getEntityType(MySqlFetchRecord::class);
        self::assertFalse($mapping->pdoFetchClassCompatible);
        $rows = $db->set(MySqlFetchRecord::class)->asNoTracking()->toArray();
        self::assertSame((string)$raw['bits'], $rows[0]->bits);
        self::assertSame('2026', $rows[0]->manufactured);
    }

    public function testCustomSetterKeepsFallbackAndIsNotBypassed(): void
    {
        $pdo = $this->connection();
        $pdo->exec('CREATE TABLE constructor_records (id INT PRIMARY KEY, active BOOLEAN)');
        $pdo->exec('INSERT INTO constructor_records VALUES(1,1),(2,NULL)');
        MySqlFetchCustomSetterRecord::$calls = 0;
        $db = new DbContext($pdo);
        $set = $db->set(MySqlFetchCustomSetterRecord::class);
        self::assertFalse($db->model->getEntityType(MySqlFetchCustomSetterRecord::class)->pdoFetchClassCompatible);
        $rows = $set->asNoTracking()->orderBy(Expr::field('id'))->toArray();
        self::assertSame(4, MySqlFetchCustomSetterRecord::$calls);
        self::assertSame(1, $rows[0]->id);
        self::assertTrue($rows[0]->active);
        self::assertNull($rows[1]->active);
    }
}
