<?php

declare(strict_types=1);

namespace PhpEntity\Tests\Integration;

use PhpEntity\Tests\Support\MySqlDatabase;
use PhpEntity\Tests\JoinTest;

/** Runs the shared JOIN contract against a real MySQL 8 server. */
final class MySqlJoinTest extends JoinTest
{
    use MySqlDatabase;
}
