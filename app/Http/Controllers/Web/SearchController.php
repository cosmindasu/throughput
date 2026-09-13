<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\Search\GlobalSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * FR-SEARCH-01/02 — `GET /{workspace}/search`. JSON simplu, nu Inertia: paleta Cmd+K
 * (`GlobalSearch.tsx`) interoghează din orice ecran, fără să navigheze pagina curentă.
 *
 * Fără cod de tenant aici (§ instrucțiuni pachet G): izolarea vine din global scope +
 * RLS, aplicate deja pe fiecare query din `GlobalSearchService`.
 */
class SearchController extends Controller
{
    public function index(Request $request, GlobalSearchService $search): JsonResponse
    {
        // `?q[]=x` face `query('q')` să întoarcă un array: `(string) $array` dă „Array to
        // string conversion" (P3) — și `Illuminate\Support\Stringable` (deci și
        // `$request->string()`) face EXACT același cast intern, nu evită problema. Verificarea
        // explicită `is_string` e singura formă care nu aruncă pe acest input.
        $raw = $request->query('q', '');
        $term = mb_substr(trim(is_string($raw) ? $raw : ''), 0, GlobalSearchService::MAX_QUERY_LENGTH);

        $payload = mb_strlen($term) < GlobalSearchService::MIN_QUERY_LENGTH
            ? $search->initialState($request)
            : $search->search($request->user(), $term);

        return response()->json($payload);
    }
}
