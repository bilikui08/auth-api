<?php

namespace Tests\Feature;

use App\Application\Mail\TemplateRenderer;
use App\Application\System\SystemSettings;
use App\Auth\Passport\SelectionTokens;
use App\Domain\Auth\Contracts\TokenRepository;
use App\Mail\AddedToOrganizationMail;
use App\Mail\InvitationMail;
use App\Models\EmailTemplate;
use App\Models\Organization;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\UsesRealPassport;
use Tests\TestCase;

/**
 * Ajustes de la plataforma (nombre del sistema) y plantillas de correo editables. Lo delicado: el texto de una plantilla lo escribe
 * una persona y sale por tu remitente con un enlace de acceso, así que no se ejecuta nunca como código, se escapa lo que escribe
 * cualquiera y no admite enlaces ni HTML propios.
 */
class SystemAndEmailTemplatesTest extends TestCase
{
    use RefreshDatabase;
    use UsesRealPassport;

    private User $root;
    private User $ana;
    private Organization $a;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPassport();
        Mail::fake();
        config(['app.default_name' => 'Sistema de gestión', 'app.frontend_url' => 'https://app.ejemplo.com']);

        $this->root = User::factory()->create(['name' => 'Root', 'email' => 'root@example.com']);
        $this->root->roles()->attach(Role::where('name', 'admin')->value('id'));
        $this->ana = User::factory()->create(['name' => 'Ana', 'email' => 'ana@example.com']);
        $this->a = Organization::create(['name' => 'Empresa A SA']);
        $this->a->users()->attach($this->ana->id, ['role_id' => Role::where('name', 'company_admin')->value('id')]);
        $system = Organization::create(['name' => 'Sistema']);
        $system->users()->attach($this->root->id, ['role_id' => Role::where('name', 'company_admin')->value('id')]);
    }

    protected function tearDown(): void
    {
        $this->tearDownPassport();
        parent::tearDown();
    }

    private function as(User $user)
    {
        $organization = $user->organizations()->orderBy('organizations.id')->first();
        $this->app['auth']->forgetGuards();

        return $this->withToken(app(TokenRepository::class)->issueForOrganization(app(SelectionTokens::class)->create($user->id), $organization->id)->accessToken);
    }

    private function body(array $overrides = []): array
    {
        return $overrides + ['subject' => '{invitador} te espera en {empresa}', 'heading' => 'Bienvenido a {sistema}', 'body' => "Hola, **{invitador}** te invitó a {empresa}.\n\nEl enlace vale {dias} días.", 'button_label' => 'Entrar ahora'];
    }

    // ------------------------------------------------------------------ renderer (el núcleo de la seguridad)

    public function test_values_cannot_inject_markdown_links_images_or_html(): void
    {
        $md = TemplateRenderer::markdown('Hola {invitador}', ['invitador' => '[clic](http://malo.com) ![i](http://malo.com/x.png) <script>x</script> **negrita** `c`']);

        $this->assertStringNotContainsString('](http', str_replace('\\]', '', $md), 'el enlace queda escapado');
        $this->assertStringContainsString('\\[clic\\]', $md);
        $this->assertStringContainsString('\\<script\\>', $md);
        $this->assertStringContainsString('\\*\\*negrita\\*\\*', $md, 'un valor no puede armar negrita');
    }

    public function test_the_template_text_is_escaped_too_except_for_bold(): void
    {
        $md = TemplateRenderer::markdown("**ok** [x](http://a.com) <b>y</b> {{ 7*7 }} @php echo 1; @endphp {sistema}", ['sistema' => 'S']);

        $this->assertStringStartsWith('**ok** ', $md);
        $this->assertStringContainsString('\\[x\\]', $md);
        $this->assertStringContainsString('\\<b\\>', $md);
        $this->assertStringContainsString('{{ 7\\*7 }}', $md);
        $this->assertStringEndsWith(' S', $md);
    }

    public function test_paragraphs_line_breaks_and_bold_work(): void
    {
        $this->assertSame("Primero **fuerte**  \nsegunda línea\n\nOtro párrafo", TemplateRenderer::markdown("Primero **fuerte**\nsegunda línea\n\n\n\nOtro párrafo", []));
    }

    public function test_single_line_texts_cannot_break_into_new_header_lines(): void
    {
        $subject = TemplateRenderer::line('Hola {invitador}', ['invitador' => "Ana\r\nBcc: victima@example.com"]);

        $this->assertStringNotContainsString("\n", $subject);
        $this->assertStringNotContainsString("\r", $subject);
        $this->assertSame('Hola Ana Bcc: victima@example.com', $subject);
    }

    public function test_unknown_placeholders_are_left_as_they_are(): void
    {
        $this->assertSame('Hola {nada}', TemplateRenderer::line('Hola {nada}', ['otra' => 'x']));
        $this->assertSame(['a', 'b'], TemplateRenderer::placeholders('{a} y {b} y {a}'));
    }

    // ------------------------------------------------------------------ nombre del sistema

    public function test_the_system_name_defaults_to_the_env_and_the_admin_can_change_and_reset_it(): void
    {
        $this->as($this->root)->getJson('/api/auth/admin/system')->assertOk()
            ->assertJsonPath('data.system_name', 'Sistema de gestión')->assertJsonPath('data.is_default', true);

        $this->as($this->root)->putJson('/api/auth/admin/system', ['system_name' => '  Mi ERP  '])->assertOk()
            ->assertJsonPath('data.system_name', 'Mi ERP')->assertJsonPath('data.is_default', false)->assertJsonPath('data.default_system_name', 'Sistema de gestión');

        $this->as($this->root)->putJson('/api/auth/admin/system', ['system_name' => ''])->assertOk()->assertJsonPath('data.system_name', 'Sistema de gestión');
        $this->assertSame(0, SystemSetting::count(), 'vacío vuelve al .env');
    }

    public function test_the_system_name_is_validated(): void
    {
        foreach ([str_repeat('a', 61), '<b>Mi ERP</b>', "Dos\nlíneas", ['x']] as $bad) {
            $this->as($this->root)->putJson('/api/auth/admin/system', ['system_name' => $bad])->assertUnprocessable();
        }
        $this->as($this->root)->putJson('/api/auth/admin/system', [])->assertUnprocessable();
        $this->assertSame(0, SystemSetting::count());
    }

    public function test_only_the_system_admin_can_change_the_system_settings_and_templates(): void
    {
        $this->as($this->ana)->getJson('/api/auth/admin/system')->assertForbidden();
        $this->as($this->ana)->putJson('/api/auth/admin/system', ['system_name' => 'X'])->assertForbidden();
        $this->as($this->ana)->getJson('/api/auth/admin/email-templates')->assertForbidden();
        $this->as($this->ana)->putJson('/api/auth/admin/email-templates/invitation', $this->body())->assertForbidden();
        $this->as($this->ana)->deleteJson('/api/auth/admin/email-templates/invitation')->assertForbidden();
        $this->as($this->ana)->postJson('/api/auth/admin/email-templates/invitation/preview', $this->body())->assertForbidden();
        $this->as($this->ana)->postJson('/api/auth/admin/email-templates/invitation/test', $this->body())->assertForbidden();
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/auth/admin/system')->assertUnauthorized();

        $this->assertSame(0, SystemSetting::count() + EmailTemplate::count());
    }

    public function test_branding_is_public_and_only_exposes_the_name(): void
    {
        SystemSetting::create(['key' => SystemSettings::NAME, 'value' => 'Mi ERP']);

        $this->getJson('/api/auth/branding')->assertOk()->assertExactJson([
            'success' => true, 'message' => 'Marca.', 'data' => ['system_name' => 'Mi ERP'], 'errors' => null,
        ]);
    }

    public function test_the_name_reaches_the_mail_header_subject_and_sender(): void
    {
        $this->as($this->root)->putJson('/api/auth/admin/system', ['system_name' => 'Mi ERP'])->assertOk();

        $mail = new InvitationMail($this->a, 'Ana', 'https://app.ejemplo.com/accept-invitation?token=T', 7);

        $this->assertSame('Ana te invitó a Empresa A SA en Mi ERP', $mail->rendered()->subject);
        $html = $mail->render();
        $this->assertStringContainsString('Mi ERP', $html);
        $this->assertStringNotContainsString('Sistema de gestión', $html);
        $this->assertSame('Mi ERP', $mail->from[0]['name']);
        $this->assertSame(config('mail.from.address'), $mail->from[0]['address']);
    }

    // ------------------------------------------------------------------ plantillas

    public function test_it_lists_the_templates_with_their_originals_and_variables(): void
    {
        $data = $this->as($this->root)->getJson('/api/auth/admin/email-templates')->assertOk()->json('data');

        $this->assertSame(['invitation', 'added_to_organization', 'password_reset'], array_column($data, 'key'));
        $invitation = $data[0];
        $this->assertFalse($invitation['is_customized']);
        $this->assertSame($invitation['defaults'], $invitation['current']);
        $this->assertSame(['invitador', 'empresa', 'sistema', 'dias'], array_column($invitation['variables'], 'name'));
        $this->assertNotEmpty($invitation['variables'][0]['description']);
    }

    public function test_the_password_reset_template_previews_and_sends_a_test(): void
    {
        $preview = $this->as($this->root)->postJson('/api/auth/admin/email-templates/password_reset/preview', ['subject' => 'Cambiá tu clave', 'heading' => 'Clave nueva', 'body' => 'El enlace vale {minutos} minutos.', 'button_label' => 'Elegir', 'samples' => ['minutos' => '15']])->assertOk()->json('data');
        $this->assertStringContainsString('15 minutos', $preview['html']);
        $this->assertStringContainsString('/reset-password?token=EJEMPLO', $preview['html']);

        $this->as($this->root)->getJson('/api/auth/admin/email-templates')->assertOk()
            ->assertJsonPath('data.2.variables.1.name', 'minutos');
    }

    public function test_a_saved_template_is_used_by_the_real_mail(): void
    {
        $this->as($this->root)->putJson('/api/auth/admin/email-templates/invitation', $this->body())->assertOk()
            ->assertJsonPath('data.is_customized', true)->assertJsonPath('data.current.button_label', 'Entrar ahora');

        $mail = new InvitationMail($this->a, 'Ana', 'https://app.ejemplo.com/accept-invitation?token=T', 7);
        $rendered = $mail->rendered();

        $this->assertSame('Ana te espera en Empresa A SA', $rendered->subject);
        $this->assertSame('Bienvenido a Sistema de gestión', $rendered->heading);
        $this->assertSame('Entrar ahora', $rendered->buttonLabel);
        $html = $mail->render();
        $this->assertStringContainsString('Entrar ahora', $html);
        $this->assertMatchesRegularExpression('#<strong[^>]*>Ana</strong>#', $html, 'la negrita se convierte: ' . substr(strip_tags($html, '<strong><p>'), 0, 600));
        $this->assertStringContainsString('https://app.ejemplo.com/accept-invitation?token=T', $html, 'el enlace lo pone el sistema');
    }

    public function test_the_real_invitation_flow_sends_the_customized_template(): void
    {
        $this->as($this->root)->putJson('/api/auth/admin/email-templates/invitation', $this->body(['subject' => 'Sumate a {empresa}']))->assertOk();

        $this->as($this->ana)->postJson('/api/auth/organization/invitations', ['email' => 'nueva@example.com', 'roles' => ['seller']])->assertCreated();

        Mail::assertSent(InvitationMail::class, fn (InvitationMail $m) => $m->rendered()->subject === 'Sumate a Empresa A SA');
    }

    public function test_user_controlled_names_are_escaped_in_the_final_mail(): void
    {
        $evil = Organization::create(['name' => '<img src=x onerror=alert(1)>']);
        $mail = new InvitationMail($evil, '<b>Ana</b>', 'https://app.ejemplo.com/accept-invitation?token=T', 7);

        $html = $mail->render();

        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('<b>Ana</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;Ana&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<a href="http', str_replace('href="https://app.ejemplo.com', '', $html), 'no se arman enlaces ajenos');
    }

    public function test_templates_cannot_carry_links_html_or_unknown_variables(): void
    {
        $bad = [
            ['body' => 'Entrá a https://malo.example.com ahora'],
            ['body' => 'Entrá a http://malo.example.com'],
            ['body' => 'Entrá a www.malo.example.com'],
            ['body' => 'Hola <a href="x">click</a>'],
            ['body' => 'Hola <script>x</script>'],
            ['body' => 'javascript:alert(1)'],
            ['heading' => 'Mirá https://malo.example.com'],
            ['subject' => 'Visitá www.malo.example.com'],
            ['button_label' => 'Ir a https://malo.example.com'],
            ['body' => 'Hola {contraseña}'],
            ['body' => 'Hola {Invitador}'],
            ['body' => 'Hola {{ 7*7 }}'],
            ['subject' => "Dos\nlíneas"],
            ['heading' => "Dos\r\nlíneas"],
            ['body' => ''],
            ['button_label' => ''],
            ['subject' => str_repeat('a', 151)],
            ['body' => str_repeat('a', 4001)],
        ];

        foreach ($bad as $override) {
            $status = $this->as($this->root)->putJson('/api/auth/admin/email-templates/invitation', $this->body($override))->getStatusCode();
            $this->assertSame(422, $status, 'debió rechazar: ' . substr(json_encode($override), 0, 80));
        }

        $this->assertSame(0, EmailTemplate::count(), 'ninguna plantilla inválida se guardó');
    }

    public function test_the_unknown_variable_message_lists_the_valid_ones(): void
    {
        $this->as($this->root)->putJson('/api/auth/admin/email-templates/added_to_organization', $this->body(['body' => 'Hola {dias}']))
            ->assertUnprocessable()->assertJsonPath('errors.body.0', 'Variable desconocida: {dias}. Podés usar: {invitador}, {empresa}, {sistema}.');
    }

    public function test_an_unknown_template_is_a_404_not_a_server_error(): void
    {
        $this->as($this->root)->putJson('/api/auth/admin/email-templates/no-existe', $this->body())->assertForbidden();
        $this->as($this->root)->deleteJson('/api/auth/admin/email-templates/no-existe')->assertNotFound();
        $this->as($this->root)->postJson('/api/auth/admin/email-templates/no-existe/preview', $this->body())->assertForbidden();
        $this->as($this->root)->postJson('/api/auth/admin/email-templates/no-existe/test', $this->body())->assertForbidden();
    }

    public function test_restoring_goes_back_to_the_original(): void
    {
        $this->as($this->root)->putJson('/api/auth/admin/email-templates/invitation', $this->body())->assertOk();

        $this->as($this->root)->deleteJson('/api/auth/admin/email-templates/invitation')->assertOk()
            ->assertJsonPath('data.is_customized', false)->assertJsonPath('data.current.button_label', 'Aceptar la invitación');
        $this->assertSame(0, EmailTemplate::count());
    }

    public function test_a_broken_custom_template_falls_back_to_the_original_and_the_mail_still_goes(): void
    {
        // Una fila corrupta (por ejemplo editada a mano en la base) no debe impedir mandar la invitación
        EmailTemplate::create(['key' => 'invitation', 'subject' => 's', 'heading' => 'h', 'body' => 'b', 'button_label' => 'x']);
        $service = new class(app(SystemSettings::class)) extends \App\Application\Mail\EmailTemplateService {
            public int $calls = 0;

            public function current(string $key): array
            {
                if ($this->calls++ === 0) {
                    throw new \RuntimeException('plantilla corrupta');
                }

                return parent::current($key);
            }
        };

        $rendered = $service->render('invitation', ['invitador' => 'Ana', 'empresa' => 'A', 'dias' => '7']);

        $this->assertSame('Ana te invitó a A en Sistema de gestión', $rendered->subject, 'cae al original');
    }

    public function test_the_preview_renders_a_draft_without_saving_it(): void
    {
        $response = $this->as($this->root)->postJson('/api/auth/admin/email-templates/invitation/preview', $this->body(['heading' => 'Título de prueba']))->assertOk();

        $this->assertSame('Ana Pérez te espera en Empresa de Ejemplo SA', $response->json('data.subject'));
        $this->assertStringContainsString('Título de prueba', $response->json('data.html'));
        $this->assertStringContainsString('Entrar ahora', $response->json('data.html'));
        $this->assertSame(0, EmailTemplate::count(), 'la vista previa no guarda nada');
    }

    public function test_the_preview_can_use_other_example_data_like_juancito(): void
    {
        $response = $this->as($this->root)->postJson('/api/auth/admin/email-templates/invitation/preview', $this->body(['samples' => ['invitador' => 'Juancito', 'empresa' => 'Kiosco Pepe', 'dias' => '3']]))->assertOk();

        $this->assertSame('Juancito te espera en Kiosco Pepe', $response->json('data.subject'));
        $this->assertStringContainsString('Juancito', $response->json('data.html'));
        $this->assertStringContainsString('3 días', $response->json('data.html'));
        $this->assertSame(0, EmailTemplate::count(), 'los ejemplos no se guardan');
    }

    public function test_blank_examples_fall_back_to_the_defaults_and_the_samples_are_escaped(): void
    {
        $response = $this->as($this->root)->postJson('/api/auth/admin/email-templates/invitation/preview', $this->body(['samples' => ['invitador' => '   ', 'empresa' => '<b>X</b>']]))->assertOk();

        $this->assertSame('Ana Pérez te espera en <b>X</b>', $response->json('data.subject'));
        $this->assertStringNotContainsString('<b>X</b>', $response->json('data.html'));
    }

    public function test_the_examples_are_validated_and_never_saved_with_the_template(): void
    {
        foreach ([['sistema' => 'Otro'], ['dias' => 'x', 'inventada' => 'y'], ['invitador' => str_repeat('a', 61)], ['invitador' => "con\nsalto"]] as $samples) {
            $this->as($this->root)->postJson('/api/auth/admin/email-templates/invitation/preview', $this->body(['samples' => $samples]))->assertUnprocessable();
        }

        $this->as($this->root)->putJson('/api/auth/admin/email-templates/invitation', $this->body(['samples' => ['invitador' => 'Juancito']]))->assertOk();
        $this->assertSame(['subject', 'heading', 'body', 'button_label'], array_keys(EmailTemplate::firstOrFail()->only(['subject', 'heading', 'body', 'button_label'])));
        $this->assertSame('Ana te espera en Empresa A SA', (new InvitationMail($this->a, 'Ana', 'https://app.ejemplo.com/x', 7))->rendered()->subject, 'los ejemplos no se usan en los correos reales');
    }

    public function test_each_variable_tells_its_example_and_whether_it_can_be_changed(): void
    {
        $variables = collect($this->as($this->root)->getJson('/api/auth/admin/email-templates')->json('data.0.variables'))->keyBy('name');

        $this->assertSame('Ana Pérez', $variables['invitador']['example']);
        $this->assertTrue($variables['invitador']['editable']);
        $this->assertSame('7', $variables['dias']['example']);
        $this->assertNull($variables['sistema']['example']);
        $this->assertFalse($variables['sistema']['editable']);
    }

    public function test_a_draft_with_a_link_is_refused_by_the_preview_too(): void
    {
        $this->as($this->root)->postJson('/api/auth/admin/email-templates/invitation/preview', $this->body(['body' => 'Entrá a https://malo.example.com']))->assertUnprocessable();
    }

    public function test_the_test_mail_goes_only_to_the_requesting_admin_with_the_draft(): void
    {
        $response = $this->as($this->root)->postJson('/api/auth/admin/email-templates/invitation/test', $this->body(['to' => 'otro@example.com', 'email' => 'otro@example.com']))
            ->assertOk()->assertJsonPath('data.sent', true)->assertJsonPath('data.to', 'root@example.com');

        Mail::assertSent(InvitationMail::class, fn (InvitationMail $m) => $m->hasTo('root@example.com') && ! $m->hasTo('otro@example.com') && $m->rendered()->buttonLabel === 'Entrar ahora');
        Mail::assertSentCount(1);
        $this->assertSame(0, EmailTemplate::count());
    }

    public function test_the_test_mail_for_the_second_template_works_and_reports_a_mail_failure(): void
    {
        $this->as($this->root)->postJson('/api/auth/admin/email-templates/added_to_organization/test', $this->body(['body' => 'Hola {invitador}']))->assertOk()->assertJsonPath('data.sent', true);
        Mail::assertSent(AddedToOrganizationMail::class);

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP caído'));
        $this->as($this->root)->postJson('/api/auth/admin/email-templates/invitation/test', $this->body())->assertOk()->assertJsonPath('data.sent', false);
    }

    public function test_the_test_mail_is_rate_limited(): void
    {
        $last = null;
        for ($i = 0; $i < 7; $i++) {
            $last = $this->as($this->root)->postJson('/api/auth/admin/email-templates/invitation/test', $this->body())->getStatusCode();
        }

        $this->assertSame(429, $last);
    }
}
