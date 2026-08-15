<?php

namespace App\Enums;

enum ReportSourceType: string
{
    case Pdf = 'PDF';
    case Image = 'IMAGE';
    case Camera = 'CAMERA';
}
