<?php

declare(strict_types=1);

namespace Light\Entity;

/** Override the synchronous save hooks your application needs. */
abstract class SaveChangesInterceptor
{
    public function savingChanges(DbContext $context): void {}
    public function savedChanges(DbContext $context, int $result): void {}
    public function saveChangesFailed(DbContext $context, \Throwable $exception): void {}
}
