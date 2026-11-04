<?php

declare(strict_types=1);

namespace Light\Entity\Tests;

use Light\Entity\Tests\Support\ValueConversionScenarios;
use PDO;
use PHPUnit\Framework\TestCase;

final class ValueConversionTest extends TestCase
{
    use ValueConversionScenarios;

    protected function conversionConnection(): PDO
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) { self::markTestSkipped('pdo_sqlite required.'); }
        return new PDO('sqlite::memory:');
    }
}
