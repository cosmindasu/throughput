# Tenancy, RLS și cozi

Stratul de care depinde tot restul. Fiecare regulă de aici corespunde unui bug real, găsit pe
container, în cod care fusese deja declarat funcțional — vezi
[ADR-014](../../docs/adr/ADR-014-context-de-tenant-o-singura-poarta.md).

## Contextul se setează prin `TenantContext`, nu direct

`App\Services\Tenancy\TenantContext` e **singura poartă**. Nu scrie `set_config` sau
`SET LOCAL` altundeva.

`SET LOCAL app.tenant_id = ?` **nu există**: `SET` nu acceptă parametri legați, iar
`DB::statement()` face `prepare()`+`execute()`, deci întoarce `SQLSTATE[42601]`. Forma corectă
e `select set_config('app.tenant_id', ?, true)`. Al treilea argument `true` înseamnă „doar în
tranzacția curentă" — cu `false`, contextul supraviețuiește commit-ului și scurge date între
cereri pe aceeași conexiune.

## Două variabile de sesiune, nu una

`app.tenant_id` **și** `app.user_id`. A doua există pentru că `memberships` are politică
proprie: sub o politică uniformă, un utilizator membru în două organizații vedea 0 workspace-uri
fără context și 1 cu contextul primului — al doilea era nedescoperibil, deci comutatorul de
workspace era imposibil de implementat. Măsurat, nu presupus.

## Ordinea middleware-ului e semnificativă

`SetSessionContext` → `ResolveWorkspace`. Inversarea **nu dă eroare**: dă un comutator de
workspace gol. Există un test dedicat (`MiddlewareOrderTest`) exact pentru că simptomul e mut.

## Politicile RLS se aplică și la INSERT

Politicile au doar `USING`, iar PostgreSQL o aplică **și** ca `WITH CHECK`. Deci un `INSERT`
fără context eșuează cu `new row violates row-level security policy`. Seederele și joburile de
sistem iterează tenanții explicit cu `TenantContext::run()`, pe conexiunea aplicației — **nu**
pe cea cu `BYPASSRLS`, care rămâne exclusiv pentru `artisan migrate`.

## Politicile RLS: cast pe setare, nu pe coloană

Orice politică se construiește cu `EnablesRowLevelSecurity::matchesSetting()`, care produce
`tenant_id = current_setting('app.tenant_id', true)::bpchar`. **Nu** scrie
`tenant_id::text = current_setting(...)`, și nici `tenant_id = current_setting(...)` fără cast:
în ambele cazuri cast-ul ajunge pe coloană, iar indexul compus cu `tenant_id` pe prima poziție
iese din joc. Izolarea rămâne corectă, deci niciun test funcțional nu vede diferența. Se vede
doar planul de execuție: `Seq Scan` pe 50.000 de comenzi, măsurat. Global scope-ul Eloquent
ascunde problema, fiindcă adaugă `tenant_id = ?` fără cast, așa că plătesc doar SQL-ul brut,
agregatele și joburile. `IsolationTest::test_no_policy_casts_the_indexed_column` citește
`pg_policies`. Vezi [ADR-016](../../docs/adr/ADR-016-cast-rls-pe-setare-nu-pe-coloana.md).

## Două familii de joburi

- **De tenant**: primesc `tenantId` serializat explicit și îl restaurează în `handle()`.
- **De sistem**: nu au tenant (seed, `demo:reset`, jobul `sent → overdue`, scheduler-ul de
  rapoarte, anonimizarea, purjarea). Iterează tenanții, cu o tranzacție și un context per tenant.

Joburile cu I/O extern își gestionează contextul prin **două tranzacții scurte**, cu apelul
extern între ele — altfel middleware-ul de job reintroduce exact problema rezolvată de ADR-013.

## Testele rulează cu coadă `database`, nu `sync`

Cu `sync` (sau `Queue::fake()`), jobul rulează în procesul care l-a dispecerizat, cu contextul
cererii încă viu — adică exact condiția în care bug-ul de serializare e **invizibil**. Un job
care primește un model tenant-scoped ar trece verde și ar cădea în producție.

## `actingAs` golește sesiunea

`AuthenticateSession` e activ global (FR-PUB-05 cere invalidarea sesiunilor la reset de parolă).
Al doilea `actingAs()` din același test moștenea `password_hash_web` al primului utilizator și
era delogat instant — `302 → /login`, nu `200`. Golirea e în `Tests\TestCase::actingAs()`.
