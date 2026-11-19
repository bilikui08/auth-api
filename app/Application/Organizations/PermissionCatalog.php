<?php

namespace App\Application\Organizations;

use Illuminate\Support\Facades\DB;

/**
 * Catálogo de permisos y de los roles de empresa que vienen de fábrica.
 *
 * Un permiso se llama `modulo.recurso.accion` (ej. `sales.invoice.annul`): cada módulo del ERP declara los suyos y
 * los servicios (billing, etc.) chequean PERMISOS, nunca roles. Un rol es solo un grupo de permisos que un
 * administrador puede armar sin tocar código.
 *
 * Para agregar un permiso: sumarlo en PERMISSIONS (y en los roles que correspondan) y correr
 * `php artisan permissions:sync`. La sincronización solo agrega: no quita permisos que alguien haya ajustado a mano.
 */
final class PermissionCatalog
{
    /** @var array<string, string> nombre => descripción */
    public const PERMISSIONS = [
        'sales.invoice.view' => 'Ver comprobantes',
        'sales.invoice.issue' => 'Emitir comprobantes',
        'sales.invoice.annul' => 'Anular comprobantes (nota de crédito)',
        'sales.customer.view' => 'Ver clientes',
        'sales.customer.manage' => 'Crear, editar y eliminar clientes',
        'sales.sale-point.manage' => 'Administrar puntos de venta',
        'sales.billing-profile.manage' => 'Administrar el perfil de facturación',
        'sales.trash.restore-any' => 'Restaurar lo eliminado por otras personas',
        'sales.audit.view-all' => 'Ver la auditoría completa de la empresa',
        'sales.settings.manage' => 'Cambiar la configuración de la empresa',
    ];

    /** Rol de fábrica que tiene todos los permisos: el dueño de la empresa. */
    public const COMPANY_ADMIN = 'company_admin';

    /**
     * @var array<string, array{description: string, permissions: list<string>|'*'}>
     */
    public const ROLES = [
        self::COMPANY_ADMIN => ['description' => 'Administrador de la empresa', 'permissions' => '*'],
        'seller' => [
            'description' => 'Vendedor: factura y administra clientes',
            'permissions' => [
                'sales.invoice.view', 'sales.invoice.issue', 'sales.customer.view', 'sales.customer.manage',
            ],
        ],
        'accountant' => [
            'description' => 'Contador: ve todo y anula comprobantes',
            'permissions' => [
                'sales.invoice.view', 'sales.invoice.annul', 'sales.customer.view', 'sales.audit.view-all',
            ],
        ],
        'viewer' => [
            'description' => 'Lector: solo consulta',
            'permissions' => ['sales.invoice.view', 'sales.customer.view'],
        ],
    ];

    /** Agrega lo que falte (permisos, roles de fábrica y sus asignaciones). Idempotente. */
    public static function sync(): void
    {
        $now = now();

        foreach (self::PERMISSIONS as $name => $description) {
            DB::table('permissions')->insertOrIgnore([
                'name' => $name,
                'module' => explode('.', $name)[0],
                'description' => $description,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $permissionIds = DB::table('permissions')->pluck('id', 'name');

        foreach (self::ROLES as $name => $role) {
            DB::table('roles')->insertOrIgnore([
                'name' => $name, 'description' => $role['description'], 'created_at' => $now, 'updated_at' => $now,
            ]);

            $roleId = DB::table('roles')->where('name', $name)->value('id');
            $names = $role['permissions'] === '*' ? array_keys(self::PERMISSIONS) : $role['permissions'];

            foreach ($names as $permission) {
                DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionIds[$permission]]);
            }
        }
    }
}
