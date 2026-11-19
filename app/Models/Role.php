<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'description'])]
class Role extends Model
{
    use HasFactory;

    public const ADMIN = 'admin';

    public const USER = 'user';

    /** Roles que no se pueden borrar ni renombrar. */
    public const SYSTEM = [self::ADMIN, self::USER, 'company_admin'];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /** @return BelongsToMany<Permission, $this> */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function isSystem(): bool
    {
        return in_array($this->name, self::SYSTEM, true);
    }
}
