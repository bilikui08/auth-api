<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\UserRoleController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')
    ->name('auth.')
    ->group(function (): void {
        Route::post('login', [AuthController::class, 'login'])->name('login');
        Route::post('select-organization', [AuthController::class, 'selectOrganization'])->name('select-organization');
        Route::post('switch-organization', [AuthController::class, 'switchOrganization'])->name('switch-organization');
        Route::post('register', [AuthController::class, 'register'])->name('register');
        Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->name('forgot-password');
        Route::post('refresh', [AuthController::class, 'refresh'])->name('refresh');

        Route::middleware('auth:api')->group(function (): void {
            Route::get('organizations', [AuthController::class, 'organizations'])->name('organizations');
            Route::get('me', [AuthController::class, 'me'])->name('me');
            Route::post('logout', [AuthController::class, 'logout'])->name('logout');

            // Gestion de roles: solo administradores.
            Route::middleware('role:admin')->group(function (): void {
                Route::apiResource('roles', RoleController::class);

                Route::get('users/{user}/roles', [UserRoleController::class, 'index'])->name('users.roles.index');
                Route::put('users/{user}/roles', [UserRoleController::class, 'sync'])->name('users.roles.sync');
                Route::post('users/{user}/roles/{role}', [UserRoleController::class, 'attach'])->name('users.roles.attach');
                Route::delete('users/{user}/roles/{role}', [UserRoleController::class, 'detach'])->name('users.roles.detach');
            });
        });
    });
