<?php

namespace App\Http\Requests\Auth;

use App\Domain\Auth\DTO\RefreshTokenData;
use Illuminate\Foundation\Http\FormRequest;

final class RefreshTokenRequest extends FormRequest
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
        return [
            'refresh_token' => ['required', 'string'],
        ];
    }

    public function toDto(): RefreshTokenData
    {
        return new RefreshTokenData((string) $this->validated('refresh_token'));
    }
}
