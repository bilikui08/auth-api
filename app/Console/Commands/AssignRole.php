<?php

namespace App\Console\Commands;

use App\Application\Roles\RoleService;
use App\Domain\Roles\Exceptions\RoleOperationNotAllowed;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;

/** Util para crear el primer administrador: php artisan auth:role tu@mail.com admin */
final class AssignRole extends Command
{
    protected $signature = 'auth:role {email : Email del usuario} {role : Nombre del rol} {--revoke : Retira el rol en lugar de asignarlo}';

    protected $description = 'Asigna (o retira con --revoke) un rol a un usuario';

    public function handle(RoleService $roles): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();
        $role = Role::query()->where('name', $this->argument('role'))->first();

        if ($user === null || $role === null) {
            $this->error($user === null ? 'Usuario no encontrado.' : 'Rol no encontrado.');

            return self::FAILURE;
        }

        try {
            $this->option('revoke') ? $roles->detach($user, $role) : $roles->attach($user, $role);
        } catch (RoleOperationNotAllowed $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('%s: %s', $user->email, implode(', ', $user->roleNames()) ?: '(sin roles)'));

        return self::SUCCESS;
    }
}
