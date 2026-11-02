<?php

declare(strict_types=1);

namespace PhpEntity;

enum QueryTrackingBehavior: string
{
    case TrackAll = 'trackAll';
    case NoTracking = 'noTracking';
}
