<?php

namespace App\Enums;

enum ExtractionJobStatus: string
{
    case Queued = 'QUEUED';
    case Processing = 'PROCESSING';
    case Succeeded = 'SUCCEEDED';
    case Failed = 'FAILED';
}
