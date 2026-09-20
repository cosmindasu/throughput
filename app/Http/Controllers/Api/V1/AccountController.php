<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\Api\V1\AccountResource;
use App\Models\Account;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/accounts` (specs.md §18).
 *
 * Doar citire: FR-API-01 nu numește niciun scop de scriere pe conturi, iar API-ul nu
 * inventează unul. Ruta există fiindcă `POST /orders` cere un `account_id` valid — fără
 * ea, scopul `orders:write` ar fi nefolosibil de un consumator care nu are deja ID-urile.
 */
final class AccountController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Account::class);

        $accounts = Account::query()
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            // `.ai/rules/tenancy.md` — `created_at` are precizie 0, deci ordonarea
            // are nevoie de un tiebreaker stabil; ULID-ul îl oferă gratuit.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return $this->paginated($request, $accounts, AccountResource::class);
    }

    public function show(Request $request, string $account): JsonResponse
    {
        $model = $this->findForTenant(Account::query(), $account);

        $this->authorize('view', $model);

        return $this->item($request, new AccountResource($model));
    }
}
