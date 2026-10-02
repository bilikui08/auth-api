<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::post('/auth', [AuthController::class, 'auth'])->name('auth');
Route::post('/register', [AuthController::class, 'register'])->name('register');
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->name('forgot-password');
Route::post('/refresh', [AuthController::class, 'refresh'])->name('refresh');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:api')->name('logout');
