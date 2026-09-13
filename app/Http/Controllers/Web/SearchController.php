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
        $term = mb_substr(trim((string) $request->query('q', '')), 0, GlobalSearchService::MAX_QUERY_LENGTH);

        $payload = mb_strlen($term) < GlobalSearchService::MIN_QUERY_LENGTH
            ? $search->initialState($request)
            : $search->search($request->user(), $term);

        return response()->json($payload);
    }
}
