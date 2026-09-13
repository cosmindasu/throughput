<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * `auth.user` — plan §1.2 regula 1 (niciun model brut în `Inertia::render()`/`share()`).
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array{id: string, name: string, email: string, theme: string, initials: string, dismissedHints: list<string>}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'theme' => $this->theme,
            'initials' => $this->initials(),
            // BR-HELP-02 — indicii de primă vizită respinși, per utilizator (nu per
            // tenant, la fel ca `theme`). Cast `array` pe model; `?? []` doar pentru
            // rândurile mai vechi decât coloana, dacă există.
            'dismissedHints' => $this->dismissed_hints ?? [],
        ];
    }

    private function initials(): string
    {
        return (string) collect(preg_split('/\s+/', trim((string) $this->name)))
            ->filter()
            ->map(fn (string $part) => Str::upper(Str::substr($part, 0, 1)))
            ->take(2)
            ->implode('');
    }
}
