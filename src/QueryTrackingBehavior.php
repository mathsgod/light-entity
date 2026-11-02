<?php

declare(strict_types=1);

namespace Light\Entity;

enum QueryTrackingBehavior: string
{
    case TrackAll = 'trackAll';
    case NoTracking = 'noTracking';
}
