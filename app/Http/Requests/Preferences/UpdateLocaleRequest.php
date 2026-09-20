<?php

namespace App\Http\Requests\Preferences;

use App\Support\LocalePreference;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PATCH /preferences/locale` — ADR-022, specs.md §15.8 FR-I18N-01. Pe modelul exact al
 * lui `UpdateThemeRequest`: `authorize()` verifică doar autentificarea, nimic din matricea
 * §7.4 — comutarea limbii, ca și comutarea temei (BR-PREF-02), nu e o acțiune de scriere
 * de business.
 */
class UpdateLocaleRequest extends FormRequest
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
            'locale' => ['required', 'string', Rule::in(LocalePreference::CHOICES)],
        ];
    }
}
