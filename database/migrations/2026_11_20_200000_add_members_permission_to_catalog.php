<?php

use App\Application\Organizations\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Agrega `org.members.manage` al catálogo (el administrador de la empresa lo recibe porque tiene todos los permisos). */
return new class extends Migration
{
    public function up(): void
    {
        PermissionCatalog::sync();
    }

    public function down(): void
    {
        DB::table('permissions')->where('name', 'org.members.manage')->delete();
    }
};
