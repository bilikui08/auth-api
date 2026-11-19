<?php

namespace App\Console\Commands;

use App\Application\Organizations\PermissionCatalog;
use Illuminate\Console\Command;

/** Agrega al sistema los permisos y roles de fábrica que falten (idempotente; no quita nada). */
final class SyncPermissions extends Command
{
    protected $signature = 'permissions:sync';

    protected $description = 'Sincroniza el catálogo de permisos y los roles de empresa de fábrica';

    public function handle(): int
    {
        PermissionCatalog::sync();

        $this->info(sprintf('Catálogo sincronizado: %d permisos, %d roles de fábrica.', count(PermissionCatalog::PERMISSIONS), count(PermissionCatalog::ROLES)));

        return self::SUCCESS;
    }
}
