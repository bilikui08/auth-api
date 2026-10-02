<?php

namespace App\Http\Requests\Auth;

use App\Domain\Auth\DTO\LogoutData;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Http\FormRequest;

final class LogoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [];
    }

    public function toDto(): LogoutData
    {
        $tokenId = $this->user()?->token()?->oauth_access_token_id;

        if (! is_string($tokenId) || $tokenId === '') {
            throw new AuthenticationException;
        }

        return new LogoutData($tokenId);
    }
}
