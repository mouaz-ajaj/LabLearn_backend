<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Quiz\CaseSpecificQuestionBuilder;
use App\Services\Quiz\CaseSpecificQuestionProvider;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Phase 3B.3: real, deterministic template-driven Case-Specific generation
        // from persisted KBS evidence. NullCaseSpecificQuestionProvider (Phase 3B.2)
        // remains available for tests/scenarios with no Analysis at all.
        $this->app->bind(CaseSpecificQuestionProvider::class, CaseSpecificQuestionBuilder::class);
    }

    public function boot(): void
    {
        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(60)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        RateLimiter::for('auth.register', fn (Request $request): Limit => Limit::perMinute(5)
            ->by('register:'.$request->ip()));

        RateLimiter::for('auth.login', function (Request $request): array {
            $email = Str::lower($request->string('email')->trim()->toString());

            return [
                Limit::perMinute(30)->by('login-ip:'.$request->ip()),
                Limit::perMinute(5)->by('login-account:'.$email.'|'.$request->ip()),
            ];
        });

        RateLimiter::for('auth.password', fn (Request $request): Limit => Limit::perMinute(5)
            ->by('password:'.$request->ip()));

        ResetPassword::createUrlUsing(function (User $user, string $token): string {
            $query = http_build_query([
                'token' => $token,
                'email' => $user->email,
            ]);

            return rtrim((string) config('app.frontend_url'), '/').'/reset-password?'.$query;
        });
    }
}
