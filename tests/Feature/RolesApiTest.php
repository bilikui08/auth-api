<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class RolesApiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('name', 'admin')->value('id'));
        Passport::actingAs($admin);

        return $admin;
    }

    private function role(string $name): Role
    {
        return Role::create(['name' => $name]);
    }

    // ---------- esquema / roles del sistema ----------

    public function test_system_roles_exist_after_migrating(): void
    {
        $this->assertEqualsCanonicalizing(['admin', 'user', 'company_admin', 'seller', 'accountant', 'viewer'], Role::pluck('name')->all());
    }

    // ---------- acceso ----------

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/auth/roles')->assertUnauthorized();
    }

    public function test_non_admins_cannot_manage_roles(): void
    {
        Passport::actingAs(User::factory()->create());
        $role = $this->role('editor');
        $target = User::factory()->create();

        $this->getJson('/api/auth/roles')->assertForbidden()->assertJsonPath('success', false);
        $this->postJson('/api/auth/roles', ['name' => 'x'])->assertForbidden();
        $this->putJson("/api/auth/roles/{$role->id}", ['description' => 'x'])->assertForbidden();
        $this->deleteJson("/api/auth/roles/{$role->id}")->assertForbidden();
        $this->putJson("/api/auth/users/{$target->id}/roles", ['roles' => ['admin']])->assertForbidden();
        $this->assertSame([], $target->roleNames());
    }

    // ---------- CRUD de roles ----------

    public function test_admin_can_list_roles_with_user_counts(): void
    {
        $admin = $this->admin();
        $this->role('editor')->users()->attach($admin);

        $this->getJson('/api/auth/roles')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(Role::count(), 'data')
            ->assertJsonFragment(['name' => 'editor', 'users_count' => 1, 'is_system' => false]);
    }

    public function test_admin_can_create_show_update_and_delete_a_role(): void
    {
        $this->admin();

        $id = $this->postJson('/api/auth/roles', ['name' => 'editor', 'description' => 'Edita contenido'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'editor')
            ->assertJsonPath('data.is_system', false)
            ->json('data.id');

        $this->getJson("/api/auth/roles/{$id}")->assertOk()->assertJsonPath('data.description', 'Edita contenido');

        $this->putJson("/api/auth/roles/{$id}", ['name' => 'editor-jefe', 'description' => 'Nuevo'])
            ->assertOk()
            ->assertJsonPath('data.name', 'editor-jefe');

        $this->deleteJson("/api/auth/roles/{$id}")->assertOk()->assertJsonPath('data', null);
        $this->getJson("/api/auth/roles/{$id}")->assertNotFound()->assertJsonPath('success', false);
    }

    public function test_role_names_are_validated(): void
    {
        $this->admin();
        $this->role('editor');

        $this->postJson('/api/auth/roles', ['name' => 'editor'])->assertUnprocessable()->assertJsonValidationErrors('name', 'errors');
        $this->postJson('/api/auth/roles', ['name' => 'Con Espacios'])->assertUnprocessable();
        $this->postJson('/api/auth/roles', ['name' => str_repeat('a', 51)])->assertUnprocessable();
        $this->postJson('/api/auth/roles', [])->assertUnprocessable();
    }

    public function test_updating_a_role_may_keep_its_own_name(): void
    {
        $this->admin();
        $role = $this->role('editor');

        $this->putJson("/api/auth/roles/{$role->id}", ['name' => 'editor', 'description' => 'ok'])->assertOk();
    }

    public function test_system_roles_cannot_be_renamed_or_deleted(): void
    {
        $this->admin();
        $adminRole = Role::where('name', 'admin')->first();

        $this->putJson("/api/auth/roles/{$adminRole->id}", ['name' => 'root'])->assertUnprocessable();
        $this->deleteJson("/api/auth/roles/{$adminRole->id}")->assertUnprocessable();
        // La descripcion si se puede cambiar.
        $this->putJson("/api/auth/roles/{$adminRole->id}", ['description' => 'Super'])->assertOk();
        $this->assertDatabaseHas('roles', ['name' => 'admin', 'description' => 'Super']);
    }

    // ---------- relacion N:N ----------

    public function test_a_user_can_have_many_roles_and_a_role_many_users(): void
    {
        $this->admin();
        $editor = $this->role('editor');
        $auditor = $this->role('auditor');
        [$ana, $bob] = User::factory()->count(2)->create();

        $this->putJson("/api/auth/users/{$ana->id}/roles", ['roles' => ['editor', 'auditor']])
            ->assertOk()
            ->assertJsonPath('data.roles', ['auditor', 'editor']);
        $this->putJson("/api/auth/users/{$bob->id}/roles", ['roles' => ['editor']])->assertOk();

        $this->assertSame(['auditor', 'editor'], $ana->roleNames());
        $this->assertSame(2, $editor->users()->count());
        $this->assertSame(1, $auditor->users()->count());
    }

    public function test_sync_replaces_roles_and_validates_names(): void
    {
        $this->admin();
        $this->role('editor');
        $this->role('auditor');
        $ana = User::factory()->create();

        $this->putJson("/api/auth/users/{$ana->id}/roles", ['roles' => ['editor']])->assertOk();
        $this->putJson("/api/auth/users/{$ana->id}/roles", ['roles' => ['auditor']])->assertOk();
        $this->assertSame(['auditor'], $ana->roleNames());

        $this->putJson("/api/auth/users/{$ana->id}/roles", ['roles' => ['no-existe']])->assertUnprocessable();
        $this->putJson("/api/auth/users/{$ana->id}/roles", ['roles' => ['auditor', 'auditor']])->assertUnprocessable();
        $this->putJson("/api/auth/users/{$ana->id}/roles", [])->assertUnprocessable();
        $this->putJson("/api/auth/users/{$ana->id}/roles", ['roles' => []])->assertOk();
        $this->assertSame([], $ana->roleNames());
    }

    public function test_attach_and_detach_a_single_role(): void
    {
        $this->admin();
        $editor = $this->role('editor');
        $ana = User::factory()->create();

        $this->postJson("/api/auth/users/{$ana->id}/roles/{$editor->id}")->assertOk()->assertJsonPath('data.roles', ['editor']);
        // Idempotente: no duplica.
        $this->postJson("/api/auth/users/{$ana->id}/roles/{$editor->id}")->assertOk();
        $this->assertDatabaseCount('role_user', 2); // admin->admin + ana->editor

        $this->getJson("/api/auth/users/{$ana->id}/roles")->assertOk()->assertJsonPath('data.roles', ['editor']);
        $this->deleteJson("/api/auth/users/{$ana->id}/roles/{$editor->id}")->assertOk()->assertJsonPath('data.roles', []);
    }

    public function test_unknown_user_or_role_returns_404(): void
    {
        $this->admin();
        $editor = $this->role('editor');

        $this->getJson('/api/auth/users/9999/roles')->assertNotFound();
        $this->postJson("/api/auth/users/9999/roles/{$editor->id}")->assertNotFound();
        $this->postJson('/api/auth/users/'.User::factory()->create()->id.'/roles/9999')->assertNotFound();
    }

    public function test_deleting_a_role_detaches_it_from_its_users(): void
    {
        $this->admin();
        $editor = $this->role('editor');
        $ana = User::factory()->create();
        $ana->roles()->attach($editor);

        $this->deleteJson("/api/auth/roles/{$editor->id}")->assertOk();

        $this->assertSame([], $ana->roleNames());
        $this->assertDatabaseMissing('role_user', ['role_id' => $editor->id]);
    }

    public function test_deleting_a_user_removes_the_pivot_rows_but_not_the_role(): void
    {
        $editor = $this->role('editor');
        $ana = User::factory()->create();
        $ana->roles()->attach($editor);

        $ana->delete();

        $this->assertDatabaseMissing('role_user', ['user_id' => $ana->id]);
        $this->assertDatabaseHas('roles', ['name' => 'editor']);
    }

    // ---------- proteccion del ultimo admin ----------

    public function test_the_last_admin_cannot_lose_the_admin_role(): void
    {
        $admin = $this->admin();
        $adminRole = Role::where('name', 'admin')->first();

        $this->deleteJson("/api/auth/users/{$admin->id}/roles/{$adminRole->id}")->assertUnprocessable();
        $this->putJson("/api/auth/users/{$admin->id}/roles", ['roles' => ['user']])->assertUnprocessable();
        $this->assertSame(['admin'], $admin->roleNames());
    }

    public function test_an_admin_can_be_demoted_when_another_admin_exists(): void
    {
        $admin = $this->admin();
        $other = User::factory()->create();
        $other->roles()->attach(Role::where('name', 'admin')->value('id'));

        $this->putJson("/api/auth/users/{$admin->id}/roles", ['roles' => ['user']])->assertOk();
        $this->assertSame(['user'], $admin->roleNames());
    }
}
