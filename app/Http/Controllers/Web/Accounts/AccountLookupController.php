<?php

namespace App\Http\Controllers\Web\Accounts;

use App\Http\Controllers\Controller;
use App\Models\Account;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * P2-001 (code review pachetul „contacte") — endpoint JSON pentru `AccountCombobox`,
 * folosit de formularul de contact (și gândit pentru cel de deal). Marlin are ~4.000
 * de conturi (specs_si_design) — un „Account ID" text liber în care se lipește un
 * ULID e inutilizabil într-un demo public.
 *
 * Autorizare pe `accounts.view` (aceeași poartă ca `AccountPolicy::viewAny`) — un
 * căutător de conturi e tot o CITIRE de conturi, nu un drept separat. Izolarea de
 * tenant vine gratuit din `Account::query()` (global scope Eloquent) + RLS, ADR-003:
 * ambele straturi, ca peste tot în restul modulului.
 */
final class AccountLookupController extends Controller
{
    private const MAX_RESULTS = 20;

    public function __invoke(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Account::class);

        // Aceeași limită de lungime ca `ListQuery::filter()` (`mb_substr(..., 0, 100)`) —
        // un query de căutare nu are nevoie de mai mult, iar un termen absurd de lung
        // nu trebuie să ajungă în clauza `ilike`.
        $raw = $request->query('q');
        $q = is_string($raw) ? mb_substr(trim($raw), 0, 100) : '';

        $accounts = Account::query()
            ->select(['id', 'name', 'domain'])
            ->when($q !== '', fn ($query) => $query->where('name', 'ilike', '%'.addcslashes($q, '%_\\').'%'))
            ->orderBy('name')
            ->limit(self::MAX_RESULTS)
            ->get();

        return response()->json([
            'data' => $accounts->map(fn (Account $account): array => [
                'id' => $account->id,
                'name' => $account->name,
                'domain' => $account->domain,
            ])->all(),
        ]);
    }
}
