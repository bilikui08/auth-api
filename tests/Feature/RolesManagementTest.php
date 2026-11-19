<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Pantalla de roles de la administración del sistema: el uso de cada rol en las empresas y las protecciones que evitan dejar a
 * alguien sin acceso (borrar un rol en uso, vaciarlo de permisos) o darle permisos de empresa a los roles globales.
 */
class RolesManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('name', 'admin')->value('id'));
        Passport::actingAs($admin);

        return $admin;
    }

    private function cashier(): Role
    {
        $role = Role::create(['name' => 'cajero', 'description' => 'Cajero: cobra']);
        $role->permissions()->sync(Permission::whereIn('name', ['sales.invoice.view', 'sales.invoice.issue'])->pluck('id'));

        return $role;
    }

    /** Pone a una persona en una empresa con ese rol. */
    private function member(Organization $organization, Role $role, ?User $user = null): User
    {
        $user ??= User::factory()->create();
        $organization->users()->attach($user->id, ['role_id' => $role->id]);

        return $user;
    }

    // ------------------------------------------------------------------ uso de cada rol

    public function test_the_list_tells_how_many_people_and_companies_use_each_role(): void
    {
        $this->admin();
        $cashier = $this->cashier();
        $a = Organization::create(['name' => 'A']);
        $b = Organization::create(['name' => 'B']);
        $ana = $this->member($a, $cashier);
        $this->member($a, $cashier);
        $this->member($b, $cashier, $ana);              // la misma persona en otra empresa
        $this->member($a, Role::where('name', 'viewer')->first());

        $roles = collect($this->getJson('/api/auth/roles')->assertOk()->json('data'))->keyBy('name');

        $this->assertSame(2, $roles['cajero']['members_count'], 'personas distintas');
        $this->assertSame(2, $roles['cajero']['organizations_count']);
        $this->assertSame(1, $roles['viewer']['members_count']);
        $this->assertSame(0, $roles['seller']['members_count']);
        $this->assertSame(['sales.invoice.issue', 'sales.invoice.view'], $roles['cajero']['permissions']);
    }

    public function test_show_create_and_update_also_report_the_usage(): void
    {
        $this->admin();
        $cashier = $this->cashier();
        $this->member(Organization::create(['name' => 'A']), $cashier);

        $this->getJson("/api/auth/roles/{$cashier->id}")->assertOk()->assertJsonPath('data.members_count', 1)->assertJsonPath('data.organizations_count', 1);
        $this->putJson("/api/auth/roles/{$cashier->id}", ['description' => 'Cajero: nuevo'])->assertOk()->assertJsonPath('data.members_count', 1);
        $this->postJson('/api/auth/roles', ['name' => 'nuevo', 'permissions' => ['sales.customer.view']])->assertCreated()
            ->assertJsonPath('data.members_count', 0)->assertJsonPath('data.permissions', ['sales.customer.view']);
    }

    // ------------------------------------------------------------------ no se borra lo que está en uso

    public function test_a_role_in_use_cannot_be_deleted_and_nobody_loses_their_company(): void
    {
        $this->admin();
        $cashier = $this->cashier();
        $company = Organization::create(['name' => 'A']);
        $ana = $this->member($company, $cashier);
        $this->member($company, $cashier);

        $this->deleteJson("/api/auth/roles/{$cashier->id}")->assertUnprocessable()
            ->assertJsonPath('message', 'El rol «cajero» lo usan 2 personas en 1 empresa: cambiales el rol antes de borrarlo, o quedarían fuera de la empresa.');

        $this->assertNotNull(Role::find($cashier->id));
        $this->assertTrue($company->users()->where('users.id', $ana->id)->exists(), 'sigue en su empresa');
        $this->assertSame(2, DB::table('organization_user')->where('role_id', $cashier->id)->count());
    }

    public function test_the_message_is_singular_for_one_person_and_one_company(): void
    {
        $this->admin();
        $cashier = $this->cashier();
        $this->member(Organization::create(['name' => 'A']), $cashier);

        $this->deleteJson("/api/auth/roles/{$cashier->id}")->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, '1 persona en 1 empresa'));
    }

    public function test_once_nobody_uses_it_the_role_can_be_deleted(): void
    {
        $this->admin();
        $cashier = $this->cashier();
        $company = Organization::create(['name' => 'A']);
        $ana = $this->member($company, $cashier);

        $company->users()->detach($ana->id);

        $this->deleteJson("/api/auth/roles/{$cashier->id}")->assertOk();
        $this->assertNull(Role::find($cashier->id));
    }

    public function test_a_role_nobody_uses_is_deleted_even_if_it_was_assigned_globally(): void
    {
        $this->admin();
        $editor = Role::create(['name' => 'editor']);
        User::factory()->create()->roles()->attach($editor);

        $this->deleteJson("/api/auth/roles/{$editor->id}")->assertOk();
    }

    // ------------------------------------------------------------------ no se vacía un rol en uso

    public function test_a_role_in_use_cannot_lose_all_its_permissions(): void
    {
        $this->admin();
        $cashier = $this->cashier();
        $this->member(Organization::create(['name' => 'A']), $cashier);

        $this->putJson("/api/auth/roles/{$cashier->id}", ['permissions' => []])->assertUnprocessable()
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'está en uso: no se le pueden quitar todos los permisos'));
        $this->assertSame(2, $cashier->permissions()->count());

        // cambiarlos por otros sí se puede
        $this->putJson("/api/auth/roles/{$cashier->id}", ['permissions' => ['sales.customer.view']])->assertOk()->assertJsonPath('data.permissions', ['sales.customer.view']);
    }

    public function test_a_role_nobody_uses_can_be_emptied(): void
    {
        $this->admin();
        $cashier = $this->cashier();

        $this->putJson("/api/auth/roles/{$cashier->id}", ['permissions' => []])->assertOk()->assertJsonPath('data.permissions', []);
    }

    // ------------------------------------------------------------------ los roles globales no llevan permisos de empresa

    public function test_admin_and_user_cannot_receive_company_permissions(): void
    {
        $this->admin();

        foreach (['admin', 'user'] as $name) {
            $role = Role::where('name', $name)->firstOrFail();
            $this->putJson("/api/auth/roles/{$role->id}", ['permissions' => ['sales.invoice.view']])->assertUnprocessable()
                ->assertJsonPath('message', "«{$name}» es un rol global de la plataforma: no lleva permisos de empresa.");
            $this->assertSame(0, $role->permissions()->count());
        }
    }

    public function test_admin_and_user_can_still_have_their_description_changed_and_an_empty_list_is_fine(): void
    {
        $this->admin();
        $role = Role::where('name', 'user')->firstOrFail();

        $this->putJson("/api/auth/roles/{$role->id}", ['description' => 'Cuenta estándar'])->assertOk();
        $this->putJson("/api/auth/roles/{$role->id}", ['permissions' => []])->assertOk();
    }

    public function test_the_company_owner_role_stays_locked_even_with_the_new_rules(): void
    {
        $this->admin();
        $owner = Role::where('name', 'company_admin')->firstOrFail();

        $this->putJson("/api/auth/roles/{$owner->id}", ['permissions' => []])->assertUnprocessable();
        $this->putJson("/api/auth/roles/{$owner->id}", ['permissions' => ['sales.invoice.view']])->assertUnprocessable();
        $this->assertSame(Permission::count(), $owner->permissions()->count());
    }

    public function test_none_of_this_is_available_to_non_admins(): void
    {
        Passport::actingAs(User::factory()->create());
        $role = $this->cashier();

        $this->getJson('/api/auth/roles')->assertForbidden();
        $this->deleteJson("/api/auth/roles/{$role->id}")->assertForbidden();
        $this->putJson("/api/auth/roles/{$role->id}", ['permissions' => []])->assertForbidden();
    }
}
