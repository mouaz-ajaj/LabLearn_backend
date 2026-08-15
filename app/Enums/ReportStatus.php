<?php

namespace App\Enums;

enum ReportStatus: string
{
    case Uploaded = 'UPLOADED';
    case Queued = 'QUEUED';
    case Processing = 'PROCESSING';
    case NeedsReview = 'NEEDS_REVIEW';
    case Verified = 'VERIFIED';
    case Analyzed = 'ANALYZED';
    case Completed = 'COMPLETED';
    case Failed = 'FAILED';
}
