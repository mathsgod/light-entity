<?php

declare(strict_types=1);

namespace Light\Entity\Tests;

use Light\Entity\{Entity, EntityMapping, ValueComparer, ValueConverter};
use PHPUnit\Framework\TestCase;

final class ConverterFixture extends Entity {}

final class ValueConverterTest extends TestCase
{
    public function testNullBypassesConverterAndComparerCallbacks(): void
    {
        $fail = static function (): never { throw new \LogicException('Null must bypass callbacks.'); };
        $converter = new ValueConverter($fail, $fail);
        $comparer = new ValueComparer($fail, $fail);
        self::assertNull($converter->convertFromProvider(null));
        self::assertNull($converter->convertToProvider(null));
        self::assertNull($comparer->snapshot(null));
        self::assertTrue($comparer->equals(null, null));
        self::assertFalse($comparer->equals(null, []));
        self::assertFalse($comparer->equals([], null));
    }

    public function testConverterRejectsNonScalarProviderResults(): void
    {
        $converter = new ValueConverter(fn ($v) => ['invalid'], fn ($v) => $v);
        $this->expectException(\InvalidArgumentException::class);
        $converter->convertToProvider('value');
    }

    public function testMappingRejectsUnknownOrPrimaryKeyHandlers(): void
    {
        $converter = new ValueConverter(fn ($v) => $v, fn ($v) => $v);
        foreach ([['unknown'=>$converter], ['id'=>$converter], ['settings'=>new \stdClass()]] as $converters) {
            try {
                new EntityMapping(ConverterFixture::class, 'users', ['id','settings'], converters: $converters);
                self::fail('Invalid handler configuration must be rejected.');
            } catch (\InvalidArgumentException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }

    public function testNonScalarValuesRequireExplicitSnapshotSemantics(): void
    {
        $mapping = new EntityMapping(ConverterFixture::class, 'users', ['id','settings'],
            converters: ['settings'=>new ValueConverter(fn ($v) => json_encode($v, JSON_THROW_ON_ERROR), fn ($v) => json_decode($v, true, 512, JSON_THROW_ON_ERROR))]);
        $entity = $mapping->hydrate(['id'=>1,'settings'=>'{"theme":"dark"}']);
        $this->expectException(\InvalidArgumentException::class);
        $mapping->values($entity);
    }

    public function testComparerCanIgnoreIrrelevantDifferencesWithoutChangingStorageValues(): void
    {
        $mapping = new EntityMapping(ConverterFixture::class, 'users', ['id','settings'],
            comparers: ['settings'=>new ValueComparer(fn ($a,$b) => strtolower($a) === strtolower($b), fn ($v) => $v)]);
        self::assertTrue($mapping->valuesEqual(['id'=>1,'settings'=>'DARK'], ['id'=>1,'settings'=>'dark']));
        self::assertFalse($mapping->valuesEqual(['id'=>1,'settings'=>'dark'], ['id'=>1,'settings'=>'light']));
        self::assertSame(['settings'=>'DARK'], $mapping->toProviderValues(['settings'=>'DARK']));
    }
}
