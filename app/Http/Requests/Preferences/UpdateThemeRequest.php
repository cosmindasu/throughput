<?php

namespace App\Http\Requests\Preferences;

use App\Support\ThemePreference;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PATCH /preferences/theme` — FR-PREF-01…03. Disponibil tuturor rolurilor (BR-PREF-02):
 * `authorize()` verifică doar autentificarea, nimic din matricea §7.4 — comutarea temei
 * nu e o acțiune de scriere de business.
 */
class UpdateThemeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'theme' => ['required', 'string', Rule::in(ThemePreference::CHOICES)],

            // Trimis de client DOAR pentru alegerea „system" (resources/js/Components/ThemeToggle.tsx):
            // `matchMedia`, rulat chiar în browser-ul care face cererea, e singurul semnal de
            // încredere despre preferința de sistem — vezi App\Support\ThemePreference.
            'resolvedTheme' => ['nullable', 'string', Rule::in(ThemePreference::RESOLVED)],
        ];
    }
}
