<?php

namespace App\Enums;

enum AnalysisFlow: string
{
    case DirectResult = 'direct-result';
    case QuizFirst = 'quiz-first';
}
