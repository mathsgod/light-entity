<?php

declare(strict_types=1);

namespace Light\Entity\Tests;

use InvalidArgumentException;
use LogicException;
use PDO;
use Light\Entity\DbContext;
use Light\Entity\Entity;
use Light\Entity\EntityMapping;
use Light\Entity\IModel;
use Light\Entity\Table;
use PHPUnit\Framework\TestCase;

#[Table('model_users')]
final class ModelUser extends Entity {}
final class ModelWidget extends Entity {}

final class ModelTest extends TestCase
{
    private PDO $pdo;
    private DbContext $db;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required for model integration tests.');
        }
        $this->pdo = new PDO('sqlite::memory:');
        $this->db = new DbContext($this->pdo);
    }

    public function testFindAndListDoNotDiscoverSchema(): void
    {
        // No tables exist: looking up metadata does not attempt schema discovery.
        self::assertInstanceOf(IModel::class, $this->db->model);
        self::assertNull($this->db->model->findEntityType(ModelUser::class));
        self::assertSame([], $this->db->model->getEntityTypes());
    }

    public function testGetDiscoversMetadataWithoutCallingSet(): void
    {
        $this->createUsers();
        $mapping = $this->db->model->getEntityType(ModelUser::class);

        self::assertSame(ModelUser::class, $mapping->class);
        self::assertSame('model_users', $mapping->table);
        self::assertSame('id', $mapping->key);
        self::assertTrue($mapping->generatedKey);
        self::assertSame(['id', 'name'], $mapping->properties);
        self::assertSame('TEXT', $mapping->columns['name']->type);
        self::assertFalse($mapping->columns['name']->nullable);
        self::assertSame($mapping, $this->db->model->findEntityType(ModelUser::class));
        self::assertSame([$mapping], $this->db->model->getEntityTypes());
    }

    public function testSetUsesAndReusesModelMetadata(): void
    {
        $this->createUsers();
        $users = $this->db->set(ModelUser::class);
        $mapping = $this->db->model->findEntityType(ModelUser::class);
        self::assertNotNull($mapping);
        self::assertSame($mapping, $this->db->model->getEntityType(ModelUser::class));
        $user = new ModelUser();
        $user->name = 'Peter';
        $users->add($user);
        self::assertSame(1, $this->db->saveChanges());
        self::assertSame($user, $this->db->set(ModelUser::class)->find($user->id));
    }

    public function testExplicitTableOverrideIsSharedWithSet(): void
    {
        $this->createUsers();
        $mapping = $this->db->model->getEntityType(ModelWidget::class, 'model_users');
        self::assertSame('model_users', $mapping->table);
        self::assertSame(0, $this->db->set(ModelWidget::class)->count());
        self::assertSame($mapping, $this->db->model->getEntityType(ModelWidget::class));
        $this->expectException(LogicException::class);
        $this->db->set(ModelWidget::class, 'different_table');
    }

    public function testSchemaCacheIsScopedToModel(): void
    {
        $this->createUsers();
        $cached = $this->db->model->getEntityType(ModelUser::class);
        $this->pdo->exec('ALTER TABLE model_users ADD COLUMN age INTEGER');
        self::assertSame($cached, $this->db->model->getEntityType(ModelUser::class));
        self::assertArrayNotHasKey('age', $cached->columns);
        $newContext = new DbContext($this->pdo);
        self::assertNull($newContext->model->findEntityType(ModelUser::class));
        self::assertArrayHasKey('age', $newContext->model->getEntityType(ModelUser::class)->columns);
    }

    public function testFailedDiscoveryIsNotCachedAndCanBeRetried(): void
    {
        try {
            $this->db->model->getEntityType(ModelUser::class);
            self::fail('Expected missing-table error.');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('Table not found', $error->getMessage());
        }
        self::assertNull($this->db->model->findEntityType(ModelUser::class));
        self::assertSame([], $this->db->model->getEntityTypes());
        $this->createUsers();
        self::assertSame('model_users', $this->db->model->getEntityType(ModelUser::class)->table);
    }

    public function testExplicitMappingsAreAvailableWithoutSchemaDiscovery(): void
    {
        $mapping = new EntityMapping(ModelUser::class, 'manual_users', ['id', 'name']);
        $db = new DbContext($this->pdo, mappings: [$mapping]);
        self::assertSame($mapping, $db->model->findEntityType(ModelUser::class));
        self::assertSame($mapping, $db->model->getEntityType(ModelUser::class));
        self::assertSame([$mapping], $db->model->getEntityTypes());
    }

    public function testDuplicateExplicitMappingsAreRejected(): void
    {
        $mapping = new EntityMapping(ModelUser::class, 'manual_users', ['id', 'name']);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate entity mapping');
        new DbContext($this->pdo, mappings: [$mapping, $mapping]);
    }

    private function createUsers(): void
    {
        $this->pdo->exec('CREATE TABLE model_users (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
    }
}
