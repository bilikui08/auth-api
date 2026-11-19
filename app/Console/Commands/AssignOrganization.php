<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Util hasta que exista la administración desde la pantalla:
 *   php artisan org:assign tu@mail.com 3 seller        (le da el rol en la empresa; crea la pertenencia si no existe)
 *   php artisan org:assign tu@mail.com 3 seller --revoke
 */
final class AssignOrganization extends Command
{
    protected $signature = 'org:assign {email : Email del usuario} {organization : Id de la empresa} {role : Nombre del rol} {--revoke : Retira el rol en lugar de asignarlo}';

    protected $description = 'Asigna (o retira con --revoke) un rol de empresa a un usuario';

    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();
        $organization = Organization::query()->find($this->argument('organization'));
        $role = Role::query()->where('name', $this->argument('role'))->first();

        if ($user === null || $organization === null || $role === null) {
            $this->error($user === null ? 'Usuario no encontrado.' : ($organization === null ? 'Empresa no encontrada.' : 'Rol no encontrado.'));

            return self::FAILURE;
        }

        if ($this->option('revoke')) {
            $organization->users()->wherePivot('user_id', $user->id)->wherePivot('role_id', $role->id)->detach($user->id);
        } else {
            $exists = $organization->users()->wherePivot('user_id', $user->id)->wherePivot('role_id', $role->id)->exists();
            if (! $exists) {
                $organization->users()->attach($user->id, ['role_id' => $role->id]);
            }
        }

        $roles = $user->organizations()->whereKey($organization->id)->get()->map(fn ($o) => Role::query()->find($o->pivot->role_id)?->name)->all();
        $this->info(sprintf('%s en «%s»: %s', $user->email, $organization->name, implode(', ', $roles) ?: '(sin roles: ya no pertenece)'));

        return self::SUCCESS;
    }
}
