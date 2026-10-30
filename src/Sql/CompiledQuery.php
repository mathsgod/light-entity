<?php

declare(strict_types=1);

namespace PhpEntity\Sql;

final readonly class CompiledQuery
{
    /** @param array<string, mixed> $parameters */
    public function __construct(
        public string $sql,
        public array $parameters,
        public string $resultMode = 'rows',
    ) {
    }
}
