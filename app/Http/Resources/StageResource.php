<?php

namespace App\Http\Resources;

use App\Models\Stage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Etapa unei pipeline, pentru ecranul de configurare (FR-DEAL-02). `dealsCount` vine din
 * `withCount('deals')` în controller — evită N+1 pe fiecare rând al listei.
 *
 * `canDelete` e propul PER ETAPĂ cerut de Pachetul D, separat de `can.manage` de pe pagină
 * (props Inertia, regula 2): azi valoarea e identică pentru toate etapele (nu există
 * ownership pe etape, §7.5), dar rămâne pe etapă, nu doar pe pagină, ca policy-ul să poată
 * evolua fără să schimbe contractul.
 *
 * NU conține motivul de refuz al ștergerii ca „drept" — vezi `deletionBlockedReason`, care e
 * o stare (BR-DEAL-01), nu o permisiune: butonul „Delete" rămâne prezent cât timp
 * `canDelete` e true, iar mesajul explică DE CE serverul l-ar refuza, nu ascunde butonul.
 *
 * @mixin Stage
 */
class StageResource extends JsonResource
{
    /**
     * @return array{
     *     id: string,
     *     name: string,
     *     position: int,
     *     isWon: bool,
     *     isLost: bool,
     *     probability: int|null,
     *     dealsCount: int,
     *     canDelete: bool,
     *     deletionBlockedReason: string|null,
     * }
     */
    public function toArray(Request $request): array
    {
        // `withCount('deals')` în controller populează `deals_count` — fără el, fiecare rând
        // ar mai face o interogare (N+1) doar ca să afle dacă poate fi șters.
        $dealsCount = (int) ($this->deals_count ?? $this->deals()->count());

        return [
            'id' => $this->id,
            'name' => $this->name,
            'position' => $this->position,
            'isWon' => $this->is_won,
            'isLost' => $this->is_lost,
            'probability' => $this->probability,
            'dealsCount' => $dealsCount,
            'canDelete' => $request->user()?->can('delete', $this->resource) ?? false,
            'deletionBlockedReason' => $this->deletionBlockedReason($dealsCount),
        ];
    }
}
