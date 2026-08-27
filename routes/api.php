<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\TagController;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::name('v1.')->group(function () {
        Route::post('/register', [AuthController::class, 'register'])
            ->middleware('throttle:5,1')
            ->name('auth.register');

        Route::post('/login', [AuthController::class, 'login'])
            ->middleware('throttle:login')
            ->name('auth.login');

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');

            Route::get('/user', function (Request $request) {
                return new UserResource($request->user());
            })->name('auth.user');

            Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])
                ->middleware('throttle:6,1')
                ->name('verification.send');
        });

        Route::middleware(['auth:sanctum', 'verified'])->group(function () {
            Route::apiResource('tags', TagController::class);
        });
    });

    // Not under the v1. name prefix: Illuminate\Auth\Notifications\VerifyEmail
    // hardcodes the route name 'verification.verify' when building the signed link.
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');
});
