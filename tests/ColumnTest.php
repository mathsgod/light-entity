<?php

declare(strict_types=1);

namespace PhpEntity\Tests;

use PhpEntity\Schema\Column;
use PHPUnit\Framework\TestCase;

final class ColumnTest extends TestCase
{
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
