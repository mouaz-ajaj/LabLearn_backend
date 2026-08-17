<?php

use App\Http\Controllers\Api\Analysis\RequestResultExplanationController;
use App\Http\Controllers\Api\Analysis\ShowAnalysisController;
use App\Http\Controllers\Api\Analysis\StartAnalysisController;
use App\Http\Controllers\Api\Auth\ForgotPasswordController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\LogoutController;
use App\Http\Controllers\Api\Auth\RegisterController;
use App\Http\Controllers\Api\Auth\ResetPasswordController;
use App\Http\Controllers\Api\Comparison\CreateComparisonController;
use App\Http\Controllers\Api\Job\ExtractionJobController;
use App\Http\Controllers\Api\Quiz\ListQuizHistoryController;
use App\Http\Controllers\Api\Quiz\ShowQuizController;
use App\Http\Controllers\Api\Quiz\StartQuizController;
use App\Http\Controllers\Api\Quiz\SubmitQuizAnswerController;
use App\Http\Controllers\Api\Report\CreateReportController;
use App\Http\Controllers\Api\Report\ExtractedResultController;
use App\Http\Controllers\Api\Report\ListReportsController;
use App\Http\Controllers\Api\Report\ProcessReportController;
use App\Http\Controllers\Api\Report\ReportFileController;
use App\Http\Controllers\Api\Report\ShowReportController;
use App\Http\Controllers\Api\Report\ShowReportVerificationController;
use App\Http\Controllers\Api\Report\StoreReportVerificationController;
use App\Http\Controllers\Api\User\MeController;
use App\Http\Controllers\Api\User\ProfileController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('throttle:api')->group(function (): void {
    Route::prefix('auth')->group(function (): void {
        Route::post('register', RegisterController::class)->middleware('throttle:auth.register');
        Route::post('login', LoginController::class)->middleware('throttle:auth.login');
        Route::post('forgot-password', ForgotPasswordController::class)->middleware('throttle:auth.password');
        Route::post('reset-password', ResetPasswordController::class)->middleware('throttle:auth.password');

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::post('logout', LogoutController::class);
            Route::get('me', MeController::class);
        });
    });

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::prefix('users')->group(function (): void {
            Route::get('me', MeController::class);
            Route::patch('me', [ProfileController::class, 'update']);
            Route::delete('me', [ProfileController::class, 'destroy']);
        });

        Route::get('reports', ListReportsController::class);
        Route::post('reports', CreateReportController::class);
        Route::get('reports/{report}', ShowReportController::class);
        Route::post('reports/{report}/files', ReportFileController::class);
        Route::post('reports/{report}/process', ProcessReportController::class);
        Route::get('reports/{report}/extracted-results', ExtractedResultController::class);
        Route::post('reports/{report}/verification', StoreReportVerificationController::class);
        Route::get('reports/{report}/verification', ShowReportVerificationController::class);
        Route::post('reports/{report}/analyze', StartAnalysisController::class);
        Route::get('analyses/{analysis}', ShowAnalysisController::class);
        Route::post('analyses/{analysis}/explanation', RequestResultExplanationController::class);
        Route::post('reports/{report}/quiz', StartQuizController::class);
        Route::get('quiz/{quiz}', ShowQuizController::class);
        Route::post('quiz/{quiz}/answers', SubmitQuizAnswerController::class);
        Route::get('students/me/quiz-history', ListQuizHistoryController::class);
        Route::get('jobs/{job}', ExtractionJobController::class);
        Route::post('comparisons', CreateComparisonController::class);
    });
});
