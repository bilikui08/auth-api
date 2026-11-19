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
        $this->templates->save($key, $request->validated(), $request->user()->id);

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
        $mail = $this->sampleMail($key, $request->validated());

        return response()->json(['message' => 'Vista previa.', 'data' => ['subject' => $mail->rendered()->subject, 'html' => $mail->render()]]);
    }

    /**
     * Manda el correo de ejemplo, con el borrador que se está editando (validado igual que al guardar), a la casilla de quien lo pide
     * (nunca a otra: así no se usa el sistema para mandar correos a terceros).
     */
    public function test(SaveEmailTemplateRequest $request, string $key): JsonResponse
    {
        $mail = $this->sampleMail($key, $request->validated());

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

    /** @param array<string, string>|null $draft */
    private function sampleMail(string $key, ?array $draft): TemplatedMail
    {
        $this->guard($key);
        $samples = EmailTemplateRegistry::definition($key)['samples'];
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
            'variables' => collect($definition['variables'])->map(fn (string $description, string $name): array => ['name' => $name, 'description' => $description])->values()->all(),
            'is_customized' => $this->templates->isCustomized($key),
            'current' => $this->templates->current($key),
            'defaults' => $this->templates->defaults($key),
        ];
    }
}
