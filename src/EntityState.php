<?php

declare(strict_types=1);

namespace PhpEntity;

enum EntityState: string
{
    case Detached = 'detached';
    case Unchanged = 'unchanged';
    case Added = 'added';
    case Modified = 'modified';
    case Deleted = 'deleted';
}
