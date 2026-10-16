<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')
    ->name('auth.')
    ->group(function (): void {
        Route::post('login', [AuthController::class, 'login'])->name('login');
        Route::post('register', [AuthController::class, 'register'])->name('register');
        Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->name('forgot-password');
        Route::post('refresh', [AuthController::class, 'refresh'])->name('refresh');

        Route::middleware('auth:api')->group(function (): void {
            Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        });
    });
