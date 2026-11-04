<?php

declare(strict_types=1);

namespace Light\Entity\Tests\Integration;

use Light\Entity\Tests\Support\{MySqlDatabase, ValueConversionScenarios};
use PDO;
use PHPUnit\Framework\TestCase;

final class MySqlValueConversionTest extends TestCase
{
    use MySqlDatabase;
    use ValueConversionScenarios;

    protected function conversionConnection(): PDO { return $this->connection(); }
}
