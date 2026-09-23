<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

/**
 * Stratul APLICATIV al jetoanelor de API (specs.md §18.1, FR-API-01) — ce vede un Owner
 * în Settings: etichetă, scopuri, cine l-a emis, când a fost folosit ultima dată, dacă e
 * revocat. Mecanismul de sub el rămâne `personal_access_tokens` (Sanctum 4), exact cum
 * spune migrația lui din Faza 1.
 *
 * ## De ce tenantul stă pe rândul SANCTUM, nu doar aici
 *
 * `api_tokens` are RLS (migrația Fazei 1), iar politica cere `app.tenant_id` DEJA setat.
 * Rezolvarea tenantului dintr-un jeton se întâmplă însă exact înainte de a exista vreun
 * context — deci un `select … from api_tokens where token_hash = ?` la intrarea în cerere
 * ar întoarce mereu zero rânduri (politica are doar `USING`, cade închis). Problema e
 * structurală, nu de implementare: ouă înaintea găinii.
 *
 * Singura tabelă fără RLS din lanț e `personal_access_tokens` (identitate globală, ca
 * `users`), deci acolo stă și legătura cu tenantul, într-o intrare de `abilities`
 * rezervată — `tenant:{ULID}`. E o valoare scrisă de SERVER la emitere, citită dintr-un
 * rând care a trecut deja verificarea `hash_equals` a lui Sanctum: nu e un claim al
 * clientului, deci nu contrazice §18.2 („tenantul se rezolvă server-side, niciodată din
 * URL"). Cu tenantul cunoscut, `ResolveTenantFromApiToken` deschide contextul și abia
 * apoi citește rândul de aici, sub RLS, prin `token_hash` — adică al doilea strat
 * verifică efectiv primul: un `tenant:` falsificat n-ar găsi niciun `api_tokens`.
 *
 * `token_hash` e IDENTIC cu `personal_access_tokens.token` (`sha256` peste partea secretă
 * a jetonului), ceea ce face legătura dintre cele două rânduri verificabilă fără o coloană
 * de FK în plus — și fără să existe nicăieri jetonul în clar (§18.1).
 */
#[Fillable(['name', 'abilities', 'last_used_at', 'expires_at', 'revoked_at'])]
class ApiToken extends Model
{
    use BelongsToTenant, HasUlids;

    /**
     * Prefixul intrării de `abilities` (pe rândul Sanctum) care poartă tenantul.
     * Nu e un scop de business și nu apare niciodată în interfață.
     */
    public const TENANT_ABILITY_PREFIX = 'tenant:';

    public const ABILITY_ACCOUNTS_READ = 'accounts:read';

    public const ABILITY_CONTACTS_READ = 'contacts:read';

    public const ABILITY_CONTACTS_WRITE = 'contacts:write';

    public const ABILITY_DEALS_READ = 'deals:read';

    public const ABILITY_DEALS_WRITE = 'deals:write';

    public const ABILITY_ORDERS_READ = 'orders:read';

    public const ABILITY_ORDERS_WRITE = 'orders:write';

    public const ABILITY_INVOICES_READ = 'invoices:read';

    public const ABILITY_INVOICES_WRITE = 'invoices:write';

    public const ABILITY_INVENTORY_READ = 'inventory:read';

    public const ABILITY_INVENTORY_WRITE = 'inventory:write';

