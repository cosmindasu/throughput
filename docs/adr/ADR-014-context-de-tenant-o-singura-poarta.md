# ADR-014: Contextul de tenant — o singură poartă, două variabile de sesiune, politică proprie pentru `memberships`

- **Status**: Accepted — **forma SQL a comparației din politicile RLS (pct. 2) e superseded parțial de [[ADR-016]]**
- **Date**: 2026-09-12
- **Deciders**: Proprietar
- **Related**: [[ADR-003]] (izolarea în două straturi), [[ADR-013]] (apelurile externe în cozi), [[ADR-002]]
- **Tags**: multi-tenancy, rls, postgresql, memberships, cozi, sprint-1

## Context și problema

[[ADR-003]] cere ca fiecare tabelă tenant-scoped să aibă o politică RLS pe `current_setting('app.tenant_id')`, iar contextul să se seteze cu `SET LOCAL` în tranzacție. Planul a implementat regula literal, uniform, pe toate tabelele. Verificarea pe un PostgreSQL 16 curat (2026-09-12, reprodusă mai jos) a arătat că implementarea literală are **trei găuri**, niciuna vizibilă la citirea codului:

1. **`SET LOCAL` nu acceptă parametri legați.** `DB::statement('SET LOCAL app.tenant_id = ?', [$id])` — forma din plan — face `PDO::prepare()` + `execute()`, iar PostgreSQL răspunde `SQLSTATE[42601]: syntax error at or near "$1"`. Mecanismul pe care stă tot ADR-003 nu pornea.
2. **`memberships` sub RLS pe `tenant_id` face al doilea workspace nedescoperibil.** Comutatorul de spațiu de lucru ([[ADR-002]], FR-TEN-01) și decizia „în ce workspace aterizez după login" cer o interogare **cross-tenant** pe `memberships`, înainte ca vreun tenant să fie cunoscut. Măsurat: un utilizator membru în două organizații vede **0** rânduri fără context și **1** cu contextul primului workspace. Singura interogare care ar găsi al doilea rulează deja scopată pe primul.
3. **Joburile și comenzile fără tenant nu au mecanism.** `ApplyTenantContextToJob` presupune `$job->tenantId`, dar seed-ul de volum, `demo:reset`, jobul zilnic `sent → overdue`, scheduler-ul de rapoarte, anonimizarea la 36 de luni și purjarea post-anulare sunt toate cross-tenant. Sub o politică cu doar `USING`, PostgreSQL o folosește **și** ca `WITH CHECK`, deci fără context până și `INSERT`-ul eșuează: `new row violates row-level security policy`.

În plus, [[ADR-013]] a scos apelurile externe din cererea HTTP tocmai pentru că middleware-ul ține o tranzacție deschisă — dar middleware-ul de job înfășura la fel tot `handle()`, deci apelul la curier și Chromium-ul de câteva secunde se întorceau într-o tranzacție, exact ce ADR-013 voia să prevină.

## Drivers de decizie

- **Mecanismul trebuie să fie verificat, nu dedus.** Trei bug-uri succesive în scriptul de bootstrap au fost livrate ca „reparate" pe baza citirii codului. Aceeași clasă de eroare a lovit și aici.
- **O singură poartă.** Contextul se setează azi în trei locuri (middleware HTTP, middleware de job, comenzi). Trei locuri = trei feluri de a greși.
- **Excepțiile trebuie să fie declarate, nu descoperite.** O tabelă care are nevoie de altă politică decât restul trebuie să spună de ce, într-un loc pe care cineva îl găsește căutând.

## Decizia luată

### 1. `set_config`, nu `SET LOCAL`

```php
DB::statement("select set_config('app.tenant_id', ?, true)", [$tenantId]);
```

Al treilea argument `true` = scopat tranzacției, echivalentul exact al lui `SET LOCAL`. Verificat: se resetează la commit, deci conexiunea reutilizată de PHP-FPM sau de un worker Horizon nu moștenește tenantul cererii anterioare. Verificat și reversul: cu `false` (echivalentul lui `SET` simplu), valoarea **supraviețuiește** commit-ului — scurgerea pe care ADR-003 o descrie, reprodusă în laborator.

### 2. Două variabile de sesiune și o politică proprie pentru `memberships`

`app.user_id` se setează la autentificare, înainte să se știe workspace-ul. `memberships` primește singura politică din aplicație care nu e uniformă:

```sql
CREATE POLICY membership_visibility ON memberships
  USING (
        user_id::text   = current_setting('app.user_id',   true)
     OR tenant_id::text = current_setting('app.tenant_id', true)
  );
```

„Rândurile mele de membership, oriunde, **sau** rândurile tenantului curent." Verificat pe toate cele șase cazuri:

| Situație | Rezultat |
|---|---|
| `u1` autenticat, fără workspace rezolvat | vede ambele workspace-uri — comutatorul funcționează |
| `u1` cu context pe `t1` | vede propriile 2 + colegii din `t1` — ecranul Members funcționează |
| `u2` cu context pe `t1` | **nu** vede membership-ul lui `u1` din `t2` — fără scurgere |
| niciun context (rută publică, job de sistem) | 0 rânduri — cade închis |
| `INSERT` de membership în tenantul curent | permis (invitație) |
| `INSERT` de membership în alt tenant | respins de RLS |

