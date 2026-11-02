<?php

declare(strict_types=1);

namespace Light\Entity\Sql;

final readonly class CompiledQuery
{
    /** @param array<string, mixed> $parameters */
    public function __construct(
        public string $sql,
        public array $parameters,
        public string $resultMode = 'rows',
        public mixed $defaultValue = null,
    ) {
    }
}