    /**
     * Catalogul de scopuri — sursă unică pentru ecranul de administrare, pentru
     * `EnsureTokenAbility` și pentru `openapi/throughput-v1.yaml`.
     *
     * Cele OPT scopuri numite în FR-API-01 sunt toate aici. Cele TREI în plus
     * (`accounts:read`, `invoices:write`, `inventory:write`) acoperă goluri ale listei
     * din specificație, semnalate în raportul lotului, nu inventate pentru simetrie:
     * §18.4 cere explicit `POST /invoices` și `POST /stock-movements` (deci un scop de
     * scriere pentru amândouă, altfel endpoint-urile ar fi inaccesibile), iar
     * `POST /orders` cere un `account_id` valid, deci un consumator are nevoie de o
     * cale de a-l afla.
     *
     * @return array<string, string> scop → descriere afișată în interfață
     */
    public static function abilityCatalog(): array
    {
        $abilities = [
            self::ABILITY_ACCOUNTS_READ,
            self::ABILITY_CONTACTS_READ,
            self::ABILITY_CONTACTS_WRITE,
            self::ABILITY_DEALS_READ,
            self::ABILITY_DEALS_WRITE,
            self::ABILITY_ORDERS_READ,
            self::ABILITY_ORDERS_WRITE,
            self::ABILITY_INVOICES_READ,
            self::ABILITY_INVOICES_WRITE,
            self::ABILITY_INVENTORY_READ,
            self::ABILITY_INVENTORY_WRITE,
        ];

        // Descrierile trec prin catalog (`lang/{en,fr}/enums.php`), CHEILE nu: ele se
        // scriu pe `api_tokens.abilities` și se compară în `EnsureTokenAbility`, deci
        // sunt identificatori tehnici, nu text. Aceeași separare ca la `order_status`.
        return array_combine(
            $abilities,
            array_map(static fn (string $ability): string => __('enums.api_abilities.'.$ability), $abilities)
        );
    }

    /** @return list<string> */
    public static function allowedAbilities(): array
    {
        return array_keys(self::abilityCatalog());
    }

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Emite un jeton nou pentru tenantul din CONTEXTUL CURENT (deci apelabil doar din
     * interiorul unui `TenantContext`) și întoarce valoarea în clar O SINGURĂ DATĂ —
     * US-API-01: „nu mai e recuperabilă din UI ulterior".
     *
     * Rândul Sanctum se scrie cu `forceFill`, nu prin `HasApiTokens::createToken()`:
     * trait-ul ar fi cerut o modificare în `App\Models\User` (fișier comun altui lot),
     * pentru exact cele patru linii de mai jos. Formatul jetonului rămâne **identic** cu
     * cel al lui Sanctum (`{id}|{secret}`), deci `PersonalAccessToken::findToken()` îl
     * citește fără nicio adaptare.
     *
     * @param  list<string>  $abilities
     * @return array{0: self, 1: string} [rândul aplicativ, jetonul în clar]
     */
    public static function issue(User $issuer, string $name, array $abilities, ?Carbon $expiresAt = null): array
    {
        $tenantId = TenantScope::requireCurrentTenantId();

        $secret = Str::random(40);
        $hash = hash('sha256', $secret);

        $personalAccessToken = Sanctum::personalAccessTokenModel();

        /** @var PersonalAccessToken $sanctumToken */
        $sanctumToken = new $personalAccessToken;
        $sanctumToken->forceFill([
            'tokenable_type' => $issuer->getMorphClass(),
            'tokenable_id' => $issuer->getKey(),
            'name' => $name,
            'token' => $hash,
            'abilities' => [...array_values($abilities), self::TENANT_ABILITY_PREFIX.$tenantId],
            'expires_at' => $expiresAt,
        ])->save();

        $token = new self([
            'name' => $name,
            'abilities' => array_values($abilities),
            'expires_at' => $expiresAt,
        ]);
        // `user_id`/`token_hash` nu sunt în #[Fillable] — aceeași regulă ca `created_by`
        // pe Account/Order: mass-assignment ar accepta orice valoare venită din HTTP.
        $token->user_id = $issuer->getKey();
        $token->token_hash = $hash;
        $token->save();

        return [$token, $sanctumToken->getKey().'|'.$secret];
    }

    /**
     * Revocarea nu șterge rândul (FR-API-02, simetric cu BR-TEN-04 pe membri): istoricul
     * „cine a emis, cine a revocat, când" rămâne. Rândul Sanctum, în schimb, DISPARE —
     * un jeton revocat nu trebuie să mai poată fi nici măcar căutat.
     */
    public function revoke(): void
    {
        $personalAccessToken = Sanctum::personalAccessTokenModel();

        $personalAccessToken::query()
            ->where('token', $this->token_hash)
            ->delete();

        $this->forceFill(['revoked_at' => now()])->save();
    }

