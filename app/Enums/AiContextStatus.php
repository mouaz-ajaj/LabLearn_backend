<?php

namespace App\Enums;

enum AiContextStatus: string
{
    case Available = 'AVAILABLE';
    case Fallback = 'FALLBACK';
}
