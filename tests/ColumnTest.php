<?php

declare(strict_types=1);

namespace PhpEntity\Tests;

use PhpEntity\Schema\Column;
use PHPUnit\Framework\TestCase;

final class ColumnTest extends TestCase
{
    public function testMixedCaseMetadataPreservesTypeAndConversion(): void
    {
        foreach ([
            ['BoOlEaN', '1', true],
            ['INT', '42', 42],
            ['DoUbLe', '1.25', 1.25],
            ['DECIMAL(20,6)', '12345678901234.123456', '12345678901234.123456'],
            ['BIGINT UNSIGNED', '18446744073709551615', '18446744073709551615'],
            ['TEXT', 'hello', 'hello'],
            ['', 42, 42],
        ] as [$type, $input, $expected]) {
            $column = new Column('value', $type, true);
            self::assertSame($type, $column->type);
            self::assertSame($expected, $column->convert($input));
            self::assertNull($column->convert(null));
        }
    }

    public function testOutOfRangeIntegerRetainsPrecision(): void
    {
        $column = new Column('large', 'bigint unsigned', false);
        self::assertSame('18446744073709551615', $column->convert('18446744073709551615'));
    }

    public function testDecimalRetainsPrecision(): void
    {
        $column = new Column('amount', 'decimal(20,6)', false);
        self::assertSame('12345678901234.123456', $column->convert('12345678901234.123456'));
    }
}
