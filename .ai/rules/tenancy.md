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

`SetSessionContext` → `ResolveWorkspace` → `SubstituteBindings`. Ordinea e impusă de **lista de
prioritate** a framework-ului (`prependToPriorityList` în `bootstrap/app.php`), nu de felul în
care e declarată ruta — o declarație inversată e reordonată. Fără cele două intrări,
`SetSessionContext` după `ResolveWorkspace` dă un comutator de workspace gol (mut), iar
`SubstituteBindings` (din grupul `web`) rulează înaintea contextului: orice parametru tipizat
(`show(Account $account)`) dă 500 cu `TenantContextMissingException`. `MiddlewareOrderTest`
acoperă ambele.

`ResolveWorkspace` scoate `{workspace}` din parametrii rutei (`forgetParameter`): dispatcher-ul
Laravel pasează parametrii **pozițional**, deci altfel `show(Account $account)` ar primi
slug-ul. Nu pune `string $workspace` în semnăturile de controller; tenantul curent e
`app('tenant')`, iar `route()` propagă segmentul singur, prin `URL::defaults`.

Rutele de modul stau în `routes/web/{modul}.php`, incluse din grupul cu workspace din
`routes/web.php` — nu înregistra o rută de workspace în afara acelui grup.

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

## Un index funcțional pe o funcție ne-`LEAKPROOF` e mort sub RLS

`CREATE INDEX ON accounts (tenant_id, lower(name))` pare soluția evidentă pentru o căutare
case-insensitive. **Sub RLS nu se folosește niciodată.** O tabelă cu RLS activ e o barieră de
securitate: PostgreSQL evaluează întâi politica, și refuză să coboare sub ea orice predicat al
utilizatorului care conține o funcție ne-`LEAKPROOF` — altfel funcția ar putea scurge, printr-un
mesaj de eroare, valori din rânduri pe care apelantul n-are voie să le vadă. `lower()` **nu** e
leakproof (`select proleakproof from pg_proc where proname = 'lower'` → `f`), deci predicatul
ajunge filtru după citirea rândurilor, iar indexul pe expresie rămâne nefolosit.

Eșecul e **tăcut**: indexul există, `\d tabela` îl arată, nimic nu dă eroare. Se vede doar în
plan — `Rows Removed by Filter: 3999` pe un tenant cu 4.000 de conturi. Nici măcar
`enable_seqscan = off` nu-l scoate din joc; măsurat.

Forma care merge e o **coloană generată și stocată**, plus un index obișnuit pe ea:

```sql
ALTER TABLE accounts ADD COLUMN name_lower text GENERATED ALWAYS AS (lower(name)) STORED;
CREATE INDEX ON accounts (tenant_id, name_lower);
```

Comparația devine `name_lower = ?`, adică `texteq`, care **e** leakproof. Măsurat pe același
set: 0,951 ms → 0,061 ms, ca `throughput_app`, sub RLS.

Regula se aplică oricărei funcții, nu doar lui `lower()`: verifică `proleakproof` înainte să te
bazezi pe un index funcțional. Găsit în Faza 4, pe cheile de duplicat ale importului CSV — un
code review recomandase indexul funcțional, iar măsurătoarea l-a infirmat.

## Blocarea unui rând părinte: `FOR NO KEY UPDATE`, nu `FOR UPDATE`

O invariantă care nu trăiește pe un singur rând se serializează blocând rândul părinte: numărul
de comandă per tenant, contactul principal al unui cont, ordinea etapelor dintr-un pipeline.
`lockForUpdate()` emite `FOR UPDATE`, care intră în conflict cu `FOR KEY SHARE`, blocarea pe
care PostgreSQL o ia la verificarea FK a oricărui INSERT sau UPDATE într-o tabelă copil. Cum
tranzacția ține toată cererea, un `FOR UPDATE` pe `tenants` oprește scrierile întregului tenant
până la finalul cererii. Măsurat cu două sesiuni: `lock timeout` exact pe
`SELECT 1 FROM ONLY tenants … FOR KEY SHARE`.

Forma corectă e `->lock('for no key update')`. Serializează la fel două tranzacții care
blochează același părinte, dar lasă să treacă inserările în tabelele copil. Pe rândul pe care
chiar îl modifici (comanda la tranziție, `inventory_levels`), `lockForUpdate()` e acceptabil:
oprește doar copiii acelui rând.

## Rânduri create la cerere: `ON CONFLICT DO NOTHING`, sortat

Un `create()` într-un `catch` pentru unique violation nu salvează nimic: în PostgreSQL orice
eroare abortează tranzacția, deci cererea dă 500 la interogarea următoare. Rândurile care pot
fi create concurent se inserează cu `insertOrIgnore()`, într-o singură instrucțiune, **în
aceeași ordine în care se blochează apoi**. Inserate în ordinea apelantului, două tranzacții în
sensuri opuse țin fiecare câte un rând nou și îl așteaptă pe al celeilalte (deadlock reprodus pe
`inventory_levels`). Vezi `App\Actions\Stock\Concerns\LocksInventoryLevels`.

## Două familii de joburi

- **De tenant**: primesc `tenantId` serializat explicit și îl restaurează în `handle()`.
- **De sistem**: nu au tenant (seed, `demo:reset`, jobul `sent → overdue`, scheduler-ul de
  rapoarte, anonimizarea, purjarea). Iterează tenanții, cu o tranzacție și un context per tenant.

Joburile cu I/O extern își gestionează contextul prin **două tranzacții scurte**, cu apelul
extern între ele — altfel middleware-ul de job reintroduce exact problema rezolvată de ADR-013.

## Memoizarea per cerere: `scoped()` fără `bound()` pe o închidere care capturează valori

Worker-ul de coadă e un proces cu viață lungă, care rulează joburi pentru tenanți diferiți.
Laravel apelează `forgetScopedInstances()` doar acolo, înainte de fiecare job
(`QueueServiceProvider`), și șterge **instanța rezolvată**, nu binding-ul. Un tipar ca
`if (app()->bound($key)) return app($key); … app()->scoped($key, fn () => $ids);` păstrează
deci `$ids` de la primul job pe toată viața worker-ului, iar un job pentru alt tenant citește
setul primului. Găsit pe eticheta „(deactivated)" (FR-TEN-04): un user dezactivat într-un
tenant apărea dezactivat și în altul.

Forma corectă e una din două: re-legare **necondiționată** la fiecare cerere sau job (cum face
`ResolveWorkspace` cu `tenant`), sau o clasă înregistrată o singură dată ca `scoped` într-un
provider, cu cache-ul în instanță, **cheiat pe tenant**, și golit explicit când datele se schimbă
în aceeași cerere. În testele HTTP, mai multe cereri din același test rulează în același proces și
instanța supraviețuiește între ele; un test care numără interogări apelează
`$this->app->forgetScopedInstances()` înaintea fiecărei cereri măsurate.

## Testele rulează cu coadă `database`, nu `sync`

Cu `sync` (sau `Queue::fake()`), jobul rulează în procesul care l-a dispecerizat, cu contextul
cererii încă viu — adică exact condiția în care bug-ul de serializare e **invizibil**. Un job
care primește un model tenant-scoped ar trece verde și ar cădea în producție.

## `actingAs` golește sesiunea

`AuthenticateSession` e activ global (FR-PUB-05 cere invalidarea sesiunilor la reset de parolă).
Al doilea `actingAs()` din același test moștenea `password_hash_web` al primului utilizator și
era delogat instant — `302 → /login`, nu `200`. Golirea e în `Tests\TestCase::actingAs()`.
