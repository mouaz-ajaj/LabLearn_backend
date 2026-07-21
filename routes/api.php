<?php

use App\Http\Controllers\Api\Auth\ForgotPasswordController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\LogoutController;
use App\Http\Controllers\Api\Auth\RegisterController;
use App\Http\Controllers\Api\Auth\ResetPasswordController;
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

    Route::prefix('users')->middleware('auth:sanctum')->group(function (): void {
        Route::get('me', MeController::class);
        Route::patch('me', [ProfileController::class, 'update']);
        Route::delete('me', [ProfileController::class, 'destroy']);
    });
});
