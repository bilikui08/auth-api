<?php

namespace Tests\Feature;

use App\Application\Organizations\PermissionCatalog;
use App\Auth\Passport\SelectionTokens;
use App\Domain\Auth\Contracts\TokenRepository;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesRealPassport;
use Tests\TestCase;

/**
 * Administración de empresas: el administrador de una empresa gestiona sus personas y roles; el administrador del sistema
 * gestiona todas las empresas. Con tokens reales de Passport (la empresa activa sale del token).
 */
class OrganizationAdminTest extends TestCase
{
    use RefreshDatabase;
    use UsesRealPassport;

    private User $ana;      // administradora de la empresa A
    private User $beto;     // vendedor de la empresa A
    private User $carla;    // administradora de la empresa B
    private User $root;     // administrador del sistema
    private Organization $a;
    private Organization $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPassport();

        $this->ana = $this->user('ana');
        $this->beto = $this->user('beto');
        $this->carla = $this->user('carla');
        $this->root = $this->user('root');
        $this->root->roles()->attach(Role::where('name', 'admin')->value('id'));

        $this->a = $this->company('Empresa A', [[$this->ana, 'company_admin'], [$this->beto, 'seller']]);
        $this->b = $this->company('Empresa B', [[$this->carla, 'company_admin']]);
        $this->company('Sistema', [[$this->root, 'company_admin']]);
    }

    protected function tearDown(): void
    {
        $this->tearDownPassport();
        parent::tearDown();
    }

    private function user(string $name): User
    {
        return User::factory()->create(['name' => $name, 'email' => "{$name}@example.com"]);
    }

    /** @param list<array{User, string}> $members */
    private function company(string $name, array $members): Organization
    {
        $organization = Organization::create(['name' => $name]);
        foreach ($members as [$user, $role]) {
            $organization->users()->attach($user->id, ['role_id' => Role::where('name', $role)->value('id')]);
        }

        return $organization;
    }

    /** Access token real de la persona trabajando con esa empresa. */
    private function token(User $user, Organization $organization): string
    {
        return app(TokenRepository::class)
            ->issueForOrganization(app(SelectionTokens::class)->create($user->id), $organization->id)->accessToken;
    }

    private function as(User $user, ?Organization $organization = null)
    {
        $organization ??= $user->organizations()->orderBy('organizations.id')->first();

        // El guard conserva al usuario del pedido anterior: en cada pedido real arranca de cero
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->token($user, $organization));
    }

    /** @return list<string> */
    private function rolesOf(Organization $organization, User $user): array
    {
        return \DB::table('organization_user')->join('roles', 'roles.id', '=', 'organization_user.role_id')
            ->where('organization_id', $organization->id)->where('user_id', $user->id)->orderBy('roles.name')->pluck('roles.name')->all();
    }

    // ------------------------------------------------------------------ catálogo

    public function test_the_company_admin_role_has_the_members_permission_and_sync_is_idempotent(): void
    {
        $admin = Role::where('name', 'company_admin')->firstOrFail();
        $this->assertTrue($admin->permissions()->where('name', 'org.members.manage')->exists());
        $this->assertFalse(Role::where('name', 'seller')->firstOrFail()->permissions()->where('name', 'org.members.manage')->exists());

        $before = \DB::table('permission_role')->count();
        PermissionCatalog::sync();
        $this->assertSame($before, \DB::table('permission_role')->count());
    }

    // ------------------------------------------------------------------ acceso

    public function test_only_people_with_the_members_permission_can_manage_a_company(): void
    {
        $this->getJson('/api/auth/organization/members')->assertUnauthorized();

        $this->as($this->beto)->getJson('/api/auth/organization/members')->assertForbidden();
        $this->as($this->beto)->postJson('/api/auth/organization/members', ['email' => 'carla@example.com', 'roles' => ['viewer']])->assertForbidden();
        $this->as($this->beto)->putJson("/api/auth/organization/members/{$this->beto->id}", ['roles' => ['company_admin']])->assertForbidden();
        $this->as($this->beto)->deleteJson("/api/auth/organization/members/{$this->ana->id}")->assertForbidden();

        $this->as($this->ana)->getJson('/api/auth/organization/members')->assertOk();
        $this->assertSame(['seller'], $this->rolesOf($this->a, $this->beto), 'nada cambió');
    }

    public function test_the_permission_is_read_from_the_database_so_a_change_applies_immediately(): void
    {
        $token = $this->token($this->ana, $this->a);
        $this->withToken($token)->getJson('/api/auth/organization/members')->assertOk();

        // Se le quita el rol (hay otra administradora): el MISMO token deja de servir al instante
        $this->a->users()->attach($this->carla->id, ['role_id' => Role::where('name', 'company_admin')->value('id')]);
        \DB::table('organization_user')->where('organization_id', $this->a->id)->where('user_id', $this->ana->id)->delete();
        $this->a->users()->attach($this->ana->id, ['role_id' => Role::where('name', 'viewer')->value('id')]);

        $this->withToken($token)->getJson('/api/auth/organization/members')->assertForbidden();
    }

    public function test_an_inactive_company_cannot_be_managed(): void
    {
        $token = $this->token($this->ana, $this->a);
        $this->a->update(['is_active' => false]);

        $this->withToken($token)->getJson('/api/auth/organization')->assertForbidden();
    }

    // ------------------------------------------------------------------ personas de la empresa

    public function test_it_lists_the_people_of_the_active_company_with_their_roles(): void
    {
        $this->a->users()->attach($this->beto->id, ['role_id' => Role::where('name', 'viewer')->value('id')]);

        $members = $this->as($this->ana)->getJson('/api/auth/organization/members')->assertOk()->json('data');

        $this->assertSame(['ana', 'beto'], array_column($members, 'name'), 'solo las de la empresa activa');
        $this->assertSame(['seller', 'viewer'], $members[1]['roles'], 'una persona con dos roles aparece una vez');
        $this->assertSame('beto@example.com', $members[1]['email']);
        $this->assertSame($this->a->id, $this->as($this->ana)->getJson('/api/auth/organization')->json('data.id'));
    }

    public function test_it_adds_an_existing_user_with_roles(): void
    {
        $dani = $this->user('dani');

        $members = $this->as($this->ana)->postJson('/api/auth/organization/members', ['email' => 'dani@example.com', 'roles' => ['viewer', 'accountant']])
            ->assertCreated()->json('data');

        $this->assertContains('dani', array_column($members, 'name'));
        $this->assertSame(['accountant', 'viewer'], $this->rolesOf($this->a, $dani));
        $this->assertSame([], $this->rolesOf($this->b, $dani), 'solo en la empresa activa');
    }

    public function test_adding_validates_the_user_the_roles_and_duplicates(): void
    {
        $this->user('dani');
        $ana = fn () => $this->as($this->ana);

        $ana()->postJson('/api/auth/organization/members', ['email' => 'nadie@example.com', 'roles' => ['viewer']])
            ->assertUnprocessable()->assertJsonPath('errors.email.0', 'No hay ningún usuario con ese email: la persona debe registrarse primero.');
        $ana()->postJson('/api/auth/organization/members', ['email' => 'beto@example.com', 'roles' => ['viewer']])
            ->assertUnprocessable()->assertJsonPath('message', 'Esa persona ya pertenece a la empresa.');
        $ana()->postJson('/api/auth/organization/members', ['email' => 'dani@example.com', 'roles' => []])->assertUnprocessable();
        $ana()->postJson('/api/auth/organization/members', ['email' => 'dani@example.com'])->assertUnprocessable();

        // Los roles globales no se asignan en una empresa, ni los que no existen
        foreach (['admin', 'user', 'inventado'] as $role) {
            $ana()->postJson('/api/auth/organization/members', ['email' => 'dani@example.com', 'roles' => [$role]])->assertUnprocessable();
        }
        $this->assertSame([], $this->rolesOf($this->a, User::where('email', 'dani@example.com')->first()));
    }

    public function test_it_replaces_the_roles_of_a_member(): void
    {
        $this->as($this->ana)->putJson("/api/auth/organization/members/{$this->beto->id}", ['roles' => ['accountant', 'viewer']])->assertOk();

        $this->assertSame(['accountant', 'viewer'], $this->rolesOf($this->a, $this->beto));
        $this->as($this->ana)->putJson("/api/auth/organization/members/{$this->beto->id}", ['roles' => []])->assertUnprocessable();
        $this->as($this->ana)->putJson("/api/auth/organization/members/{$this->beto->id}", ['roles' => ['admin']])->assertUnprocessable();
        $this->assertSame(['accountant', 'viewer'], $this->rolesOf($this->a, $this->beto), 'un pedido inválido no cambia nada');
    }

    public function test_the_company_always_keeps_one_administrator(): void
    {
        $this->as($this->ana)->putJson("/api/auth/organization/members/{$this->ana->id}", ['roles' => ['seller']])
            ->assertUnprocessable()->assertJsonPath('message', 'La empresa debe tener al menos un administrador: asigná ese rol a otra persona antes.');
        $this->as($this->ana)->deleteJson("/api/auth/organization/members/{$this->ana->id}")->assertUnprocessable();
        $this->assertSame(['company_admin'], $this->rolesOf($this->a, $this->ana));

        // Con una segunda administradora ya se puede
        $this->as($this->ana)->putJson("/api/auth/organization/members/{$this->beto->id}", ['roles' => ['company_admin']])->assertOk();
        $this->as($this->ana)->putJson("/api/auth/organization/members/{$this->ana->id}", ['roles' => ['seller']])->assertOk();
        $this->assertSame(['seller'], $this->rolesOf($this->a, $this->ana));

        // ...y el último que queda tampoco se puede quitar
        $this->as($this->beto, $this->a)->deleteJson("/api/auth/organization/members/{$this->beto->id}")->assertUnprocessable();
    }

    public function test_removing_a_member_ends_their_access_to_the_company(): void
    {
        $login = app(SelectionTokens::class)->create($this->beto->id);
        $tokens = app(TokenRepository::class)->issueForOrganization($login, $this->a->id);

        $this->as($this->ana)->deleteJson("/api/auth/organization/members/{$this->beto->id}")->assertOk();

        $this->assertSame([], $this->rolesOf($this->a, $this->beto));
        $this->postJson('/api/auth/refresh', ['refresh_token' => $tokens->refreshToken])->assertUnauthorized();
        $this->postJson('/api/auth/login', ['email' => 'beto@example.com', 'password' => 'password'])
            ->assertForbidden()->assertJsonPath('message', 'Tu usuario no tiene empresas asignadas. Contactá al administrador.');
    }

    public function test_a_company_admin_cannot_touch_people_of_another_company(): void
    {
        // carla es de B: no es miembro de A
        $this->as($this->ana)->putJson("/api/auth/organization/members/{$this->carla->id}", ['roles' => ['viewer']])
            ->assertUnprocessable()->assertJsonPath('message', 'Esa persona no pertenece a la empresa.');
        $this->as($this->ana)->deleteJson("/api/auth/organization/members/{$this->carla->id}")->assertUnprocessable();
        $this->as($this->ana)->deleteJson('/api/auth/organization/members/99999')->assertNotFound();

        $this->assertSame(['company_admin'], $this->rolesOf($this->b, $this->carla));
        $this->assertSame(['carla'], array_column($this->as($this->carla)->getJson('/api/auth/organization/members')->json('data'), 'name'));
    }

    public function test_a_person_in_two_companies_manages_only_the_active_one(): void
    {
        $this->b->users()->attach($this->ana->id, ['role_id' => Role::where('name', 'company_admin')->value('id')]);

        $inB = $this->as($this->ana, $this->b)->getJson('/api/auth/organization/members')->assertOk()->json('data');
        $this->assertSame(['ana', 'carla'], array_column($inB, 'name'));

        $this->as($this->ana, $this->a)->postJson('/api/auth/organization/members', ['email' => 'carla@example.com', 'roles' => ['viewer']])->assertCreated();
        $this->assertSame(['viewer'], $this->rolesOf($this->a, $this->carla));
        $this->assertSame(['company_admin'], $this->rolesOf($this->b, $this->carla), 'B no se tocó');
    }

    public function test_it_lists_the_assignable_roles_with_their_permissions(): void
    {
        $roles = $this->as($this->ana)->getJson('/api/auth/organization/roles')->assertOk()->json('data');
        $names = array_column($roles, 'name');

        $this->assertEqualsCanonicalizing(['company_admin', 'seller', 'accountant', 'viewer'], $names);
        $this->assertNotContains('admin', $names);
        $seller = collect($roles)->firstWhere('name', 'seller');
        $this->assertContains('sales.invoice.issue', array_column($seller['permissions'], 'name'));
        $this->assertNotEmpty($seller['permissions'][0]['description']);
    }

    // ------------------------------------------------------------------ administrador del sistema

    public function test_only_the_system_admin_manages_all_the_companies(): void
    {
        $this->as($this->ana)->getJson('/api/auth/admin/organizations')->assertForbidden();
        $this->as($this->ana)->postJson('/api/auth/admin/organizations', ['name' => 'X', 'admin_email' => 'ana@example.com'])->assertForbidden();
        $this->as($this->ana)->putJson("/api/auth/admin/organizations/{$this->b->id}", ['name' => 'Robada'])->assertForbidden();
        $this->as($this->ana)->getJson('/api/auth/admin/users')->assertForbidden();

        $list = $this->as($this->root)->getJson('/api/auth/admin/organizations')->assertOk()->json('data');
        $this->assertSame(['Empresa A', 'Empresa B', 'Sistema'], array_column($list, 'name'));
        $this->assertSame([2, 1, 1], array_column($list, 'members_count'));
    }

    public function test_the_system_admin_creates_a_company_with_its_first_administrator(): void
    {
        $response = $this->as($this->root)->postJson('/api/auth/admin/organizations', ['name' => 'Nueva SRL', 'tax_id' => '30711111119', 'admin_email' => 'beto@example.com'])
            ->assertCreated();

        $response->assertJsonPath('data.is_active', true);
        $new = Organization::findOrFail($response->json('data.id'));
        $this->assertSame(['company_admin'], $this->rolesOf($new, $this->beto));

        // beto ahora tiene dos empresas: el login le pide elegir
        $this->postJson('/api/auth/login', ['email' => 'beto@example.com', 'password' => 'password'])
            ->assertOk()->assertJsonPath('data.requires_organization', true)->assertJsonCount(2, 'data.organizations');
    }

    public function test_creating_a_company_validates_its_data(): void
    {
        $root = fn () => $this->as($this->root);

        $root()->postJson('/api/auth/admin/organizations', [])->assertUnprocessable();
        $root()->postJson('/api/auth/admin/organizations', ['name' => 'X', 'admin_email' => 'no-es-un-email'])->assertUnprocessable();
        $this->a->update(['tax_id' => '30711111119']);
        $root()->postJson('/api/auth/admin/organizations', ['name' => 'X', 'tax_id' => '30711111119', 'admin_email' => 'ana@example.com'])
            ->assertUnprocessable()->assertJsonValidationErrors('tax_id');
        $this->assertSame(0, Organization::where('name', 'X')->count());
    }

    public function test_the_system_admin_can_rename_and_deactivate_a_company(): void
    {
        $login = app(SelectionTokens::class)->create($this->carla->id);
        $tokens = app(TokenRepository::class)->issueForOrganization($login, $this->b->id);

        $this->as($this->root)->putJson("/api/auth/admin/organizations/{$this->b->id}", ['name' => 'B Renombrada'])->assertOk()->assertJsonPath('data.name', 'B Renombrada');
        $this->as($this->root)->putJson("/api/auth/admin/organizations/{$this->b->id}", ['is_active' => false])->assertOk();

        // Nadie más puede entrar a esa empresa
        $this->postJson('/api/auth/login', ['email' => 'carla@example.com', 'password' => 'password'])->assertForbidden();
        $this->postJson('/api/auth/refresh', ['refresh_token' => $tokens->refreshToken])->assertUnauthorized();

        $this->as($this->root)->putJson("/api/auth/admin/organizations/{$this->b->id}", ['is_active' => true])->assertOk();
        $this->postJson('/api/auth/login', ['email' => 'carla@example.com', 'password' => 'password'])->assertOk();
    }

    public function test_the_system_admin_can_search_users(): void
    {
        $found = $this->as($this->root)->getJson('/api/auth/admin/users?search=ana')->assertOk()->json('data');
        $this->assertSame(['ana'], array_column($found, 'name'));
        $this->assertArrayNotHasKey('password', $found[0]);
        $this->assertCount(4, $this->as($this->root)->getJson('/api/auth/admin/users')->json('data'));
        $this->assertSame([], $this->as($this->root)->getJson('/api/auth/admin/users?search=%25')->json('data'), 'el comodín % es literal');
    }

    // ------------------------------------------------------------------ roles con permisos (administrador del sistema)

    public function test_the_system_admin_builds_roles_from_the_permission_catalog(): void
    {
        $catalog = $this->as($this->root)->getJson('/api/auth/permissions')->assertOk()->json('data');
        $this->assertContains('org.members.manage', array_column($catalog, 'name'));

        $this->as($this->root)->postJson('/api/auth/roles', ['name' => 'facturador', 'description' => 'Solo factura', 'permissions' => ['sales.invoice.issue', 'sales.invoice.view']])
            ->assertCreated()->assertJsonPath('data.permissions', ['sales.invoice.issue', 'sales.invoice.view']);

        // ya se puede asignar en una empresa, y sus permisos llegan al token
        $this->as($this->ana)->putJson("/api/auth/organization/members/{$this->beto->id}", ['roles' => ['facturador']])->assertOk();
        $claims = json_decode(base64_decode(strtr(explode('.', $this->token($this->beto, $this->a))[1], '-_', '+/')), true);
        $this->assertSame(['sales.invoice.issue', 'sales.invoice.view'], $claims['permissions']);

        $role = Role::where('name', 'facturador')->firstOrFail();
        $this->as($this->root)->putJson("/api/auth/roles/{$role->id}", ['permissions' => ['sales.invoice.view']])->assertOk()->assertJsonPath('data.permissions', ['sales.invoice.view']);
    }

    public function test_a_role_without_permissions_cannot_be_assigned_in_a_company(): void
    {
        Role::create(['name' => 'vacio']);

        $this->as($this->ana)->putJson("/api/auth/organization/members/{$this->beto->id}", ['roles' => ['vacio']])->assertUnprocessable();
        $this->assertNotContains('vacio', array_column($this->as($this->ana)->getJson('/api/auth/organization/roles')->json('data'), 'name'));
    }

    public function test_the_company_owner_role_keeps_all_its_permissions_and_permissions_are_validated(): void
    {
        $admin = Role::where('name', 'company_admin')->firstOrFail();

        $this->as($this->root)->putJson("/api/auth/roles/{$admin->id}", ['permissions' => ['sales.invoice.view']])
            ->assertUnprocessable()->assertJsonPath('message', 'Los permisos del rol «company_admin» no se pueden modificar: es el rol del dueño de la empresa.');
        $this->assertSame(Permission::count(), $admin->permissions()->count());

        $this->as($this->root)->postJson('/api/auth/roles', ['name' => 'x1', 'permissions' => ['no.existe']])->assertUnprocessable();
        $this->as($this->ana)->postJson('/api/auth/roles', ['name' => 'x2'])->assertForbidden();
    }
}
