<?php

namespace App\Enums;

enum ComparisonTrend: string
{
    case Increased = 'INCREASED';
    case Decreased = 'DECREASED';
    case Stable = 'STABLE';
    case InsufficientData = 'INSUFFICIENT_DATA';
    case NotComparable = 'NOT_COMPARABLE';
}
