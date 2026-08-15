<?php

namespace App\Policies;

use App\Models\ExtractionJob;
use App\Models\User;

class ExtractionJobPolicy
{
    public function view(User $user, ExtractionJob $extractionJob): bool
    {
        return $extractionJob->report()
            ->where('user_id', $user->getKey())
            ->exists();
    }
}
