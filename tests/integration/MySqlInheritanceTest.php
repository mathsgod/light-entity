<?php

declare(strict_types=1);

namespace Light\Entity\Tests\Integration;

use Light\Entity\{DbContext, Entity, Table};
use Light\Entity\Tests\Support\MySqlDatabase;
use Light\Linq\Expr;
use PDO;
use PHPUnit\Framework\TestCase;

#[Table('animals')]
class MySqlTphAnimal extends Entity {}
final class MySqlTphCat extends MySqlTphAnimal {}
final class MySqlTphDog extends MySqlTphAnimal {}
#[Table('owners')]
final class MySqlTphOwner extends Entity {}

final class MySqlInheritanceTest extends TestCase
{
    use MySqlDatabase;

    private function fixture(): array
    {
        $pdo = $this->connection();
        $pdo->exec('CREATE TABLE owners (id INT PRIMARY KEY)');
        $pdo->exec('CREATE TABLE animals (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(60), Discriminator VARCHAR(100) NOT NULL, owner_id INT, FOREIGN KEY(owner_id) REFERENCES owners(id))');
        $pdo->exec('INSERT INTO owners VALUES (1)');
        $pdo->exec("INSERT INTO animals (id, name, Discriminator, owner_id) VALUES (1, 'Cat', 'MySqlTphCat', 1), (2, 'Dog', 'MySqlTphDog', 1)");
        return [$pdo, new DbContext($pdo)];
    }

    public function testHierarchyMaterializationIdentityAndPersistence(): void
    {
        [$pdo, $db] = $this->fixture();
        $animals = $db->set(MySqlTphAnimal::class)->orderBy(Expr::field('id'))->toArray();
        self::assertSame([MySqlTphCat::class, MySqlTphDog::class], array_map(get_class(...), $animals));
        self::assertSame($animals[0], $db->set(MySqlTphCat::class)->find(1));
        self::assertNull($db->set(MySqlTphCat::class)->find(2));
        $cat = new MySqlTphCat();
        $cat->name = 'New cat';
        $db->set(MySqlTphAnimal::class)->add($cat);
        self::assertSame(1, $db->saveChanges());
        self::assertSame('MySqlTphCat', $cat->Discriminator);
        $cat->name = 'Updated';
        self::assertSame(1, $db->saveChanges());
        self::assertSame('Updated', $pdo->query('SELECT name FROM animals WHERE id = '.$cat->id)->fetchColumn());
        $db->set(MySqlTphAnimal::class)->remove($cat);
        self::assertSame(1, $db->saveChanges());
    }

    public function testNoTrackingIncludesAndNativeFetchOptimizationPreserveTypes(): void
    {
        [, $db] = $this->fixture();
        $entities = $db->set(MySqlTphAnimal::class)->asNoTracking()->orderBy(Expr::field('id'))->toArray();
        self::assertSame([MySqlTphCat::class, MySqlTphDog::class], array_map(get_class(...), $entities));
        $db->relationship(MySqlTphOwner::class, 'Cats', 'animals', ['owner_id'], MySqlTphCat::class);
        $owner = $db->set(MySqlTphOwner::class)->asNoTracking()->include('Cats')->first();
        self::assertSame([MySqlTphCat::class], array_map(get_class(...), $owner->getCats()->toArray()));
        self::assertSame([], $db->changeTracker->entries());
    }

    public function testBulkWritesAndRawSqlKeepStructuralDiscriminator(): void
    {
        [, $db] = $this->fixture();
        $cats = $db->set(MySqlTphCat::class);
        self::assertSame(1, $cats->ignoreQueryFilters()->executeUpdate(['name' => 'Feline']));
        self::assertSame('Dog', $db->set(MySqlTphDog::class)->first()->name);
        self::assertSame([MySqlTphCat::class], array_map(get_class(...), $cats->fromSqlRaw('SELECT * FROM animals')->ignoreQueryFilters()->toArray()));
        self::assertSame(1, $cats->ignoreQueryFilters()->executeDelete());
        self::assertSame(1, $db->set(MySqlTphAnimal::class)->count());
    }
}
