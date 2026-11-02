<?php

declare(strict_types=1);

namespace Light\Entity\Tests\Integration;

use Light\Entity\Tests\Support\MySqlDatabase;
use Light\Entity\Tests\JoinTest;

/** Runs the shared JOIN contract against a real MySQL 8 server. */
final class MySqlJoinTest extends JoinTest
{
    use MySqlDatabase;
}
