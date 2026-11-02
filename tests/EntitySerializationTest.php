<?php

declare(strict_types=1);

namespace PhpEntity\Tests;

use PhpEntity\{DbContext, Entity, EntityMapping, QueryTrackingBehavior};
use PhpEntity\Schema\Column;
use PHPUnit\Framework\TestCase;

class SerializationParent extends Entity
{
    public string $inherited = 'parent';
    protected string $protectedSecret = 'hidden';
}

final class SerializationEntity extends SerializationParent
{
    public int $id;
    public ?string $nullable = null;
    public string $uninitialized;
    public string $data = 'public name collision';
    public static string $staticSecret = 'hidden';
    private string $privateSecret = 'hidden';
}

final class DynamicSerializationEntity extends Entity {}

final class EntitySerializationTest extends TestCase
{
    public function testBulkInitializationPreservesNullTypesAndSubsequentEdits(): void
    {
        $entity = new DynamicSerializationEntity();
        $values = ['id' => 1, 'name' => 'Mary', 'active' => false, 'optional' => null];
        $entity->initializeFromRow($values);
        self::assertSame($values, $entity->jsonSerialize());
        self::assertTrue($entity->hasValue('optional'));
        self::assertNull($entity->optional);
        $entity->name = 'Updated';
        self::assertSame('Updated', $entity->jsonSerialize()['name']);
        self::assertSame('Mary', $values['name']);
    }

    public function testMergesInitializedPublicPropertiesAndPreservesValueTypes(): void
    {
        $entity = new SerializationEntity();
        $entity->__set('id', 99);
        $entity->id = 7;
        $entity->name = 'Mary';
        $entity->active = true;
        $entity->optional = null;
        $values = $entity->jsonSerialize();
        self::assertSame(7, $values['id']); // Public properties override internal values.
        self::assertSame('Mary', $values['name']);
        self::assertTrue($values['active']);
        self::assertNull($values['optional']);
        self::assertNull($values['nullable']);
        self::assertSame('parent', $values['inherited']);
        self::assertSame('public name collision', $values['data']);
        self::assertArrayNotHasKey('uninitialized', $values);
        self::assertArrayNotHasKey('protectedSecret', $values);
        self::assertArrayNotHasKey('privateSecret', $values);
        self::assertArrayNotHasKey('staticSecret', $values);
        self::assertSame($values, json_decode(json_encode($entity, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testAttachedContextAndMappingAreNeverSerialized(): void
    {
        $mapping = new EntityMapping(DynamicSerializationEntity::class, 'users', ['id', 'name']);
        $entity = $mapping->hydrate(['id' => 1, 'name' => 'Mary']);
        $context = (new \ReflectionClass(DbContext::class))->newInstanceWithoutConstructor();
        $entity->attachRelations($context, $mapping, QueryTrackingBehavior::NoTracking);
        self::assertSame('{"id":1,"name":"Mary"}', json_encode($entity, JSON_THROW_ON_ERROR));
        $entity->name = 'Updated';
        self::assertSame('{"id":1,"name":"Updated"}', json_encode($entity, JSON_THROW_ON_ERROR));
    }

    public function testHydratedMappedPublicPropertiesAreIncluded(): void
    {
        $mapping = new EntityMapping(SerializationEntity::class, 'users', ['id', 'nullable'], columns: [
            'id' => new Column('id', 'int', false),
            'nullable' => new Column('nullable', 'text', true),
        ]);
        $entity = $mapping->hydrate(['id' => '42', 'nullable' => 'from database']);
        $values = json_decode(json_encode($entity, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(42, $values['id']);
        self::assertSame('from database', $values['nullable']);
        self::assertArrayNotHasKey('uninitialized', $values);
    }
}
