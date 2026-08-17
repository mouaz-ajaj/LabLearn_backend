<?php

namespace App\Enums;

enum ReferenceTrend: string
{
    case MovedCloser = 'MOVED_CLOSER_TO_REFERENCE';
    case MovedFarther = 'MOVED_FARTHER_FROM_REFERENCE';
    case RemainedWithin = 'REMAINED_WITHIN_REFERENCE';
    case RemainedOutside = 'REMAINED_OUTSIDE_REFERENCE';
    case Unknown = 'UNKNOWN';
}
