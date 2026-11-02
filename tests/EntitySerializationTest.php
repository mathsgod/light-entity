<?php

declare(strict_types=1);

namespace Light\Entity\Tests;

use Light\Entity\{DbContext, Entity, EntityMapping, QueryTrackingBehavior};
use Light\Entity\Schema\Column;
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

#[\AllowDynamicProperties]
class AllowedSerializationParent extends Entity {}

final class AllowedSerializationEntity extends AllowedSerializationParent {}

#[\AllowDynamicProperties]
final class CustomSetterSerializationEntity extends Entity
{
    public function __set(string $name, mixed $value): void
    {
        $this->$name = $value;
    }
}

final class EntitySerializationTest extends TestCase
{
    public function testCachedPropertiesKeepInitializationAndValuesPerInstance(): void
    {
        $first = new SerializationEntity();
        $first->id = 1;
        $first->uninitialized = 'first';
        self::assertSame('first', $first->jsonSerialize()['uninitialized']);
        $second = new SerializationEntity();
        self::assertArrayNotHasKey('id', $second->jsonSerialize());
        self::assertArrayNotHasKey('uninitialized', $second->jsonSerialize());
        $second->id = 2;
        $second->uninitialized = 'second';
        self::assertSame(2, $second->jsonSerialize()['id']);
        self::assertSame('second', $second->jsonSerialize()['uninitialized']);
        unset($second->id);
        self::assertArrayNotHasKey('id', $second->jsonSerialize());
        self::assertSame(1, $first->jsonSerialize()['id']);
        self::assertSame('first', $first->jsonSerialize()['uninitialized']);
    }

    public function testCustomSettersKeepInstanceSpecificDynamicProperties(): void
    {
        $first = new CustomSetterSerializationEntity();
        $first->first = 1;
        self::assertSame(['first' => 1], $first->jsonSerialize());
        $second = new CustomSetterSerializationEntity();
        $second->second = null;
        self::assertSame(['second' => null], $second->jsonSerialize());
        $first->third = true;
        self::assertSame(['first' => 1, 'third' => true], $first->jsonSerialize());
    }

    public function testInheritedAllowDynamicPropertiesKeepsUnserializedPublicValues(): void
    {
        $class = AllowedSerializationEntity::class;
        $entity = unserialize('O:'.strlen($class).':"'.$class.'":1:{s:5:"extra";s:5:"value";}',
            ['allowed_classes' => [$class]]);
        self::assertInstanceOf(AllowedSerializationEntity::class, $entity);
        self::assertSame(['extra' => 'value'], $entity->jsonSerialize());
    }

    public function testPropertyHooksKeepRawValuesAndExcludeVirtualProperties(): void
    {
        if (PHP_VERSION_ID < 80400) {
            self::markTestSkipped('Property hooks require PHP 8.4.');
        }
        eval('namespace Light\Entity\\Tests;
            final class HookedSerializationEntity extends \\Light\Entity\\Entity {
                public string $name = "raw" { get => strtoupper($this->name); }
                public string $computed { get => "virtual"; }
            }');
        $entity = new HookedSerializationEntity();
        self::assertSame('RAW', $entity->name);
        self::assertSame(['name' => 'raw'], $entity->jsonSerialize());
    }

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