Singurul loc din aplicație unde ocolirea global scope-ului Eloquent e legitimă e `Membership::forCurrentUserAcrossTenants()`, pentru comutator. Un test asertează că `withoutGlobalScope` nu apare nicăieri altundeva.

### 3. `TenantContext` — o singură poartă

Un helper unic deschide tranzacția și setează contextul; middleware-ul HTTP, middleware-ul de job și comenzile de consolă îl apelează identic. Ordinea de middleware devine `Authenticate → SetSessionContext → ResolveWorkspace`: primul deschide tranzacția și setează `app.user_id`, al doilea adaugă `app.tenant_id` **în aceeași tranzacție**, deci nu există tranzacții imbricate. Numele `ApplyTenantContext` din notele de implementare ale [[ADR-013]] se împarte în aceste două.

### 4. Două familii de joburi

- **Joburi de tenant** — primesc `tenantId` scalar (regula din [[ADR-003]], addendum punctul 2) și folosesc middleware-ul de context.
- **Joburi de sistem** — nu au tenant. **Iterează tenanții explicit**, cu o tranzacție și un context per tenant. Verificat că e posibil: comutarea contextului în aceeași tranzacție și pe aceeași conexiune e validă.

Rolul cu `BYPASSRLS` rămâne rezervat exclusiv pentru `artisan migrate` și `demo:reset` — care fac DDL, nu manipulare de date. Motivul pentru care nu se acordă mai larg: „pun jobul pe conexiunea de migrare ca să nu mă bat cu RLS" e scurtătura pe care cineva o ia peste șase luni, și dezactivează plasa a doua tocmai în joburile care scriu în masă.

Webhook-ul Stripe e singurul loc unde tenantul vine dintr-un payload extern: ruta e publică și fără context, `tenants` nu are RLS, deci lookup-ul după `stripe_id` funcționează — dar jobul de procesare e un **job de tenant**, cu `tenantId` rezolvat de controller. Un `stripe_id` care nu se mapează pe niciun tenant produce `webhook_events.status = failed` cu motiv, nu o excepție necontrolată.

### 5. Joburile cu I/O extern își gestionează singure contextul

Middleware-ul de context se aplică **per job**, nu global. Joburile care fac I/O în afara Postgres-ului propriu (`GenerateShippingLabelJob`, generarea PDF-urilor, rapoartele) nu îl folosesc — apelează helper-ul de două ori, cu apelul extern între tranzacții:

```
[tranzacție scurtă] citește datele de intrare      → commit
apel extern / Chromium                              (nicio tranzacție deschisă)
[tranzacție scurtă] scrie rezultatul                → commit
```

Costul e o fereastră între cele două tranzacții, acceptabil pentru că joburile sunt idempotente pe id-ul resursei. Alternativele au fost respinse: o tranzacție scurtă doar pentru context nu funcționează (la commit contextul se pierde, restul jobului vede zero rânduri), iar `set_config(..., false)` scurge dovedit și lasă tenantul greșit jobului următor dacă cel curent aruncă.

### 6. `after_commit` pe coada Redis

Cu o tranzacție deschisă pe toată durata cererii, **fiecare** `dispatch()` se întâmplă în interiorul unei tranzacții — worker-ul poate ridica jobul înainte de commit și să nu găsească rândul. `'after_commit' => true` pe conexiunea de coadă, în `config/queue.php`. Fără el: eșecuri intermitente care par aleatorii, exact la eticheta de curierat și la operațiile în masă, unde interfața face polling pe un rând care încă nu există.

## Consecințe

### Pozitive

- Mecanismul de izolare pornește. Înainte de această verificare, nu pornea.
- Un singur loc de citit și de greșit pentru context, în loc de trei.
- Comutatorul de workspace funcționează fără să slăbească RLS pe tabela care decide cine are ce rol unde.
- Joburile de sistem au o regulă, nu o improvizație per job.

### Negative / trade-offs

- O a doua variabilă de sesiune de ținut minte, și o tabelă cu politică diferită de restul — ambele documentate aici tocmai ca să nu fie descoperite prin depanare.
- Ordinea de middleware devine semnificativă: `SetSessionContext` **trebuie** să ruleze înaintea lui `ResolveWorkspace`. Un test de rută verifică ordinea.
- Joburile cu I/O extern au două tranzacții, deci o fereastră de concurență. Acceptat, fiind idempotente.

## Verificare

Reprodusă pe `postgres:16-alpine` (PostgreSQL 16.14), container curat, cu PDO real (PHP 8.4, `pdo_pgsql`) pentru calea de cod Laravel — nu prin `psql`, care interpolează client-side și ar fi ascuns bug-ul de la punctul 1. Scenariile testate corespund 1:1 cu FR-TEST-01/02/03 din specificație și devin baza suitei de izolare din Faza 1.
