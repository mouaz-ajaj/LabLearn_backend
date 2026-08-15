<?php

namespace App\Enums;

enum QuizSessionStatus: string
{
    case Preparing = 'PREPARING';
    case Ready = 'READY';
    case InProgress = 'IN_PROGRESS';
    case Completed = 'COMPLETED';
    case Failed = 'FAILED';
}
