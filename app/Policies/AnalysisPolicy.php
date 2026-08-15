<?php

namespace App\Policies;

use App\Models\Analysis;
use App\Models\User;

class AnalysisPolicy
{
    public function view(User $user, Analysis $analysis): bool
    {
        return $analysis->user_id === $user->getKey();
    }
}
