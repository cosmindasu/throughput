<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * FR-PUB-05 — validarea formularului de setare a noii parole. Valorile implicite
 * Laravel 13 ale brokerului (expirare, throttle per email) se aplică în
 * `Password::broker()->reset()`, nu aici (specs §4.5, BR-PUB-02).
 */
class NewPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }
}
