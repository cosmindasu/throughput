<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use App\Models\Scopes\TenantScope;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * FR-API-03 / §18.4 — `Idempotency-Key` OBLIGATORIU pe `POST /orders`, `POST /invoices`
 * și `POST /stock-movements`, cu răspunsul cache-uit 24h (US-API-02: „primesc de fiecare
 * dată același răspuns … nu două comenzi").
 *
 * ## De ce nu e nevoie de niciun blocaj explicit
 *
 * Întreaga cerere API rulează într-o SINGURĂ tranzacție, deschisă de
 * `ResolveTenantFromApiToken` (ADR-014). Revendicarea cheii (`INSERT … ON CONFLICT DO
 * NOTHING`, tiparul din `.ai/rules/tenancy.md`) și efectul propriu-zis al cererii sunt
 * deci în aceeași tranzacție, ceea ce dă gratuit exact garanțiile cerute:
 *
 *  - **Concurență.** O a doua cerere cu aceeași cheie, pe altă conexiune, nu vede rândul
 *    încă necomis; `insertOrIgnore` o BLOCHEAZĂ pe indexul unic `(tenant_id, key)` până
 *    la commit-ul primeia, apoi întoarce „0 inserate", iar ea recitește rândul deja
 *    complet și redă răspunsul. Fără `SELECT … FOR UPDATE` și fără vreo blocare aplicativă.
 *  - **Eșec.** Orice excepție (inclusiv `ValidationException`) derulează tranzacția, deci
 *    revendicarea dispare odată cu efectul: o cerere respinsă nu „otrăvește" cheia, iar
 *    clientul poate reîncerca corect cu aceeași cheie. Inversul — cheie scrisă, efect
 *    derulat — ar fi fost o comandă imposibil de creat vreodată.
 *
 * Corolar: acest middleware TREBUIE să stea după `ResolveTenantFromApiToken`. Fără
 * tranzacția lui, `INSERT`-ul ar fi propria lui tranzacție, iar cele două garanții de mai
 * sus dispar tăcut.
 *
 * ## Ce înseamnă exact „același răspuns"
 *
 * Corpul se păstrează în coloana `jsonb` din migrația Fazei 1, iar PostgreSQL NU conservă
 * ordinea cheilor unui obiect JSON — o normalizează, prin design. Replay-ul întoarce deci
 * același OBIECT (aceleași chei, aceleași valori), nu aceeași secvență de octeți. Pentru
 * orice client HTTP cele două sunt identice: ordinea cheilor unui obiect JSON n-are
 * semantică. Scris aici fiindcă diferența se vede într-un `assertSame` pe array și pare,
 * la prima citire, un bug.
 */
class RequireIdempotencyKey
{
    public const HEADER = 'Idempotency-Key';

    private const MAX_KEY_LENGTH = 255;

    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header(self::HEADER, ''));

        if ($key === '') {
            return $this->problem(
                'This endpoint requires an `Idempotency-Key` header so a retried request cannot create the same record twice.',
                Response::HTTP_BAD_REQUEST,
            );
        }

        if (mb_strlen($key) > self::MAX_KEY_LENGTH) {
            return $this->problem(
                'The `Idempotency-Key` header must be at most '.self::MAX_KEY_LENGTH.' characters.',
                Response::HTTP_BAD_REQUEST,
            );
        }

        $hash = IdempotencyKey::hashFor($request->all());

        $existing = IdempotencyKey::query()->where('key', $key)->first();

        // Un rând expirat (peste cele 24h din §18.4) e ca și inexistent — se rescrie pe
        // loc, fiindcă indexul unic `(tenant_id, key)` nu permite un al doilea rând și o
        // ștergere+inserare ar fi două scrieri pentru același efect.
        if ($existing !== null && $existing->isExpired()) {
            $existing->forceFill([
                'request_hash' => $hash,
                'response_body' => null,
                'response_status' => null,
                'expires_at' => $this->expiry(),
            ])->save();

            return $this->execute($request, $next, $key);
        }

        if ($existing === null) {
            $claimed = DB::table('idempotency_keys')->insertOrIgnore([
                'id' => (string) Str::ulid(),
                'tenant_id' => TenantScope::requireCurrentTenantId(),
                'key' => $key,
                'request_hash' => $hash,
                'response_body' => null,
                'response_status' => null,
                'expires_at' => $this->expiry(),
            ]);

            if ($claimed === 1) {
                return $this->execute($request, $next, $key);
            }

            // Altcineva a revendicat cheia între `SELECT` și `INSERT` — sau a comis-o cât
            // am așteptat pe indexul unic. Rândul lui e acum vizibil.
            $existing = IdempotencyKey::query()->where('key', $key)->first();

            if ($existing === null) {
                return $this->execute($request, $next, $key);
            }
        }

        if (! hash_equals($existing->request_hash, $hash)) {
            // Mesajul e cel cerut literal de §18.4.
            return $this->problem(
                'Idempotency-Key reused with a different payload',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if ($existing->isInFlight()) {
            return $this->problem(
                'A request with this `Idempotency-Key` is still in progress. Retry in a moment.',
                Response::HTTP_CONFLICT,
            );
        }

        return response()
            ->json($existing->response_body ?? [], $existing->response_status ?? Response::HTTP_OK)
            ->header('Idempotent-Replay', 'true');
    }

    /**
     * Rulează cererea și persistă răspunsul pe rândul deja revendicat.
     *
     * Doar răspunsurile de succes se memorează. Un 4xx întors (nu ARUNCAT — acela
     * derulează tranzacția, vezi docblock-ul clasei) își șterge revendicarea: altfel
     * clientul ar primi „reused with a different payload" pe orice încercare de corectare
     * cu aceeași cheie, ceea ce ar fi un mesaj fals.
     */
    private function execute(Request $request, Closure $next, string $key): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if ($response->getStatusCode() >= 400) {
            IdempotencyKey::query()->where('key', $key)->delete();

            return $response;
        }

        $body = json_decode((string) $response->getContent(), true);

        IdempotencyKey::query()->where('key', $key)->update([
            'response_body' => json_encode(is_array($body) ? $body : []),
            'response_status' => $response->getStatusCode(),
        ]);

        return $response;
    }

    private function expiry(): string
    {
        return now()->addHours(IdempotencyKey::TTL_HOURS)->toDateTimeString();
    }

    private function problem(string $message, int $status): Response
    {
        return response()->json(['message' => $message], $status);
    }
}
