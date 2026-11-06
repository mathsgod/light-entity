<?php

declare(strict_types=1);

namespace Light\Entity\Sql;

use Light\Linq\Expressions\ValueExpression;

/** Escapes a literal needle and adds the wildcards needed by a string operation. @internal */
final readonly class StringPatternExpression implements ValueExpression
{
    public function __construct(public ValueExpression $needle, public string $operation)
    {
        if (!in_array($operation, ['startsWith', 'endsWith', 'contains'], true)) {
            throw new \InvalidArgumentException('Unsupported string pattern operation: '.$operation);
        }
    }
}
