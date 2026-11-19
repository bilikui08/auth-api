<?php

namespace Tests\Feature;

use App\Auth\Passport\SelectionTokens;
use App\Domain\Auth\Contracts\TokenRepository;
use App\Mail\AddedToOrganizationMail;
use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\UsesRealPassport;
use Tests\TestCase;

/**
 * Invitaciones por email: la única forma de tener una cuenta nueva (el registro abierto está cerrado). El token del enlace es
 * la credencial: se guarda hasheado, vence, se usa una vez y nunca vuelve por la API.
 */
class InvitationsTest extends TestCase
{
    use RefreshDatabase;
    use UsesRealPassport;

    private User $ana;   // administradora de la empresa A
    private User $beto;  // vendedor de A (no puede invitar)
    private User $root;  // administrador del sistema
    private Organization $a;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPassport();
        Mail::fake();
        config(['app.frontend_url' => 'https://app.ejemplo.com']);

        $this->ana = User::factory()->create(['name' => 'Ana', 'email' => 'ana@example.com']);
        $this->beto = User::factory()->create(['name' => 'Beto', 'email' => 'beto@example.com']);
        $this->root = User::factory()->create(['name' => 'Root', 'email' => 'root@example.com']);
        $this->root->roles()->attach(Role::where('name', 'admin')->value('id'));