    /**
     * Revocarea ÎN MASĂ (§22.2, „Revocarea în masă a tuturor jetoanelor API") — toate
     * jetoanele ÎNCĂ ne-revocate ale tenantului CURENT (scopate automat de
     * `BelongsToTenant`/RLS, ca orice altă interogare pe acest model). Simetrică cu
     * `revoke()`: rândul `api_tokens` rămâne pentru fiecare jeton atins (istoric), doar
     * rândul Sanctum dispare.
     *
     * `whereNull('revoked_at')` filtrează jetoanele deja revocate ÎNAINTE de a le atinge —
     * nu doar în efect: a doua apelare, pe un tenant deja golit, nu trebuie să
     * suprascrie un `revoked_at` mai vechi cu `now()`. Idempotența e literală, nu doar
     * „fără eroare".
     *
     * DE CE CONDIȚIA SE REPETĂ PE `update()`, NU DOAR PE `SELECT` (audit 2026-09-23,
     * DOM-01 — cursă reprodusă, nu doar teoretizată): `SELECT`-ul de mai jos NU blochează
     * rândurile citite. Două apeluri concurente (dublu-click, sau două cereri simultane)
     * pot citi AMÂNDOUĂ aceeași listă de ID-uri „încă ne-revocate" înainte ca vreuna să
     * apuce să scrie. Dacă `update()`-ul repetă doar `whereIn('id', ...)`, fără
     * `whereNull('revoked_at')`, a doua tranzacție (care așteaptă la blocarea de rând pusă
     * de `update()`-ul primeia, apoi — READ COMMITTED, `EvalPlanQual` — reevaluează
     * condiția pe versiunea proaspătă a rândului) ar suprascrie `revoked_at` deja scris de
     * prima cu un `now()` mai târziu, ȘI ar raporta `$tokens->count()` din propriul SELECT
     * (stale) — „N revocate" pentru 0 rânduri modificate în realitate, contrazicând exact
     * paragraful de mai sus.
     *
     * Cu `whereNull('revoked_at')` REPETAT pe `update()`, Postgres exclude singur, la
     * reevaluare, rândurile pe care le-a golit deja cealaltă tranzacție — iar valoarea de
     * retur a lui `update()` (numărul REAL de rânduri afectate de ACEST apel) e chiar ce
     * se întoarce. `$tokens` rămâne doar lista de CANDIDAȚI, folosită exclusiv ca să se știe
     * ce rânduri Sanctum (`token_hash`) de șters — niciodată sursa numărului raportat.
     *
     * @return int numărul de jetoane revocate ACUM, de ACEST apel (0 dacă nu era nimic de
     *             revocat, sau dacă o cerere concurentă le-a revocat deja pe toate)
     */
    public static function revokeAllUsable(): int
    {
        return DB::transaction(function (): int {
            $tokens = self::query()->whereNull('revoked_at')->get(['id', 'token_hash']);

            if ($tokens->isEmpty()) {
                return 0;
            }

            $personalAccessToken = Sanctum::personalAccessTokenModel();

            $personalAccessToken::query()
                ->whereIn('token', $tokens->pluck('token_hash'))
                ->delete();

            return self::query()
                ->whereIn('id', $tokens->pluck('id'))
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);
        });
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }

    /**
     * Scopurile de business, fără intrarea `tenant:` — ea e mecanism, nu permisiune.
     *
     * @param  list<string>  $sanctumAbilities
     * @return list<string>
     */
    public static function businessAbilities(array $sanctumAbilities): array
    {
        return array_values(array_filter(
            $sanctumAbilities,
            static fn (string $ability): bool => ! str_starts_with($ability, self::TENANT_ABILITY_PREFIX),
        ));
    }

    /**
     * Tenantul purtat de un rând Sanctum, sau `null` dacă jetonul n-a fost emis de
     * această aplicație (un `personal_access_tokens` scris de altcineva).
     *
     * @param  list<string>  $sanctumAbilities
     */
    public static function tenantIdFromAbilities(array $sanctumAbilities): ?string
    {
        foreach ($sanctumAbilities as $ability) {
            if (str_starts_with($ability, self::TENANT_ABILITY_PREFIX)) {
                return substr($ability, strlen(self::TENANT_ABILITY_PREFIX));
            }
        }

        return null;
    }
}
