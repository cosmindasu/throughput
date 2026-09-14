<?php

namespace App\Http\Requests\Bulk;

use App\Models\Membership;
use App\Support\ListQuery;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * §13.1 — „Select all matching filter" vs. checkbox de pagină curentă: `selectAllMatching`
 * decide care dintre cele două. Când e `false`, `ids` e setul explicit (checkbox-urile
 * bifate pe pagina curentă — cel mult `ListQuery::PER_PAGE`, niciodată tot filtrul).
 */
class ReassignOwnerRequest extends FormRequest
{
    /**
     * Policy-ul decide (`BulkOperationController::reassignOwner()`, `AccountPolicy`/
     * `DealPolicy::bulkReassignOwner()`) — resursa (accounts/deals) nu e cunoscută decât
     * din ruta rezolvată în controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'selectAllMatching' => ['required', 'boolean'],
            'ids' => ['required_if:selectAllMatching,false', 'array', 'max:'.ListQuery::PER_PAGE],
            'ids.*' => ['string', 'ulid'],
            'owner_user_id' => [
                'required',
                'string',
                $this->activeMembershipRule(),
            ],
            // P2-002 (code review), FR-BULK-01 — clientul trimite `true` DOAR după ce
            // dialogul de confirmare a fost acceptat (peste `BulkConfirmationThreshold`);
            // absent/`false` peste prag => `DispatchBulkOperationAction` refuză.
            'confirmed' => ['sometimes', 'boolean'],
        ];
    }

    /** @return list<string>|null null = tot filtrul curent (mod „Select all matching") */
    public function idsOrNull(): ?array
    {
        return $this->boolean('selectAllMatching') ? null : $this->input('ids', []);
    }

    public function confirmed(): bool
    {
        return $this->boolean('confirmed');
    }

    /**
     * NU `Rule::exists('memberships', ...)`: acel Rule rulează SQL BRUT — RLS se aplică
     * (deci nu scapă date din alt tenant), dar politica proprie a lui `memberships`
     * (ADR-014, pct. 2) e un `OR`: „rândurile mele de membership, oriunde, SAU rândurile
     * tenantului curent". Sub acel `OR`, `Rule::exists` ar valida drept „membru activ"
     * și un `owner_user_id` care e chiar UTILIZATORUL CURENT, dar membru într-un ALT
     * tenant — fals pozitiv. O interogare Eloquent aplică ȘI global scope-ul de tenant
     * (stratul 1, ADR-003), nu doar RLS (stratul 2), deci verifică apartenența la
     * TENANTUL CURENT, nu doar existența rândului undeva.
     */
    private function activeMembershipRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $isActiveMember = Membership::query()
                ->where('user_id', $value)
                ->where('status', Membership::STATUS_ACTIVE)
                ->exists();

            if (! $isActiveMember) {
                $fail('The selected owner is not an active member of this workspace.');
            }
        };
    }
}