        $this->a = Organization::create(['name' => 'Empresa A SA']);
        $this->a->users()->attach($this->ana->id, ['role_id' => Role::where('name', 'company_admin')->value('id')]);
        $this->a->users()->attach($this->beto->id, ['role_id' => Role::where('name', 'seller')->value('id')]);
        $system = Organization::create(['name' => 'Sistema']);
        $system->users()->attach($this->root->id, ['role_id' => Role::where('name', 'company_admin')->value('id')]);
    }

    protected function tearDown(): void
    {
        $this->tearDownPassport();
        parent::tearDown();
    }

    private function as(User $user, ?Organization $organization = null)
    {
        $organization ??= $user->organizations()->orderBy('organizations.id')->first();
        $this->app['auth']->forgetGuards();
        $token = app(TokenRepository::class)->issueForOrganization(app(SelectionTokens::class)->create($user->id), $organization->id)->accessToken;

        return $this->withToken($token);
    }

    private function invite(array $overrides = [])
    {
        return $this->as($this->ana)->postJson('/api/auth/organization/invitations', $overrides + ['email' => 'nueva@example.com', 'roles' => ['seller']]);
    }

    /** El token secreto sale solo en el email: se recupera del enlace del correo enviado. */
    private function tokenFromMail(string $to = 'nueva@example.com'): string
    {
        $sent = Mail::sent(InvitationMail::class, fn (InvitationMail $m) => $m->hasTo($to))->last();
        $this->assertNotNull($sent, "no se envió ninguna invitación a {$to}");
        $this->assertStringStartsWith('https://app.ejemplo.com/accept-invitation?token=', $sent->acceptUrl);

        return substr($sent->acceptUrl, strlen('https://app.ejemplo.com/accept-invitation?token='));
    }

    private function accept(string $token, array $overrides = [])
    {
        return $this->postJson("/api/auth/invitations/{$token}/accept", $overrides + ['name' => 'Nueva Persona', 'password' => 'password123', 'password_confirmation' => 'password123']);
    }

    // ------------------------------------------------------------------ registro cerrado

    public function test_open_registration_is_gone(): void
    {
        $this->postJson('/api/auth/register', ['name' => 'X', 'email' => 'x@example.com', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertNotFound();
    }

    // ------------------------------------------------------------------ invitar

    public function test_the_mails_carry_the_configured_system_name_not_a_hardcoded_one(): void
    {
        config(['app.name' => 'Mi ERP']);
        $this->invite()->assertCreated();

        $mail = Mail::sent(InvitationMail::class)->first();
        $this->assertSame('Ana te invitó a Empresa A SA en Mi ERP', $mail->envelope()->subject);
        $this->assertStringContainsString('Mi ERP', $mail->render());
        $this->assertStringNotContainsString('Facturín', $mail->render());

        $dani = User::factory()->create(['name' => 'Dani', 'email' => 'dani@example.com']);
        $this->invite(['email' => 'dani@example.com', 'roles' => ['viewer']])->assertCreated();
        $added = Mail::sent(AddedToOrganizationMail::class)->first();
        $this->assertSame('Ahora podés trabajar con Empresa A SA en Mi ERP', $added->envelope()->subject);
        $this->assertStringNotContainsString('Facturín', $added->render());
    }

    public function test_an_unknown_email_gets_an_invitation_by_mail_and_nothing_else_is_created(): void
    {
        $response = $this->invite()->assertCreated()->assertJsonPath('data.status', 'invited')->assertJsonPath('data.email_sent', true);

        Mail::assertSent(InvitationMail::class, fn (InvitationMail $m) => $m->hasTo('nueva@example.com') && $m->organization->is($this->a) && $m->inviterName === 'Ana' && $m->validDays === 7);
        $this->assertSame(0, User::where('email', 'nueva@example.com')->count(), 'todavía no hay cuenta');
        $this->assertSame(['nueva@example.com'], array_column($response->json('data.invitations'), 'email'));
        $this->assertSame(['seller'], $response->json('data.invitations.0.roles'));
        $this->assertSame('Ana', $response->json('data.invitations.0.invited_by'));
    }

    public function test_the_secret_token_never_comes_back_through_the_api_and_is_stored_hashed(): void
    {
        $response = $this->invite();
        $token = $this->tokenFromMail();

        $this->assertStringNotContainsString($token, json_encode($response->json()));
        $this->assertStringNotContainsString($token, json_encode($this->as($this->ana)->getJson('/api/auth/organization/invitations')->json()));
        $this->assertNull(DB::table('invitations')->where('token_hash', $token)->first(), 'no se guarda en claro');
        $this->assertNotNull(DB::table('invitations')->where('token_hash', hash('sha256', $token))->first());
    }

    public function test_someone_who_already_has_an_account_is_added_at_once_and_notified(): void
    {
        $dani = User::factory()->create(['name' => 'Dani', 'email' => 'dani@example.com']);

        $this->invite(['email' => 'DANI@example.com', 'roles' => ['viewer']])->assertCreated()->assertJsonPath('data.status', 'added');

        $this->assertSame(['viewer'], DB::table('organization_user')->join('roles', 'roles.id', '=', 'organization_user.role_id')
            ->where('organization_id', $this->a->id)->where('user_id', $dani->id)->pluck('roles.name')->all());
        Mail::assertSent(AddedToOrganizationMail::class, fn (AddedToOrganizationMail $m) => $m->hasTo('dani@example.com') && $m->loginUrl === 'https://app.ejemplo.com/');
        Mail::assertNotSent(InvitationMail::class);
        $this->assertSame(0, Invitation::count(), 'no hace falta invitación');
    }

    public function test_inviting_someone_who_already_belongs_is_refused(): void
    {
        $this->invite(['email' => 'beto@example.com'])->assertUnprocessable()->assertJsonPath('message', 'Esa persona ya pertenece a la empresa.');
    }

    public function test_only_company_administrators_can_invite_and_roles_are_validated(): void
    {
        $this->as($this->beto)->postJson('/api/auth/organization/invitations', ['email' => 'x@example.com', 'roles' => ['viewer']])->assertForbidden();
        $this->as($this->beto)->getJson('/api/auth/organization/invitations')->assertForbidden();

        foreach ([['roles' => ['admin']], ['roles' => ['inventado']], ['roles' => []], ['email' => 'no-es-un-mail']] as $bad) {
            $this->invite($bad)->assertUnprocessable();
        }
        Mail::assertNothingSent();
        $this->assertSame(0, Invitation::count());
    }

    public function test_reinviting_replaces_the_previous_link(): void
    {
        $this->invite()->assertCreated();
        $first = $this->tokenFromMail();
        $this->invite(['roles' => ['viewer']])->assertCreated()->assertJsonCount(1, 'data.invitations');
        $second = $this->tokenFromMail();

        $this->assertNotSame($first, $second);
        $this->getJson("/api/auth/invitations/{$first}")->assertNotFound();
        $this->getJson("/api/auth/invitations/{$second}")->assertOk()->assertJsonPath('data.roles.0.name', 'viewer');
    }

    public function test_a_mail_failure_keeps_the_invitation_and_says_so(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP caído'));

        $this->invite()->assertCreated()->assertJsonPath('data.email_sent', false)->assertJsonCount(1, 'data.invitations');
    }

    public function test_pending_invitations_can_be_listed_and_cancelled_only_by_their_own_company(): void
    {
        $this->invite()->assertCreated();
        $token = $this->tokenFromMail();
        $id = Invitation::firstOrFail()->id;

        $other = Organization::create(['name' => 'Otra']);
        $carla = User::factory()->create(['name' => 'Carla', 'email' => 'carla@example.com']);
        $other->users()->attach($carla->id, ['role_id' => Role::where('name', 'company_admin')->value('id')]);

        $this->as($carla)->getJson('/api/auth/organization/invitations')->assertOk()->assertJsonCount(0, 'data');
        $this->as($carla)->deleteJson("/api/auth/organization/invitations/{$id}")->assertNotFound();
        $this->getJson("/api/auth/invitations/{$token}")->assertOk();

        $this->as($this->ana)->deleteJson("/api/auth/organization/invitations/{$id}")->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/auth/invitations/{$token}")->assertNotFound();
    }

    // ------------------------------------------------------------------ aceptar

    public function test_the_acceptance_screen_shows_what_you_are_being_invited_to_without_secrets(): void
    {
        $this->invite(['roles' => ['seller', 'viewer']])->assertCreated();
        $token = $this->tokenFromMail();

        $data = $this->getJson("/api/auth/invitations/{$token}")->assertOk()->json('data');

        $this->assertSame('nueva@example.com', $data['email']);
        $this->assertSame('Empresa A SA', $data['organization']);
        $this->assertSame('Ana', $data['invited_by']);
        $this->assertSame(['seller', 'viewer'], array_column($data['roles'], 'name'));
        $this->assertArrayNotHasKey('token', $data);
    }

    public function test_accepting_creates_the_account_joins_the_company_and_signs_in(): void
    {
        $this->invite(['roles' => ['seller']])->assertCreated();
        $token = $this->tokenFromMail();

        $response = $this->accept($token)->assertCreated()->assertJsonPath('data.status', 'created')->assertJsonPath('data.organization.name', 'Empresa A SA');

        $user = User::where('email', 'nueva@example.com')->firstOrFail();
        $this->assertSame('Nueva Persona', $user->name);
        $this->assertNotNull($user->email_verified_at, 'el enlace llegó a esa casilla: el email queda verificado');
        $this->assertSame(['user'], $user->roleNames(), 'sin privilegios globales');
        $claims = json_decode(base64_decode(strtr(explode('.', $response->json('data.tokens.access_token'))[1], '-_', '+/')), true);
        $this->assertSame($this->a->id, $claims['org']);
        $this->assertContains('sales.invoice.issue', $claims['permissions']);
        $this->assertNotContains('org.members.manage', $claims['permissions'], 'solo lo que dice el rol invitado');

        // y puede volver a entrar con su contraseña
        $this->postJson('/api/auth/login', ['email' => 'nueva@example.com', 'password' => 'password123'])->assertOk()->assertJsonPath('data.organization.id', $this->a->id);
    }

    public function test_an_invitation_works_only_once(): void
    {
        $this->invite()->assertCreated();
        $token = $this->tokenFromMail();

        $this->accept($token)->assertCreated();

        $this->accept($token, ['name' => 'Otro Nombre'])->assertNotFound()->assertJsonPath('message', 'La invitación no es válida o venció. Pedí que te inviten de nuevo.');
        $this->getJson("/api/auth/invitations/{$token}")->assertNotFound();
        $this->assertSame(1, User::where('email', 'nueva@example.com')->count());
        $this->assertNotNull(Invitation::firstOrFail()->accepted_at);
    }

    public function test_an_expired_invitation_is_refused(): void
    {
        $this->invite()->assertCreated();
        $token = $this->tokenFromMail();

        $this->travel(7)->days();
        $this->travel(1)->minutes();

        $this->getJson("/api/auth/invitations/{$token}")->assertNotFound();
        $this->accept($token)->assertNotFound();
        $this->assertSame(0, User::where('email', 'nueva@example.com')->count());
    }

    public function test_it_is_valid_until_the_end_of_its_period(): void
    {
        $this->invite()->assertCreated();
        $token = $this->tokenFromMail();

        $this->travel(6)->days();

        $this->accept($token)->assertCreated();
    }

    public function test_unknown_malformed_and_cancelled_tokens_all_look_the_same(): void
    {
        $this->invite()->assertCreated();
        $token = $this->tokenFromMail();
        $this->as($this->ana)->deleteJson('/api/auth/organization/invitations/' . Invitation::firstOrFail()->id)->assertOk();

        $messages = [];
        foreach ([$token, str_repeat('a', 64), 'corto', "x%27%20OR%201=1"] as $candidate) {
            $messages[] = $this->getJson('/api/auth/invitations/' . $candidate)->assertNotFound()->json('message');
            $messages[] = $this->accept($candidate)->assertNotFound()->json('message');
        }

        $this->assertCount(1, array_unique($messages), 'no revela si existió, venció o se canceló');
    }

    public function test_an_invitation_to_a_deactivated_company_is_refused(): void
    {
        $this->invite()->assertCreated();
        $token = $this->tokenFromMail();
        $this->a->update(['is_active' => false]);

        $this->accept($token)->assertNotFound();
    }

    public function test_accepting_validates_name_and_password_without_burning_the_invitation(): void
    {
        $this->invite()->assertCreated();
        $token = $this->tokenFromMail();

        $this->accept($token, ['name' => 'Ana'])->assertUnprocessable()->assertJsonPath('errors.name.0', 'Ese nombre ya está en uso: elegí otro.');
        $this->accept($token, ['password' => 'corta', 'password_confirmation' => 'corta'])->assertUnprocessable();
        $this->accept($token, ['password_confirmation' => 'distinta123'])->assertUnprocessable();
        $this->accept($token, ['name' => ''])->assertUnprocessable();

        $this->getJson("/api/auth/invitations/{$token}")->assertOk();
        $this->accept($token)->assertCreated();
    }

    public function test_the_email_of_the_account_comes_from_the_invitation_not_from_the_request(): void
    {
        $this->invite()->assertCreated();
        $token = $this->tokenFromMail();

        $this->accept($token, ['email' => 'otro@example.com', 'roles' => ['admin']])->assertCreated();

        $this->assertSame(0, User::where('email', 'otro@example.com')->count());
        $this->assertSame(['user'], User::where('email', 'nueva@example.com')->firstOrFail()->roleNames());
    }

    public function test_if_the_email_got_an_account_in_the_meantime_it_is_only_added_to_the_company(): void
    {
        $this->invite()->assertCreated();
        $token = $this->tokenFromMail();
        $existing = User::factory()->create(['name' => 'Ya Existe', 'email' => 'nueva@example.com']);

        $response = $this->accept($token)->assertOk()->assertJsonPath('data.status', 'existing_user');

        $this->assertNull($response->json('data.tokens'), 'entrar requiere su contraseña de siempre');
        $this->assertSame($existing->id, User::where('email', 'nueva@example.com')->value('id'));
        $this->assertTrue($this->a->users()->where('users.id', $existing->id)->exists());
        $this->assertSame('Ya Existe', $existing->fresh()->name, 'no pisa los datos de la cuenta existente');
    }

    public function test_the_acceptance_is_rate_limited(): void
    {
        $last = null;
        for ($i = 0; $i < 12; $i++) {
            $last = $this->accept(str_repeat('b', 64))->getStatusCode();
        }

        $this->assertSame(429, $last);
    }

    // ------------------------------------------------------------------ administrador del sistema: empresa nueva con administrador invitado

    public function test_the_system_admin_creates_a_company_and_invites_its_administrator_by_mail(): void
    {
        $response = $this->as($this->root)->postJson('/api/auth/admin/organizations', ['name' => 'Cliente Nuevo SRL', 'admin_email' => 'dueno@example.com'])
            ->assertCreated()->assertJsonPath('data.admin_status', 'invited')->assertJsonPath('data.email_sent', true);

        $company = Organization::findOrFail($response->json('data.id'));
        $token = $this->tokenFromMail('dueno@example.com');
        $this->accept($token, ['name' => 'La Dueña'])->assertCreated();

        $owner = User::where('email', 'dueno@example.com')->firstOrFail();
        $this->assertSame(['company_admin'], DB::table('organization_user')->join('roles', 'roles.id', '=', 'organization_user.role_id')
            ->where('organization_id', $company->id)->where('user_id', $owner->id)->pluck('roles.name')->all());
    }

    public function test_an_existing_account_becomes_administrator_right_away(): void
    {
        $response = $this->as($this->root)->postJson('/api/auth/admin/organizations', ['name' => 'Otra SRL', 'admin_email' => 'beto@example.com'])
            ->assertCreated()->assertJsonPath('data.admin_status', 'assigned');

        $this->assertTrue(Organization::findOrFail($response->json('data.id'))->users()->where('users.id', $this->beto->id)->exists());
        Mail::assertNotSent(InvitationMail::class);
    }
}
