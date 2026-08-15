<?php

namespace App\Policies;

use App\Models\QuizSession;
use App\Models\User;

class QuizSessionPolicy
{
    public function view(User $user, QuizSession $quizSession): bool
    {
        return $quizSession->user_id === $user->getKey();
    }
}
