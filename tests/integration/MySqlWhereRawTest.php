<?php

declare(strict_types=1);

namespace Light\Entity\Tests\Integration;

use Light\Entity\Tests\{WhereRawTest, WhereRawUser};
use Light\Entity\Tests\Support\MySqlDatabase;

final class MySqlWhereRawTest extends WhereRawTest
{
    use MySqlDatabase;

    public function testBinaryComparisonUsesMappedTableWithoutWritingSelect(): void
    {
        $db = $this->fixture();
        $users = $db->set(WhereRawUser::class)->whereRaw('BINARY name = ?', ['Ada'])->toArray();
        self::assertCount(1, $users);
        self::assertSame(1, $users[0]->id);
    }
}
