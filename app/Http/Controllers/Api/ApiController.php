<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Baza controllerelor API-ului public v1 (specs.md §18, ADR-008).
 *
 * Două lucruri, o singură dată:
 *
 * 1. **Învelișul `{"data": …}`.** `AppServiceProvider::boot()` cheamă
 *    `JsonResource::withoutWrapping()` pentru props-urile Inertia (regula 1 din plan
 *    §1.2), cu nota explicită că „API-ul public din §18 își declară învelișul acolo, pe
 *    resursele lui". Un API public are însă nevoie de un plic stabil ca să poată adăuga
 *    `meta` fără să schimbe forma rădăcinii — deci plicul se construiește AICI, explicit,
 *    nu prin `public static $wrap` (care n-ar avea efect pe `AnonymousResourceCollection`,
 *    a cărei clasă e cea consultată de `ResourceResponse::wrapper()`, nu a resursei).
 *
 * 2. **Mitigarea BOLA (§18.5).** `find()` pe un model scopat pe tenant întoarce `null`
 *    pentru ID-ul valid al ALTUI tenant, iar `notFound()` îl transformă în `404` — NU în
 *    `403`, care ar confirma existența resursei. Nu e o subtilitate: e diferența dintre
 *    „nu-ți spun nimic" și „există, dar nu e a ta".
 */
abstract class ApiController extends Controller
{
    /** Plafonul de rânduri pe pagină — un consumator nu poate cere toată baza deodată. */
    protected const MAX_PER_PAGE = 100;

    protected const DEFAULT_PER_PAGE = 25;

    /**
     * Încarcă un model al tenantului curent sau aruncă `404`.
     *
     * Rutele API folosesc parametri `string`, nu binding implicit: grupul `api` rulează
     * `SubstituteBindings` ÎNAINTEA lui `ResolveTenantFromApiToken` (lista de prioritate
     * din `bootstrap/app.php` acoperă doar middleware-ul web), deci un parametru tipizat
     * ar interoga fără context și ar da 500. Vezi docblock-ul acelui middleware.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return TModel
     */
    protected function findForTenant(Builder $query, string $id): Model
    {
        $model = $query->find($id);

        if ($model === null) {
            throw (new ModelNotFoundException)->setModel($query->getModel()::class, [$id]);
        }

        return $model;
    }

    /**
     * Plicul unei liste paginate.
     *
     * @param  LengthAwarePaginator<int, Model>  $page
     * @param  class-string<JsonResource>  $resource
     */
    protected function paginated(Request $request, LengthAwarePaginator $page, string $resource): JsonResponse
    {
        return response()->json([
            // `resolve()`, nu `toArray()`: doar el trece rezultatul prin `filter()`, adică
            // scoate `MissingValue`-urile lăsate de `whenLoaded()`. Cu `toArray()`, o
            // relație neîncărcată ar ajunge serializată ca `{}` în JSON-ul public.
            'data' => $resource::collection($page->getCollection())->resolve($request),
            'meta' => [
                'page' => $page->currentPage(),
                'perPage' => $page->perPage(),
                'total' => $page->total(),
                'lastPage' => $page->lastPage(),
            ],
        ]);
    }

    /** Plicul unei resurse singulare. */
    protected function item(Request $request, JsonResource $resource, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $resource->resolve($request)], $status);
    }

    protected function perPage(Request $request): int
    {
        $requested = $request->integer('perPage', self::DEFAULT_PER_PAGE);

        return max(1, min($requested, self::MAX_PER_PAGE));
    }
}
