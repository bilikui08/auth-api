<?php

namespace App\Application\Mail;

/**
 * Plantillas de correo que existen, con su original, las variables que admiten y valores de ejemplo para la vista previa.
 * Para sumar una plantilla (por ejemplo "restablecer contraseña") alcanza con agregarla acá y usarla desde su Mailable.
 *
 * El enlace del botón NO es una variable del texto: lo agrega el sistema (así un texto editado no puede llevar enlaces propios).
 */
final class EmailTemplateRegistry
{
    public const INVITATION = 'invitation';
    public const ADDED_TO_ORGANIZATION = 'added_to_organization';

    /**
     * @return array<string, array{label: string, description: string, variables: array<string, string>, samples: array<string, string>, defaults: array{subject: string, heading: string, body: string, button_label: string}}>
     */
    public static function definitions(): array
    {
        return [
            self::INVITATION => [
                'label' => 'Invitación a una empresa',
                'description' => 'Le llega a una persona que todavía no tiene cuenta, con el botón para crearla.',
                'variables' => [
                    'invitador' => 'Nombre de quien invita',
                    'empresa' => 'Nombre de la empresa',
                    'sistema' => 'Nombre del sistema',
                    'dias' => 'Días de vigencia del enlace',
                ],
                'samples' => ['invitador' => 'Ana Pérez', 'empresa' => 'Empresa de Ejemplo SA', 'sistema' => '', 'dias' => '7'],
                'defaults' => [
                    'subject' => '{invitador} te invitó a {empresa} en {sistema}',
                    'heading' => 'Te invitaron a {sistema}',
                    'body' => "**{invitador}** te invitó a trabajar con los datos de **{empresa}** en {sistema}.\n\nVas a elegir tu nombre y una contraseña. El enlace vale {dias} días y se puede usar una sola vez.\n\nSi no esperabas esta invitación, ignorá este mensaje: sin aceptarla no se crea ninguna cuenta.",
                    'button_label' => 'Aceptar la invitación',
                ],
            ],
            self::ADDED_TO_ORGANIZATION => [
                'label' => 'Te sumaron a una empresa',
                'description' => 'Le llega a una persona que ya tenía cuenta y fue sumada a una empresa.',
                'variables' => [
                    'invitador' => 'Nombre de quien la sumó',
                    'empresa' => 'Nombre de la empresa',
                    'sistema' => 'Nombre del sistema',
                ],
                'samples' => ['invitador' => 'Ana Pérez', 'empresa' => 'Empresa de Ejemplo SA', 'sistema' => ''],
                'defaults' => [
                    'subject' => 'Ahora podés trabajar con {empresa} en {sistema}',
                    'heading' => 'Ahora trabajás con {empresa}',
                    'body' => "**{invitador}** te sumó a **{empresa}** en {sistema}. La próxima vez que ingreses vas a poder elegir esa empresa.\n\nSi no esperabas este aviso, avisale a quien administra la empresa.",
                    'button_label' => 'Ingresar',
                ],
            ],
        ];
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::definitions());
    }

    /** @return array<string, mixed> */
    public static function definition(string $key): array
    {
        return self::definitions()[$key] ?? throw new \InvalidArgumentException("Plantilla desconocida: {$key}");
    }
}
