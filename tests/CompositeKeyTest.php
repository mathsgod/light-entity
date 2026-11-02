<?php

declare(strict_types=1);

namespace PhpEntity\Tests;

use PDO;
use PhpEntity\DbContext;
use PhpEntity\DbSet;
use PhpEntity\Entity;
use PhpEntity\EntityMapping;
use PhpEntity\Migrations\MigrationBuilder;
use PhpEntity\Migrations\MigrationSqlGenerator;
use PhpEntity\Migrations\TableBuilder;
use PhpEntity\Schema\Column;
use PhpEntity\Sql\MySqlDialect;
use PhpEntity\Sql\SqliteDialect;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompositeItem extends Entity {}

class CompositeKeyTest extends TestCase
{
    protected PDO $pdo;
    protected DbContext $db;
    protected DbSet $items;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec("CREATE TABLE items (
            order_id VARCHAR(100) NOT NULL, product_id VARCHAR(100) NOT NULL,
            quantity INT NOT NULL DEFAULT 1,
            doubled INT GENERATED ALWAYS AS (quantity * 2) STORED, label VARCHAR(100) NOT NULL UNIQUE,
            PRIMARY KEY (order_id, product_id)
        )");
        $this->openContext();
    }

    protected function openContext(): void
    {
        $this->db = new DbContext($this->pdo);
        $this->items = $this->db->set(CompositeItem::class, 'items');
    }

    protected function item(string $order, string $product, string $label): CompositeItem
    {
        $item = new CompositeItem();
        $item->order_id = $order;
        $item->product_id = $product;
        $item->label = $label;
        return $item;
    }

    public function testInsertReloadUpdateAndDeleteUseEveryKeyColumn(): void
    {
        $a = $this->item('100', '20', 'a');
        $b = $this->item('100', '21', 'b');
        $c = $this->item('101', '20', 'c');
        $this->items->addRange([$a, $b, $c]);
        self::assertSame(0, $this->items->count());
        self::assertSame(3, $this->db->saveChanges());
        self::assertSame(1, $a->quantity); // Database default was reloaded.
        self::assertSame(2, $a->doubled);
        self::assertSame(0, $this->db->saveChanges());
        $this->openContext();
        $a = $this->items->find(['product_id' => '20', 'order_id' => '100']);
        self::assertNotNull($a);
        self::assertSame($a, $this->items->find(['order_id' => '100', 'product_id' => '20']));
        $a->quantity = 7;
        self::assertSame(1, $this->db->saveChanges());
        self::assertSame(14, $a->doubled);
        self::assertSame([7, 1, 1], array_map('intval', $this->pdo->query('SELECT quantity FROM items ORDER BY label')->fetchAll(PDO::FETCH_COLUMN)));
        $this->items->remove($a);
        self::assertSame(1, $this->db->saveChanges());
        self::assertNull($this->items->find(['order_id' => '100', 'product_id' => '20']));
        self::assertSame(2, $this->items->count());
        self::assertSame(0, $this->db->saveChanges());
    }

    public function testIdentityTrackingHasNoDelimiterCollisionsAndReusesHydratedObjects(): void
    {
        $a = $this->item('a|b', 'c', 'first');
        $b = $this->item('a', 'b|c', 'second');
        $this->items->addRange([$a, $b]);
        self::assertSame($a, $this->items->find(['order_id' => 'a|b', 'product_id' => 'c']));
        self::assertSame($b, $this->items->find(['order_id' => 'a', 'product_id' => 'b|c']));
        self::assertSame(2, $this->db->saveChanges());
        $this->openContext();
        foreach ($this->items as $item) {
            self::assertSame($item, $this->items->find(['order_id' => $item->order_id, 'product_id' => $item->product_id]));
        }
    }

    public function testDuplicateTrackedTupleIsRejected(): void
    {
        $this->items->add($this->item('1', '2', 'first'));
        $this->expectException(\LogicException::class);
        $this->items->add($this->item('1', '2', 'second'));
    }

    public function testRemovingDetachedEntityAndCancellingPendingInsert(): void
    {
        $a = $this->item('1', '2', 'a');
        $b = $this->item('1', '3', 'b');
        $this->items->addRange([$a, $b]);
        $this->items->remove($b);
        self::assertSame(1, $this->db->saveChanges());
        $this->openContext();
        $this->items->remove($this->item('1', '2', 'unused'));
        self::assertSame(1, $this->db->saveChanges());
        self::assertSame(0, $this->items->count());
    }

    public static function keyColumns(): iterable
    {
        yield ['order_id'];
        yield ['product_id'];
    }

    #[DataProvider('keyColumns')]
    public function testChangingAnyKeyComponentIsRejectedAndCanBeRetried(string $column): void
    {
        $a = $this->item('1', '2', 'a');
        $this->items->add($a);
        $this->db->saveChanges();
        $original = $a->$column;
        $a->$column = 'changed';
        try {
            $this->db->saveChanges();
            self::fail('Expected primary key mutation rejection.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('Changing a tracked primary key', $error->getMessage());
        }
        $a->$column = $original;
        $a->quantity = 9;
        self::assertSame(1, $this->db->saveChanges());
    }

    #[DataProvider('keyColumns')]
    public function testMissingKeyComponentIsRejectedOnAddAndRemove(string $column): void
    {
        $a = $this->item('1', '2', 'a');
        $a->$column = null;
        try {
            $this->items->add($a);
            self::fail('Expected missing insert key rejection.');
        } catch (\LogicException) {
            self::assertSame(0, $this->items->count());
        }
        $this->expectException(\LogicException::class);
        $this->items->remove($a);
    }

    public static function invalidFindKeys(): iterable
    {
        yield ['1'];
        yield [[]];
        yield [['order_id' => '1']];
        yield [['order_id' => '1', 'wrong' => '2']];
        yield [['order_id' => '1', 'product_id' => '2', 'extra' => '3']];
        yield [['1', '2']];
    }

    #[DataProvider('invalidFindKeys')]
    public function testFindRejectsIncompleteOrAmbiguousKeys(int|string|array $key): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->items->find($key);
    }

    public function testFailedSaveRollsBackUpdatesAndInsertsAndRetainsChangesForRetry(): void
    {
        $a = $this->item('1', '2', 'existing');
        $this->items->add($a);
        $this->db->saveChanges();
        $a->quantity = 8;
        $b = $this->item('1', '3', 'duplicate');
        $c = $this->item('2', '3', 'duplicate');
        $this->items->addRange([$b, $c]);
        try {
            $this->db->saveChanges();
            self::fail('Expected unique constraint failure.');
        } catch (\PDOException) {
            self::assertFalse($this->pdo->inTransaction());
        }
        self::assertSame(1, $this->items->count());
        self::assertSame(1, (int) $this->pdo->query('SELECT quantity FROM items')->fetchColumn());
        self::assertFalse($b->hasValue('quantity'));
        $c->label = 'different';
        self::assertSame(3, $this->db->saveChanges());
        self::assertSame(8, $a->quantity);
        self::assertSame(1, $b->quantity);
        self::assertSame(0, $this->db->saveChanges());
    }

    public function testExplicitMappingSupportsCompositeKeys(): void
    {
        $mapping = new EntityMapping(CompositeItem::class, 'items', ['order_id', 'product_id', 'quantity', 'label'], ['order_id', 'product_id'], false);
        $db = new DbContext($this->pdo, mappings: [$mapping]);
        $item = $this->item('1', '2', 'explicit');
        $item->quantity = 4;
        $db->set(CompositeItem::class)->add($item);
        self::assertSame(1, $db->saveChanges());
        self::assertSame($item, $db->set(CompositeItem::class)->find(['order_id' => '1', 'product_id' => '2']));
    }

    public function testGeneratedCompositeMappingIdentifiesTheGeneratedColumn(): void
    {
        $mapping = EntityMapping::fromSchema(CompositeItem::class, 'items', [
            new Column('order_id', 'int', false, true),
            new Column('product_id', 'int', false, true, true),
        ]);
        self::assertSame(['order_id', 'product_id'], $mapping->keys);
        self::assertSame('product_id', $mapping->generatedKeyProperty);
        $item = $this->item('1', '2', 'a');
        self::assertSame(['order_id' => '1'], $mapping->insertValues($item));
    }

    public function testCompositeMigrationCreatesAUsableTable(): void
    {
        $migration = new MigrationBuilder();
        $migration->createTable('links', function (TableBuilder $t) {
            $t->integer('a')->primaryKey();
            $t->integer('b')->primaryKey();
        });
        $sql = (new MigrationSqlGenerator(new SqliteDialect()))->generate($migration)[0];
        self::assertStringContainsString('PRIMARY KEY ("a", "b")', $sql);
        self::assertStringContainsString('PRIMARY KEY (`a`, `b`)', (new MigrationSqlGenerator(new MySqlDialect()))->generate($migration)[0]);
        // This test also runs against MySQL in the integration suite.
        $dialect = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? new MySqlDialect() : new SqliteDialect();
        $this->pdo->exec((new MigrationSqlGenerator($dialect))->generate($migration)[0]);
        $this->pdo->exec('INSERT INTO links (a,b) VALUES (1,2), (1,3)');
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM links')->fetchColumn());
        $this->expectException(\PDOException::class);
        $this->pdo->exec('INSERT INTO links (a,b) VALUES (1,2)');
    }

    public function testKeylessTableIsStillRejected(): void
    {
        $this->pdo->exec('CREATE TABLE keyless (value INT)');
        $this->expectException(\LogicException::class);
        (new DbContext($this->pdo))->set(CompositeItem::class, 'keyless');
    }

    public static function invalidMappings(): iterable
    {
        yield 'empty' => [[]];
        yield 'duplicate' => [['order_id', 'order_id']];
        yield 'unmapped' => [['order_id', 'missing']];
        yield 'non-string' => [['order_id', []]];
    }

    #[DataProvider('invalidMappings')]
    public function testInvalidKeyMappingsAreRejected(array $keys): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EntityMapping(CompositeItem::class, 'items', ['order_id', 'product_id'], $keys, false);
    }

    public function testAmbiguousGeneratedCompositeMappingIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EntityMapping(CompositeItem::class, 'items', ['order_id', 'product_id'], ['order_id', 'product_id']);
    }

    public function testSqliteRejectsGeneratedCompositeMigration(): void
    {
        $migration = new MigrationBuilder();
        $migration->createTable('invalid', function (TableBuilder $t) {
            $t->integer('a')->primaryKey()->autoIncrement();
            $t->integer('b')->primaryKey();
        });
        $this->expectException(\LogicException::class);
        (new MigrationSqlGenerator(new SqliteDialect()))->generate($migration);
    }
}
