<?php

declare(strict_types=1);

namespace Light\Entity\Tests;

use Light\Entity\{DbContext, Entity, EntityMapping, EntityState, ModelBuilder, Table};
use Light\Linq\Expr;
use PDO;
use PHPUnit\Framework\TestCase;

#[Table('tph_animals')]
class TphAnimal extends Entity {}
abstract class TphFeline extends TphAnimal {}
class TphCat extends TphFeline {}
final class TphTypedCat extends TphCat { public int $lives; }
final class TphKitten extends TphCat {}
final class TphDog extends TphAnimal {}
#[Table('tph_pets')]
abstract class TphPet extends Entity {}
final class TphBird extends TphPet {}
final class TphOwner extends Entity {}
class ConventionAnimal extends Entity {}
final class ConventionCat extends ConventionAnimal {}
#[Table('wrong_cats')]
final class TphWrongCat extends TphAnimal {}

final class InheritanceTest extends TestCase
{
    private PDO $pdo;
    private DbContext $db;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) self::markTestSkipped('pdo_sqlite required.');
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE tph_owners (id INTEGER PRIMARY KEY, name TEXT)');
        $this->pdo->exec('CREATE TABLE tph_animals (id INTEGER PRIMARY KEY, name TEXT, Discriminator TEXT NOT NULL, owner_id INTEGER REFERENCES tph_owners(id), lives INTEGER, toy TEXT)');
        $this->pdo->exec("INSERT INTO tph_owners VALUES (1, 'Owner'); INSERT INTO tph_animals VALUES
            (1, 'Animal', 'TphAnimal', 1, NULL, NULL),
            (2, 'Cat', 'TphCat', 1, 9, NULL),
            (3, 'Dog', 'TphDog', 1, NULL, 'ball'),
            (4, 'Kitten', 'TphKitten', 1, 9, NULL)");
        $this->db = new DbContext($this->pdo);
    }

    public function testDerivedSetUsesRootTableAndIncludesFurtherDerivedTypes(): void
    {
        $cats = $this->db->set(TphCat::class)->orderBy(fn ($cat) => $cat->id)->toArray();
        self::assertSame([TphCat::class, TphKitten::class], array_map(get_class(...), $cats));
        self::assertSame([2, 4], array_map(fn ($cat) => $cat->id, $cats));
        self::assertSame('tph_animals', $this->db->model->getEntityType(TphCat::class)->table);
        self::assertSame(2, $this->db->set(TphCat::class)->count());
        self::assertFalse($this->db->set(TphCat::class)->any(fn ($cat) => $cat->id == 3));
        self::assertSame(['Cat', 'Kitten'], $this->db->set(TphCat::class)->orderBy(Expr::field('id'))->select(Expr::field('name'))->toArray());
    }

    public function testBaseSetMaterializesRuntimeTypesAndSharesIdentity(): void
    {
        $animals = $this->db->set(TphAnimal::class)->orderBy(Expr::field('id'))->toArray();
        self::assertSame([TphAnimal::class, TphCat::class, TphDog::class, TphKitten::class], array_map(get_class(...), $animals));
        self::assertSame($animals[1], $this->db->set(TphCat::class)->find(2));
        self::assertSame($animals[3], $this->db->set(TphKitten::class)->find(4));
        self::assertSame(TphCat::class, $this->db->entry($animals[1])->metadata->class);
        self::assertCount(4, $this->db->changeTracker->entries());
        self::assertNull($this->db->set(TphCat::class)->find(3));
    }

    public function testDerivedFirstThenBaseAlsoSharesIdentity(): void
    {
        $cat = $this->db->set(TphCat::class)->find(2);
        self::assertSame($cat, $this->db->set(TphAnimal::class)->find(2));
        self::assertCount(1, $this->db->changeTracker->entries());
    }

    public function testNoTrackingAndStreamingUseConcreteTypes(): void
    {
        $set = $this->db->set(TphAnimal::class)->orderBy(Expr::field('id'))->asNoTracking();
        $entities = $set->toArray();
        self::assertInstanceOf(TphCat::class, $entities[1]);
        self::assertInstanceOf(TphDog::class, $entities[2]);
        self::assertSame(EntityState::Detached, $this->db->entry($entities[1])->state);
        self::assertSame([], $this->db->changeTracker->entries());
        self::assertNotSame($entities[1], $set->toArray()[1]);
        self::assertSame([TphAnimal::class, TphCat::class, TphDog::class, TphKitten::class], array_map(get_class(...), iterator_to_array($set)));
    }

    public function testWritesViaBaseSetUseConcreteDiscriminatorAndOneTable(): void
    {
        $cat = new TphCat();
        $cat->name = 'New cat';
        $cat->lives = 8;
        $this->db->set(TphAnimal::class)->add($cat);
        self::assertSame(1, $this->db->saveChanges());
        self::assertSame('TphCat', $cat->Discriminator);
        self::assertSame('TphCat', $this->pdo->query('SELECT Discriminator FROM tph_animals WHERE id = '.$cat->id)->fetchColumn());
        self::assertSame($cat, $this->db->set(TphAnimal::class)->find($cat->id));
        $cat->name = 'Updated';
        self::assertSame(1, $this->db->saveChanges());
        self::assertSame('Updated', $this->pdo->query('SELECT name FROM tph_animals WHERE id = '.$cat->id)->fetchColumn());
        $this->db->set(TphAnimal::class)->remove($cat);
        self::assertSame(1, $this->db->saveChanges());
        self::assertNull($this->db->set(TphCat::class)->find($cat->id));
    }

    public function testBulkWritesCannotAffectSiblingTypes(): void
    {
        self::assertSame(2, $this->db->set(TphCat::class)->executeUpdate(['name' => 'Feline']));
        self::assertSame('Dog', $this->db->set(TphDog::class)->first()->name);
        self::assertSame(2, $this->db->set(TphCat::class)->ignoreQueryFilters()->executeDelete());
        self::assertSame(2, $this->db->set(TphAnimal::class)->count());
    }

    public function testDiscriminatorCannotBeAssignedByBulkUpdate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->db->set(TphCat::class)->executeUpdate(['Discriminator' => 'TphDog']);
    }

    public function testDiscriminatorCannotBeChangedOnTrackedEntity(): void
    {
        $cat = $this->db->set(TphCat::class)->find(2);
        $cat->Discriminator = 'TphDog';
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('discriminator cannot be changed');
        $this->db->saveChanges();
    }

    public function testRawSqlAndIgnoredFiltersRetainTypeRestriction(): void
    {
        self::assertSame([2, 4], array_map(fn ($cat) => $cat->id,
            $this->db->set(TphCat::class)->fromSqlRaw('SELECT * FROM tph_animals')->ignoreQueryFilters()->orderBy(Expr::field('id'))->toArray()));
    }

    public function testUnknownDiscriminatorIsRejected(): void
    {
        $this->pdo->exec("INSERT INTO tph_animals (id, Discriminator) VALUES (5, 'Unknown')");
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Unknown or incompatible discriminator');
        $this->db->set(TphAnimal::class)->find(5);
    }

    public function testAbstractRootAndCustomDiscriminator(): void
    {
        $this->pdo->exec("CREATE TABLE tph_pets (id INTEGER PRIMARY KEY, kind TEXT NOT NULL, name TEXT); INSERT INTO tph_pets VALUES (1, 'bird', 'Sky')");
        $db = new class($this->pdo) extends DbContext {
            protected function onModelCreating(ModelBuilder $builder): void
            {
                $builder->entity(TphPet::class)->hasDiscriminator('kind')->hasValue(TphBird::class, 'bird');
            }
        };
        self::assertInstanceOf(TphBird::class, $db->set(TphPet::class)->first());
        $bird = new TphBird();
        $bird->name = 'New bird';
        $db->set(TphPet::class)->add($bird);
        self::assertSame(1, $db->saveChanges());
        self::assertSame('bird', $bird->kind);
    }

    public function testConventionalTableNameComesFromRoot(): void
    {
        $this->pdo->exec("CREATE TABLE convention_animals (id INTEGER PRIMARY KEY, Discriminator TEXT); INSERT INTO convention_animals VALUES (1, 'ConventionCat')");
        self::assertInstanceOf(ConventionCat::class, $this->db->set(ConventionCat::class)->first());
    }

    public function testMissingDiscriminatorFailsClearly(): void
    {
        $this->pdo->exec('CREATE TABLE convention_animals (id INTEGER PRIMARY KEY)');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('discriminator column: Discriminator');
        $this->db->set(ConventionCat::class);
    }

    public function testDerivedTypeCannotChooseAnotherTable(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('separate table');
        $this->db->set(TphWrongCat::class);
    }

    public function testRootQueryFiltersApplyToDerivedSetsAndCanBeIgnoredWithoutLosingType(): void
    {
        $db = new class($this->pdo) extends DbContext {
            protected function onModelCreating(ModelBuilder $builder): void
            {
                $builder->entity(TphAnimal::class)->hasQueryFilter('visible', ['id' => ['gt' => 2]]);
            }
        };
        self::assertSame([4], $db->set(TphCat::class)->select(Expr::field('id'))->toArray());
        self::assertSame([2, 4], $db->set(TphCat::class)->ignoreQueryFilters()->orderBy(Expr::field('id'))->select(Expr::field('id'))->toArray());
    }

    public function testJoinAndSubqueryKeepBothTypeRestrictions(): void
    {
        $cats = $this->db->set(TphCat::class);
        $dogs = $this->db->set(TphDog::class);
        self::assertSame([['cat' => 2, 'dog' => 3], ['cat' => 4, 'dog' => 3]], $cats->join($dogs,
            Expr::field('owner_id'), Expr::field('owner_id'), Expr::projection(['cat' => 'outer.id', 'dog' => 'inner.id']))
            ->orderBy(Expr::field('cat'))->toArray());
        self::assertSame(2, $cats->where(fn ($cat) => $dogs->any(fn ($dog) => $dog->owner_id == $cat->owner_id))->count());
    }

    public function testIncludeAndLazyRelationshipsUseTheHierarchyRootByDefault(): void
    {
        $this->db->set(TphCat::class);
        $this->db->set(TphDog::class);
        $this->db->relationship(TphOwner::class, 'Animals', 'tph_animals', ['owner_id']);
        $owner = $this->db->set(TphOwner::class)->include('Animals')->first();
        $animals = $owner->getAnimals()->orderBy(Expr::field('id'))->toArray();
        self::assertSame([TphAnimal::class, TphCat::class, TphDog::class, TphKitten::class], array_map(get_class(...), $animals));
        self::assertSame($animals[1], $this->db->set(TphCat::class)->find(2));
        $this->db->relationship(TphOwner::class, 'Cats', 'tph_animals', ['owner_id'], TphCat::class);
        $owner = $this->db->set(TphOwner::class)->include('Cats')->first();
        self::assertSame([TphCat::class, TphKitten::class], array_map(get_class(...), $owner->getCats()->toArray()));
        $this->db->relationship(TphCat::class, 'Owner', 'tph_owners', ['owner_id'], TphOwner::class);
        self::assertSame($owner, $this->db->set(TphAnimal::class)->find(2)->getOwner());
    }

    public function testNoTrackingIncludesStillMaterializeDerivedTypes(): void
    {
        $this->db->relationship(TphOwner::class, 'Cats', 'tph_animals', ['owner_id'], TphCat::class);
        $owner = $this->db->set(TphOwner::class)->asNoTracking()->include('Cats')->first();
        self::assertSame([TphCat::class, TphKitten::class], array_map(get_class(...), $owner->getCats()->toArray()));
        self::assertSame([], $this->db->changeTracker->entries());
    }

    public function testCustomConfigurationAppliesToDirectMetadataDiscovery(): void
    {
        $this->pdo->exec("CREATE TABLE tph_pets (id INTEGER PRIMARY KEY, kind TEXT); INSERT INTO tph_pets VALUES (1, 'bird')");
        $db = new class($this->pdo) extends DbContext {
            protected function onModelCreating(ModelBuilder $builder): void
            {
                $builder->entity(TphPet::class)->hasDiscriminator('kind')->hasValue(TphBird::class, 'bird');
            }
        };
        self::assertSame('kind', $db->model->getEntityType(TphBird::class)->hierarchy->column);
        self::assertInstanceOf(TphBird::class, $db->set(TphPet::class)->first());
    }

    public function testDiscriminatorPredicatesAreBoundForEveryDialect(): void
    {
        foreach ([new \Light\Entity\Sql\SqliteDialect(), new \Light\Entity\Sql\MySqlDialect(),
            new \Light\Entity\Sql\PostgreSqlDialect(), new \Light\Entity\Sql\SqlServerDialect()] as $dialect) {
            $db = new DbContext($this->pdo, $dialect);
            $cats = $db->set(TphCat::class)->ignoreQueryFilters()->where(['name' => 'Cat']);
            $compiled = $cats->provider()->compile($cats->expression());
            self::assertStringContainsString($dialect->quoteIdentifier('tph_animals'), $compiled->sql);
            self::assertStringContainsString($dialect->quoteIdentifier('Discriminator'), $compiled->sql);
            self::assertContains('TphCat', $compiled->parameters);
            self::assertContains('TphKitten', $compiled->parameters);
            self::assertNotContains('TphDog', $compiled->parameters);
            self::assertStringNotContainsString("'TphCat'", $compiled->sql);
        }
    }

    public function testDuplicateIdentityAcrossSiblingTypesIsRejected(): void
    {
        $this->db->set(TphCat::class)->find(2);
        $dog = new TphDog();
        $dog->id = 2;
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('already tracked');
        $this->db->attach($dog);
    }

    public function testDuplicateDiscriminatorValuesAreRejected(): void
    {
        $db = new class($this->pdo) extends DbContext {
            protected function onModelCreating(ModelBuilder $builder): void
            {
                $builder->entity(TphAnimal::class)->hasDiscriminator()
                    ->hasValue(TphCat::class, 'same')->hasValue(TphDog::class, 'same');
            }
        };
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unique');
        $db->set(TphAnimal::class);
    }

    public function testExplicitMappingsRetainIndependentTableBehavior(): void
    {
        $this->pdo->exec('CREATE TABLE independent_cats (id INTEGER PRIMARY KEY, name TEXT)');
        $mapping = new EntityMapping(TphCat::class, 'independent_cats', ['id', 'name']);
        $db = new DbContext($this->pdo, mappings: [$mapping]);
        $cat = new TphCat();
        $cat->name = 'Independent';
        $db->set(TphCat::class)->add($cat);
        self::assertSame(1, $db->saveChanges());
        self::assertSame($cat, $db->set(TphCat::class)->find($cat->id));
        self::assertNull($mapping->hierarchy);
    }

    public function testRelationshipOverridesOnTheRootAreInheritedByConcreteEntities(): void
    {
        $this->db->relationship(TphAnimal::class, 'Keeper', 'tph_owners', ['owner_id'], TphOwner::class);
        $cat = $this->db->set(TphAnimal::class)->find(2);
        self::assertSame(1, $cat->getKeeper()->id);
    }

    public function testExplicitSiblingMappingCannotBeMixedWithInferredHierarchy(): void
    {
        $mapping = new EntityMapping(TphCat::class, 'independent_cats', ['id', 'name']);
        $db = new DbContext($this->pdo, mappings: [$mapping]);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('explicit independent mappings');
        $db->set(TphAnimal::class);
    }

    public function testAbstractIntermediateSetIncludesConcreteDescendants(): void
    {
        self::assertSame([TphCat::class, TphKitten::class], array_map(get_class(...),
            $this->db->set(TphFeline::class)->orderBy(Expr::field('id'))->toArray()));
    }

    public function testBaseQueriesUseTypedPropertiesOfTheConcreteClass(): void
    {
        $this->pdo->exec("INSERT INTO tph_animals (id, Discriminator, lives) VALUES (5, 'TphTypedCat', 9)");
        $cat = $this->db->set(TphAnimal::class)->find(5);
        self::assertInstanceOf(TphTypedCat::class, $cat);
        self::assertSame(9, $cat->lives);
        $cat->lives = 8;
        self::assertSame(1, $this->db->saveChanges());
        self::assertSame('8', (string) $this->pdo->query('SELECT lives FROM tph_animals WHERE id = 5')->fetchColumn());
    }

    public function testNumericDiscriminatorColumnsAreRejected(): void
    {
        $this->pdo->exec('CREATE TABLE convention_animals (id INTEGER PRIMARY KEY, Discriminator INTEGER)');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('writable string discriminator');
        $this->db->set(ConventionCat::class);
    }
}
