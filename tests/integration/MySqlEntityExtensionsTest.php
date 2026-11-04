<?php

declare(strict_types=1);

namespace Light\Entity\Tests\Integration;

use Light\Entity\Tests\EntityExtensionsTest;
use Light\Entity\Tests\Support\MySqlDatabase;

final class MySqlEntityExtensionsTest extends EntityExtensionsTest
{
    use MySqlDatabase;
}
