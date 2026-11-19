<?php

namespace Tests\Feature;

use App\Auth\Passport\SelectionTokens;
use App\Auth\Passport\TokenContext;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UsesRealPassport;
use Tests\TestCase;

/**
 * Login con empresas, con el Passport REAL (tokens firmados y decodificados): una empresa entra directo, varias
 * obligan a elegir, los tokens llevan `org` y `permissions`, el refresh conserva la empresa y se puede cambiar.
 */
class OrganizationsLoginTest extends TestCase
{
    use RefreshDatabase;

    use UsesRealPassport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPassport();
    }

    protected function tearDown(): void
    {
        $this->tearDownPassport();

        parent::tearDown();
    }

    private function user(string $email = 'ana@example.com'): User
    {
        // El nombre es único: el de cada usuario sale de su email
        return User::factory()->create(['email' => $email, 'name' => ucfirst(strstr($email, '@', true))]);
    }

    private function join(User $user, string $company, string $role = 'company_admin', bool $active = true): Organization
    {
        $organization = Organization::firstOrCreate(['name' => $company], ['is_active' => $active]);
        $organization->users()->attach($user->id, ['role_id' => Role::where('name', $role)->value('id')]);

        return $organization;
    }

    private function login(string $email = 'ana@example.com'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password']);
    }

    /** @return array<string, mixed> */
    private function claims(string $jwt): array
    {
        $payload = explode('.', $jwt)[1];

        return json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
    }

    // ------------------------------------------------------------------ una sola empresa

    public function test_a_user_with_one_company_logs_in_directly_with_org_and_permissions_in_the_token(): void
    {
        $ana = $this->user();
        $company = $this->join($ana, 'Ana SRL');

        $response = $this->login()->assertOk()->assertJsonPath('data.organization.id', $company->id);

        $this->assertNull($response->json('data.requires_organization'));
        $claims = $this->claims($response->json('data.tokens.access_token'));

        $this->assertSame($company->id, $claims['org']);
        $this->assertSame((string) $ana->id, $claims['sub']);
        $this->assertContains('sales.invoice.issue', $claims['permissions']);
        $this->assertContains('sales.trash.restore-any', $claims['permissions'], 'el administrador tiene todos');
        $this->assertSame('Ana', $claims['name']);
    }

    public function test_the_permissions_of_the_token_are_those_of_the_role_in_that_company(): void
    {
        $ana = $this->user();
        $this->join($ana, 'Ana SRL', 'seller');

        $claims = $this->claims($this->login()->json('data.tokens.access_token'));

        $this->assertSame(
            ['sales.customer.manage', 'sales.customer.view', 'sales.invoice.issue', 'sales.invoice.view'],
            $claims['permissions'],
        );
    }

    public function test_the_permissions_of_several_roles_in_the_same_company_are_the_union(): void
    {
        $ana = $this->user();
        $company = $this->join($ana, 'Ana SRL', 'seller');
        $company->users()->attach($ana->id, ['role_id' => Role::where('name', 'accountant')->value('id')]);

        $claims = $this->claims($this->login()->json('data.tokens.access_token'));

        $this->assertContains('sales.invoice.issue', $claims['permissions']);
        $this->assertContains('sales.invoice.annul', $claims['permissions']);
        $this->assertSame($claims['permissions'], array_values(array_unique($claims['permissions'])), 'sin repetidos');
    }

    public function test_a_wrong_password_or_unknown_user_is_a_401_and_issues_nothing(): void
    {
        $this->join($this->user(), 'Ana SRL');

        $this->postJson('/api/auth/login', ['email' => 'ana@example.com', 'password' => 'incorrecta'])->assertUnauthorized();
        $this->postJson('/api/auth/login', ['email' => 'nadie@example.com', 'password' => 'password'])->assertUnauthorized();
        $this->assertSame(0, DB::table('oauth_access_tokens')->count());
    }

    public function test_a_user_without_companies_gets_a_clear_403_and_no_tokens(): void
    {
        $this->user();

        $this->login()->assertForbidden()->assertJsonPath('message', 'Tu usuario no tiene empresas asignadas. Contactá al administrador.');
        $this->assertSame(0, DB::table('oauth_access_tokens')->count());
    }

    public function test_an_inactive_company_is_not_offered(): void
    {
        $ana = $this->user();
        $this->join($ana, 'Vieja SA', active: false);
        $active = $this->join($ana, 'Nueva SA');

        $this->login()->assertOk()->assertJsonPath('data.organization.id', $active->id);
    }

    // ------------------------------------------------------------------ varias empresas

    public function test_with_several_companies_login_does_not_issue_tokens_and_offers_the_list(): void
    {
        $ana = $this->user();
        $b = $this->join($ana, 'B SRL');
        $a = $this->join($ana, 'A SA');

        $response = $this->login()->assertOk()
            ->assertJsonPath('data.requires_organization', true)
            ->assertJsonPath('data.organizations', [['id' => $a->id, 'name' => 'A SA'], ['id' => $b->id, 'name' => 'B SRL']])
            ->assertJsonPath('data.selection_expires_in', 300);

        $this->assertNull($response->json('data.tokens'), 'todavía no hay acceso');
        $this->assertNotEmpty($response->json('data.selection_token'));
        $this->assertSame(0, DB::table('oauth_access_tokens')->count());
    }

    public function test_choosing_a_company_issues_the_tokens_of_that_company(): void
    {
        $ana = $this->user();
        $seller = $this->join($ana, 'Chica SRL', 'seller');
        $admin = $this->join($ana, 'Grande SA', 'company_admin');

        $token = $this->login()->json('data.selection_token');

        $response = $this->postJson('/api/auth/select-organization', ['selection_token' => $token, 'organization_id' => $seller->id])
            ->assertOk()->assertJsonPath('data.organization.name', 'Chica SRL');

        $claims = $this->claims($response->json('data.tokens.access_token'));
        $this->assertSame($seller->id, $claims['org']);
        $this->assertNotContains('sales.invoice.annul', $claims['permissions'], 'en esa empresa es solo vendedor');

        // Otro login, otra empresa: los permisos son los de allí
        $token = $this->login()->json('data.selection_token');
        $other = $this->claims(
            $this->postJson('/api/auth/select-organization', ['selection_token' => $token, 'organization_id' => $admin->id])->json('data.tokens.access_token')
        );
        $this->assertSame($admin->id, $other['org']);
        $this->assertContains('sales.invoice.annul', $other['permissions']);
    }

    public function test_the_selection_token_works_only_once(): void
    {
        $ana = $this->user();
        $a = $this->join($ana, 'A SA');
        $this->join($ana, 'B SRL');
        $token = $this->login()->json('data.selection_token');

        $this->postJson('/api/auth/select-organization', ['selection_token' => $token, 'organization_id' => $a->id])->assertOk();
        $this->postJson('/api/auth/select-organization', ['selection_token' => $token, 'organization_id' => $a->id])
            ->assertUnauthorized()->assertJsonPath('message', 'La selección de empresa venció: volvé a iniciar sesión.');
    }

    public function test_a_company_that_is_not_yours_is_rejected_and_does_not_burn_the_selection(): void
    {
        $ana = $this->user();
        $a = $this->join($ana, 'A SA');
        $this->join($ana, 'B SRL');
        $foreign = $this->join($this->user('otro@example.com'), 'Ajena SA');
        $token = $this->login()->json('data.selection_token');

        $this->postJson('/api/auth/select-organization', ['selection_token' => $token, 'organization_id' => $foreign->id])
            ->assertForbidden()->assertJsonPath('message', 'No pertenecés a esa empresa o está dada de baja.');
        $this->postJson('/api/auth/select-organization', ['selection_token' => $token, 'organization_id' => 99999])->assertForbidden();
        $this->assertSame(0, DB::table('oauth_access_tokens')->count());

        // el permiso sigue sirviendo para una empresa propia
        $this->postJson('/api/auth/select-organization', ['selection_token' => $token, 'organization_id' => $a->id])->assertOk();
    }

    public function test_an_unknown_or_expired_selection_token_is_rejected(): void
    {
        $this->postJson('/api/auth/select-organization', ['selection_token' => str_repeat('a', 64), 'organization_id' => 1])->assertUnauthorized();
        $this->postJson('/api/auth/select-organization', [])->assertUnprocessable();
    }

    public function test_the_selection_token_is_not_stored_in_clear_text(): void
    {
        $raw = app(SelectionTokens::class)->create(7);

        $this->assertNull(\Illuminate\Support\Facades\Cache::get('org-selection:' . $raw));
        $this->assertSame(7, app(SelectionTokens::class)->peek($raw));
    }

    // ------------------------------------------------------------------ refresh y cambio de empresa

    public function test_refresh_keeps_the_company_and_picks_up_permission_changes(): void
    {
        $ana = $this->user();
        $company = $this->join($ana, 'Ana SRL', 'viewer');
        $login = $this->login();

        $this->assertNotContains('sales.invoice.issue', $this->claims($login->json('data.tokens.access_token'))['permissions']);

        // Le suben el rol: el siguiente refresh lo refleja
        $company->users()->detach($ana->id);
        $company->users()->attach($ana->id, ['role_id' => Role::where('name', 'seller')->value('id')]);

        $refreshed = $this->postJson('/api/auth/refresh', ['refresh_token' => $login->json('data.tokens.refresh_token')])->assertOk();
        $claims = $this->claims($refreshed->json('data.tokens.access_token'));

        $this->assertSame($company->id, $claims['org']);
        $this->assertContains('sales.invoice.issue', $claims['permissions']);
    }

    public function test_refresh_keeps_the_chosen_company_among_several(): void
    {
        $ana = $this->user();
        $this->join($ana, 'A SA');
        $b = $this->join($ana, 'B SRL', 'viewer');
        $token = $this->login()->json('data.selection_token');
        $tokens = $this->postJson('/api/auth/select-organization', ['selection_token' => $token, 'organization_id' => $b->id])->json('data.tokens');

        $again = $this->postJson('/api/auth/refresh', ['refresh_token' => $tokens['refresh_token']])->assertOk()->json('data.tokens');

        $this->assertSame($b->id, $this->claims($again['access_token'])['org']);

        // y el nuevo refresh token sigue en la misma empresa
        $third = $this->postJson('/api/auth/refresh', ['refresh_token' => $again['refresh_token']])->assertOk()->json('data.tokens');
        $this->assertSame($b->id, $this->claims($third['access_token'])['org']);
    }

    public function test_refresh_fails_if_the_person_no_longer_belongs_to_the_company(): void
    {
        $ana = $this->user();
        $company = $this->join($ana, 'Ana SRL');
        $this->join($ana, 'Otra SA');
        $selection = $this->login()->json('data.selection_token');
        $tokens = $this->postJson('/api/auth/select-organization', ['selection_token' => $selection, 'organization_id' => $company->id])->json('data.tokens');

        $company->users()->detach($ana->id);

        $this->postJson('/api/auth/refresh', ['refresh_token' => $tokens['refresh_token']])
            ->assertUnauthorized()->assertJsonPath('message', 'Ya no pertenecés a la empresa de esta sesión: iniciá sesión de nuevo.');
    }

    public function test_refresh_fails_if_the_company_was_deactivated(): void
    {
        $ana = $this->user();
        $company = $this->join($ana, 'Ana SRL');
        $tokens = $this->login()->json('data.tokens');

        $company->update(['is_active' => false]);

        $this->postJson('/api/auth/refresh', ['refresh_token' => $tokens['refresh_token']])->assertUnauthorized();
    }

    public function test_a_session_from_before_the_companies_existed_refreshes_into_the_only_company(): void
    {
        $ana = $this->user();
        $company = $this->join($ana, 'Ana SRL');
        $tokens = $this->login()->json('data.tokens');

        // como las sesiones abiertas antes de esta versión: sin registro de empresa
        DB::table('oauth_token_organizations')->delete();

        $claims = $this->claims($this->postJson('/api/auth/refresh', ['refresh_token' => $tokens['refresh_token']])->assertOk()->json('data.tokens.access_token'));
        $this->assertSame($company->id, $claims['org']);
    }

    public function test_a_legacy_session_of_someone_with_several_companies_must_log_in_again(): void
    {
        $ana = $this->user();
        $a = $this->join($ana, 'A SA');
        $this->join($ana, 'B SRL');
        $selection = $this->login()->json('data.selection_token');
        $tokens = $this->postJson('/api/auth/select-organization', ['selection_token' => $selection, 'organization_id' => $a->id])->json('data.tokens');
        DB::table('oauth_token_organizations')->delete();

        $this->postJson('/api/auth/refresh', ['refresh_token' => $tokens['refresh_token']])->assertUnauthorized();
    }

    public function test_switching_company_issues_tokens_of_the_other_company_without_the_password(): void
    {
        $ana = $this->user();
        $a = $this->join($ana, 'A SA', 'company_admin');
        $b = $this->join($ana, 'B SRL', 'viewer');
        $selection = $this->login()->json('data.selection_token');
        $tokens = $this->postJson('/api/auth/select-organization', ['selection_token' => $selection, 'organization_id' => $a->id])->json('data.tokens');

        $switched = $this->postJson('/api/auth/switch-organization', ['refresh_token' => $tokens['refresh_token'], 'organization_id' => $b->id])
            ->assertOk()->assertJsonPath('data.organization.name', 'B SRL');

        $claims = $this->claims($switched->json('data.tokens.access_token'));
        $this->assertSame($b->id, $claims['org']);
        $this->assertNotContains('sales.invoice.annul', $claims['permissions']);

        // la sesión nueva queda en B al renovar
        $renewed = $this->postJson('/api/auth/refresh', ['refresh_token' => $switched->json('data.tokens.refresh_token')])->json('data.tokens.access_token');
        $this->assertSame($b->id, $this->claims($renewed)['org']);

        // el refresh token anterior ya no sirve
        $this->postJson('/api/auth/refresh', ['refresh_token' => $tokens['refresh_token']])->assertUnauthorized();
    }

    public function test_switching_to_a_company_that_is_not_yours_fails_and_keeps_the_session(): void
    {
        $ana = $this->user();
        $a = $this->join($ana, 'A SA');
        $foreign = $this->join($this->user('otro@example.com'), 'Ajena SA');
        $tokens = $this->login()->json('data.tokens');

        $this->postJson('/api/auth/switch-organization', ['refresh_token' => $tokens['refresh_token'], 'organization_id' => $foreign->id])
            ->assertUnauthorized();

        // el intento fallido no consumió la sesión
        $this->assertSame($a->id, $this->claims(
            $this->postJson('/api/auth/refresh', ['refresh_token' => $tokens['refresh_token']])->assertOk()->json('data.tokens.access_token')
        )['org']);
    }

    public function test_the_requested_company_does_not_leak_into_the_next_refresh(): void
    {
        $ana = $this->user();
        $a = $this->join($ana, 'A SA');
        $b = $this->join($ana, 'B SRL');
        $selection = $this->login()->json('data.selection_token');
        $tokens = $this->postJson('/api/auth/select-organization', ['selection_token' => $selection, 'organization_id' => $a->id])->json('data.tokens');

        $this->postJson('/api/auth/switch-organization', ['refresh_token' => $tokens['refresh_token'], 'organization_id' => $b->id])->assertOk();

        $this->assertNull(app(TokenContext::class)->requestedOrganizationId);
        $this->assertNull(app(TokenContext::class)->organizationId);
    }

    public function test_the_requested_company_is_cleared_even_if_the_oauth_client_is_not_configured(): void
    {
        config(['services.passport.password_client_id' => '']);

        $this->postJson('/api/auth/switch-organization', ['refresh_token' => 'x', 'organization_id' => 5])->assertStatus(503);

        $this->assertNull(app(TokenContext::class)->requestedOrganizationId);
    }

    // ------------------------------------------------------------------ consulta de empresas

    public function test_organizations_and_me_report_the_current_company_and_permissions(): void
    {
        $ana = $this->user();
        $a = $this->join($ana, 'A SA', 'viewer');
        $b = $this->join($ana, 'B SRL');
        $selection = $this->login()->json('data.selection_token');
        $access = $this->postJson('/api/auth/select-organization', ['selection_token' => $selection, 'organization_id' => $a->id])->json('data.tokens.access_token');

        $this->withToken($access)->getJson('/api/auth/organizations')->assertOk()
            ->assertJsonPath('data.current', $a->id)
            ->assertJsonPath('data.organizations', [['id' => $a->id, 'name' => 'A SA'], ['id' => $b->id, 'name' => 'B SRL']]);

        $this->withToken($access)->getJson('/api/auth/me')->assertOk()
            ->assertJsonPath('data.organization_id', $a->id)
            ->assertJsonPath('data.permissions', ['sales.customer.view', 'sales.invoice.view']);
    }

    // ------------------------------------------------------------------ alta y puertas cerradas

    public function test_open_registration_is_closed(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Nueva Persona', 'email' => 'nueva@example.com', 'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertNotFound();

        $this->assertSame(0, User::where('email', 'nueva@example.com')->count());
        $this->assertSame(0, Organization::where('name', 'Nueva Persona')->count());
    }

    public function test_the_password_grant_is_no_longer_available_on_the_public_oauth_endpoint(): void
    {
        $this->join($this->user(), 'Ana SRL');

        $this->postJson('/oauth/token', [
            'grant_type' => 'password',
            'client_id' => config('services.passport.password_client_id'),
            'client_secret' => config('services.passport.password_client_secret'),
            'username' => 'ana@example.com',
            'password' => 'password',
        ])->assertStatus(400);
        $this->assertSame(0, DB::table('oauth_access_tokens')->count());
    }

    public function test_the_selection_grant_cannot_be_used_without_a_valid_selection_token(): void
    {
        $ana = $this->user();
        $company = $this->join($ana, 'Ana SRL');

        $this->postJson('/oauth/token', [
            'grant_type' => 'organization_selection',
            'client_id' => config('services.passport.password_client_id'),
            'client_secret' => config('services.passport.password_client_secret'),
            'selection_token' => 'inventado',
            'organization_id' => $company->id,
        ])->assertStatus(400);
        $this->assertSame(0, DB::table('oauth_access_tokens')->count());
    }
}
