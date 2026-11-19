<?php

namespace App\Http\Controllers;

use App\Application\Mail\EmailTemplateRegistry;
use App\Application\Mail\EmailTemplateService;
use App\Http\Requests\System\SaveEmailTemplateRequest;
use App\Mail\AddedToOrganizationMail;
use App\Mail\InvitationMail;
use App\Mail\TemplatedMail;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Plantillas de correo editables (administración del sistema): listar, guardar, volver al original, vista previa y prueba de envío. */
final class EmailTemplateController extends Controller
{
    public function __construct(private readonly EmailTemplateService $templates) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'message' => 'Plantillas de correo.',
            'data' => array_map(fn (string $key): array => $this->present($key), array_keys(EmailTemplateRegistry::definitions())),
        ]);
    }

    public function update(SaveEmailTemplateRequest $request, string $key): JsonResponse
    {
        $this->templates->save($key, $this->draftOf($request), $request->user()->id);

        return response()->json(['message' => 'Plantilla guardada.', 'data' => $this->present($key)]);
    }

    /** Vuelve al original. */
    public function restore(string $key): JsonResponse
    {
        $this->guard($key);
        $this->templates->restore($key);

        return response()->json(['message' => 'Se restauró el original.', 'data' => $this->present($key)]);
    }

    /** Vista previa de un borrador (sin guardarlo) con datos de ejemplo: asunto y el correo completo en HTML. */
    public function preview(SaveEmailTemplateRequest $request, string $key): JsonResponse
    {
        $mail = $this->sampleMail($key, $this->draftOf($request), $request->validated('samples', []));

        return response()->json(['message' => 'Vista previa.', 'data' => ['subject' => $mail->rendered()->subject, 'html' => $mail->render()]]);
    }

    /**
     * Manda el correo de ejemplo, con el borrador que se está editando (validado igual que al guardar), a la casilla de quien lo pide
     * (nunca a otra: así no se usa el sistema para mandar correos a terceros).
     */
    public function test(SaveEmailTemplateRequest $request, string $key): JsonResponse
    {
        $mail = $this->sampleMail($key, $this->draftOf($request), $request->validated('samples', []));

        try {
            Mail::to($request->user()->email)->send($mail);
            $sent = true;
        } catch (Throwable $e) {
            report($e);
            $sent = false;
        }

        return response()->json([
            'message' => $sent ? 'Correo de prueba enviado.' : 'No se pudo enviar el correo de prueba: revisá la configuración de correo del servidor.',
            'data' => ['sent' => $sent, 'to' => $request->user()->email],
        ]);
    }

    private function guard(string $key): void
    {
        abort_unless(EmailTemplateRegistry::exists($key), 404);
    }

    /** Los cuatro textos de la plantilla (sin los datos de ejemplo, que no se guardan). @return array<string, string> */
    private function draftOf(SaveEmailTemplateRequest $request): array
    {
        return $request->safe()->only(['subject', 'heading', 'body', 'button_label']);
    }

    /**
     * @param array<string, string>|null $draft
     * @param array<string, string|null> $overrides Datos de ejemplo que eligió quien edita (los vacíos usan el ejemplo por defecto)
     */
    private function sampleMail(string $key, ?array $draft, array $overrides = []): TemplatedMail
    {
        $this->guard($key);
        $samples = array_filter($overrides, fn ($v) => is_string($v) && trim($v) !== '') + EmailTemplateRegistry::definition($key)['samples'];
        $organization = new Organization(['name' => $samples['empresa']]);
        $url = rtrim((string) config('app.frontend_url'), '/');

        $mail = $key === EmailTemplateRegistry::INVITATION
            ? new InvitationMail($organization, $samples['invitador'], $url . '/accept-invitation?token=EJEMPLO', (int) $samples['dias'])
            : new AddedToOrganizationMail($organization, $samples['invitador'], $url . '/');
        $mail->draft = $draft;

        return $mail;
    }

    /** @return array<string, mixed> */
    private function present(string $key): array
    {
        $definition = EmailTemplateRegistry::definition($key);

        return [
            'key' => $key,
            'label' => $definition['label'],
            'description' => $definition['description'],
            // `example`: con qué dato se ve en la vista previa; `editable`: se puede probar con otro (el nombre del sistema sale de los ajustes)
            'variables' => collect($definition['variables'])->map(fn (string $description, string $name): array => [
                'name' => $name,
                'description' => $description,
                'example' => $name === 'sistema' ? null : $definition['samples'][$name],
                'editable' => $name !== 'sistema',
            ])->values()->all(),
            'is_customized' => $this->templates->isCustomized($key),
            'current' => $this->templates->current($key),
            'defaults' => $this->templates->defaults($key),
        ];
    }
}
