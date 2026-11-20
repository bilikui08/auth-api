<?php

use App\Application\Organizations\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cuenta corriente de clientes (billing): `sales.payment.view`, `sales.payment.manage` y `sales.payment.allocate`.
 * El administrador de la empresa los recibe porque tiene todos los permisos; el contador recibe solo `view`.
 */
return new class extends Migration
{
    public function up(): void
    {
        PermissionCatalog::sync();
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('name', ['sales.payment.view', 'sales.payment.manage', 'sales.payment.allocate'])->delete();
    }
};
