<?php

use App\Http\Controllers\AdminOrganizationController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\EmailTemplateController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\SystemController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\UserRoleController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')
    ->name('auth.')
    ->group(function (): void {
        // Público: el login muestra el nombre del sistema antes de autenticar
        Route::get('branding', [SystemController::class, 'branding'])->middleware('throttle:60,1')->name('branding');
        Route::post('login', [AuthController::class, 'login'])->name('login');
        Route::post('select-organization', [AuthController::class, 'selectOrganization'])->name('select-organization');
        Route::post('switch-organization', [AuthController::class, 'switchOrganization'])->name('switch-organization');
        // Invitaciones: la única forma de tener una cuenta nueva (no hay registro abierto). El token del enlace es la
        // credencial, así que se limita la frecuencia para que no se pueda adivinar.
        Route::get('invitations/{token}', [InvitationController::class, 'show'])->middleware('throttle:30,1')->name('invitations.show');
        Route::post('invitations/{token}/accept', [InvitationController::class, 'accept'])->middleware('throttle:10,1')->name('invitations.accept');
        Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->name('forgot-password');
        Route::post('refresh', [AuthController::class, 'refresh'])->name('refresh');

        Route::middleware('auth:api')->group(function (): void {
            Route::get('organizations', [AuthController::class, 'organizations'])->name('organizations');
            Route::get('me', [AuthController::class, 'me'])->name('me');
            Route::post('logout', [AuthController::class, 'logout'])->name('logout');

            // Administración de la empresa activa: personas y roles (permiso org.members.manage en esa empresa).
            Route::middleware('org.permission:org.members.manage')->prefix('organization')->name('organization.')->group(function (): void {
                Route::get('/', [OrganizationController::class, 'show'])->name('show');
                Route::get('roles', [OrganizationController::class, 'roles'])->name('roles');
                Route::get('members', [OrganizationController::class, 'members'])->name('members');
                Route::post('members', [OrganizationController::class, 'addMember'])->name('members.add');
                Route::get('invitations', [OrganizationController::class, 'invitations'])->name('invitations');
                Route::post('invitations', [OrganizationController::class, 'invite'])->name('invitations.store');
                Route::delete('invitations/{invitation}', [OrganizationController::class, 'revokeInvitation'])->whereNumber('invitation')->name('invitations.revoke');
                Route::put('members/{user}', [OrganizationController::class, 'setMemberRoles'])->name('members.roles');
                Route::delete('members/{user}', [OrganizationController::class, 'removeMember'])->name('members.remove');
            });

            // Gestion de roles: solo administradores.
            Route::middleware('role:admin')->group(function (): void {
                Route::get('permissions', [RoleController::class, 'permissions'])->name('permissions');
                Route::apiResource('roles', RoleController::class);

                // Todas las empresas (administrador del sistema)
                Route::get('admin/organizations', [AdminOrganizationController::class, 'index'])->name('admin.organizations.index');
                Route::post('admin/organizations', [AdminOrganizationController::class, 'store'])->name('admin.organizations.store');
                Route::put('admin/organizations/{organization}', [AdminOrganizationController::class, 'update'])->name('admin.organizations.update');
                // Ajustes de la plataforma y plantillas de correo (el SMTP y los secretos NO se editan acá: viven en el .env)
                Route::get('admin/system', [SystemController::class, 'show'])->name('admin.system.show');
                Route::put('admin/system', [SystemController::class, 'update'])->name('admin.system.update');
                Route::get('admin/email-templates', [EmailTemplateController::class, 'index'])->name('admin.email-templates.index');
                Route::put('admin/email-templates/{key}', [EmailTemplateController::class, 'update'])->name('admin.email-templates.update');
                Route::delete('admin/email-templates/{key}', [EmailTemplateController::class, 'restore'])->name('admin.email-templates.restore');
                Route::post('admin/email-templates/{key}/preview', [EmailTemplateController::class, 'preview'])->name('admin.email-templates.preview');
                Route::post('admin/email-templates/{key}/test', [EmailTemplateController::class, 'test'])->middleware('throttle:5,1')->name('admin.email-templates.test');
                Route::get('admin/users', [AdminOrganizationController::class, 'users'])->name('admin.users');

                Route::get('users/{user}/roles', [UserRoleController::class, 'index'])->name('users.roles.index');
                Route::put('users/{user}/roles', [UserRoleController::class, 'sync'])->name('users.roles.sync');
                Route::post('users/{user}/roles/{role}', [UserRoleController::class, 'attach'])->name('users.roles.attach');
                Route::delete('users/{user}/roles/{role}', [UserRoleController::class, 'detach'])->name('users.roles.detach');
            });
        });
    });
