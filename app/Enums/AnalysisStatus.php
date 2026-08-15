<?php

namespace App\Enums;

enum AnalysisStatus: string
{
    case Queued = 'QUEUED';
    case Processing = 'PROCESSING';
    case Succeeded = 'SUCCEEDED';
    case Failed = 'FAILED';
}
