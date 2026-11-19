<?php

namespace App\Http\Controllers;

use App\Application\System\SystemSettings;
use App\Http\Requests\System\UpdateSystemRequest;
use Illuminate\Http\JsonResponse;

/** Ajustes de la plataforma (administración del sistema) y el dato público que necesita el login. */
final class SystemController extends Controller
{
    public function __construct(private readonly SystemSettings $system) {}

    public function show(): JsonResponse
    {
        return response()->json(['message' => 'Ajustes del sistema.', 'data' => $this->present()]);
    }

    public function update(UpdateSystemRequest $request): JsonResponse
    {
        $this->system->setSystemName($request->validated('system_name'), $request->user()->id);

        return response()->json(['message' => 'Ajustes guardados.', 'data' => $this->present()]);
    }

    /** Público: el login muestra el nombre del sistema antes de que la persona se autentique. Solo el nombre, nada más. */
    public function branding(): JsonResponse
    {
        return response()->json(['message' => 'Marca.', 'data' => ['system_name' => $this->system->systemName()]]);
    }

    /** @return array<string, mixed> */
    private function present(): array
    {
        return [
            'system_name' => $this->system->systemName(),
            'default_system_name' => $this->system->defaultName(),
            'is_default' => $this->system->systemName() === $this->system->defaultName(),
            'max_length' => SystemSettings::NAME_MAX,
        ];
    }
}
