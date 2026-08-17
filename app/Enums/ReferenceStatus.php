<?php

namespace App\Enums;

enum ReferenceStatus: string
{
    case Within = 'WITHIN_REFERENCE';
    case Below = 'BELOW_REFERENCE';
    case Above = 'ABOVE_REFERENCE';
    case Unknown = 'UNKNOWN';
}
