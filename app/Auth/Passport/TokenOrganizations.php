<?php

namespace App\Auth\Passport;

use Illuminate\Support\Facades\DB;

/** Registro de con qué empresa se emitió cada access token (para que el refresh la conserve). */
final class TokenOrganizations
{
    public function remember(string $accessTokenId, int $organizationId): void
    {
        DB::table('oauth_token_organizations')->updateOrInsert(
            ['access_token_id' => $accessTokenId],
            ['organization_id' => $organizationId, 'created_at' => now()],
        );
    }

    public function organizationOf(string $accessTokenId): ?int
    {
        $id = DB::table('oauth_token_organizations')->where('access_token_id', $accessTokenId)->value('organization_id');

        return $id === null ? null : (int) $id;
    }
}
