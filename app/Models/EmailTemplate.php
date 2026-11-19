<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Versión personalizada de una plantilla de correo. Ver App\Application\Mail\EmailTemplateService. */
class EmailTemplate extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<User, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
