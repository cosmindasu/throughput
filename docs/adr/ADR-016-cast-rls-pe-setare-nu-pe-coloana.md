# ADR-016: Politicile RLS pun cast-ul pe setare, nu pe coloană

- **Status**: Accepted
- **Data**: 2026-09-12
- **Decidenți**: proprietarul proiectului
- **Supersedează parțial**: [[ADR-014]] — exclusiv forma SQL a comparației din politici (blocul din pct. 2) și forma uniformă `{$column}::text = current_setting(...)` din plan §7.2. Restul ADR-014 rămâne în vigoare, neatins: `set_config` în loc de `SET LOCAL`, cele două variabile de sesiune, politica proprie pentru `memberships` ca decizie, poarta unică `TenantContext`, cele două familii de joburi și `after_commit`.
- **Related**: [[ADR-003]], [[ADR-014]]

## Context și problema

Planul (§7.2) și [[ADR-014]] pct. 2 prescriu comparația RLS cu cast pe coloană: `tenant_id::text = current_setting('app.tenant_id', true)`, iar în politica `memberships`, la fel, `user_id::text = current_setting('app.user_id', true)`. Coloanele `tenant_id` și `user_id` sunt `character(26)` (ULID). Tabela `orders` are indexul compus `orders_tenant_id_status_created_at_index` pe `(tenant_id, status, created_at)`, exact regula din addendumul [[ADR-003]] („`tenant_id` coloană de lider").

Cast-ul pus pe coloană **învelește coloana**: `(tenant_id)::text` nu mai e expresia pe care indexul btree o recunoaște, deci planificatorul nu poate folosi indexul pentru condiția din politică. Izolarea rămâne corectă — niciun rând din alt tenant nu scapă — așa că **niciun test funcțional n-a văzut diferența**. Global scope-ul Eloquent adaugă `tenant_id = ?` fără niciun cast, deci cererile obișnuite prin modele rămâneau rapide; costul cădea exact pe căile pentru care RLS există ca a doua plasă ([[ADR-003]]): SQL brut, agregate, joburi.

Descoperit de `db:explain-critical`, la finalul Fazei 1 — adică de la citirea planului de execuție, nu de la citirea codului. Capcana nu stătea în definiția indexului (aceea era corectă, conform addendumului [[ADR-003]]), ci în forma expresiei din politică.

## Drivers de decizie

- **Verificat cu `EXPLAIN`, nu dedus din citirea codului** — aceeași lecție ca în [[ADR-014]]: un mecanism care „arată corect" în cod poate fi greșit la execuție.
- **Fixul nu slăbește izolarea** deja măsurată și testată în [[ADR-014]] — schimbă doar forma comparației, nu ce rânduri sunt vizibile.
- **O singură formă generată**, nu comparații scrise de mână per migrație/politică — sursa erorii inițiale a fost tocmai o regulă uniformă aplicată manual, de aceea corecția trebuie automatizată, nu repetată.
- **Cost de schemă zero** — fără indexuri suplimentare pe expresie, care ar dubla costul de scriere și memoria ocupată.

## Opțiuni considerate

1. **Cast pe coloană + indexuri pe expresie** (`(tenant_id::text, …)`): dublează fiecare index compus existent, cu cost la scriere și memorie pe un VPS cu buget de 250–400 MB. Respinsă.
2. **Fără cast explicit** (`tenant_id = current_setting(...)`): măsurat — PostgreSQL rezolvă comparația `bpchar = text` punând el însuși cast-ul pe coloană, deci comportamentul e identic cu varianta cu cast pe coloană. Respinsă.
3. **Cast pe setare, `::bpchar`, generat dintr-un singur helper** (`EnablesRowLevelSecurity::matchesSetting()`), nicio comparație scrisă de mână. **Aleasă.**
4. **Schimbarea tipului cheilor** (de ex. `uuid`): schemă modificată pe toate tabelele, iar cast-ul nu dispare — doar se mută (`::uuid`). Respinsă.

## Măsurătoarea

Mediu: containerul `postgres:16-alpine`, **PostgreSQL 16.14**, baza de dev după `demo:reset` (3 tenanți, 50.000 de comenzi în total, tenantul Marlin are 30.000), rol `throughput_app` (fără `BYPASSRLS`), context setat pe Marlin. Interogarea e cea din `db:explain-critical` pentru FR-ORD-02:

```sql
select * from orders where status = 'confirmed' order by created_at desc limit 50;
```

Fiecare formă a fost aplicată cu `ALTER POLICY` într-o tranzacție anulată cu `ROLLBACK`, deci politica reală n-a fost modificată.

| Formă | Plan | Rânduri aruncate de filtru | Execuție |
|---|---|---|---|
| A. `tenant_id::text = current_setting(...)` (plan §7.2 / ADR-014) | `Seq Scan on orders` + sort top-N | 45.500 | 19,2 ms (prima rulare) |
| B. `tenant_id = current_setting(...)`, fără cast explicit | identic cu A: PostgreSQL rezolvă `bpchar = text` punând cast pe coloană, iar expresia apare deparsată tot ca `(tenant_id)::text = current_setting(...)` | 45.500 | 7,8 ms |
| C. `tenant_id = current_setting(...)::bpchar` (cod) | `Index Scan Backward using orders_tenant_id_status_created_at_index`, Index Cond pe `tenant_id` și `status` | 0 | 0,107 ms |

**Notă de onestitate:** la 50.000 de rânduri milisecundele absolute sunt mici, iar timpii de la A și B variază între rulări (cache). Dovada nu e cronometrul, e **forma planului**: `Seq Scan` crește cu toată tabela, adică cu toți tenanții, pe când `Index Scan` cu `LIMIT` nu crește.

## Cade închis — verificat cu forma C, ca `throughput_app`

- context lipsă (`current_setting` = `NULL`) → 0 rânduri
- setare `''` → 0 rânduri
- tenantul corect → 30.000 rânduri
- **după `COMMIT`, pe aceeași sesiune, `current_setting('app.tenant_id', true)` întoarce `''`, nu `NULL`** → 0 rânduri. [[ADR-014]] și planul spun „`NULL` când nu e setat"; pe o conexiune reutilizată (PHP-FPM, worker), cazul real e `''`. Rezultatul e același (0 rânduri), dar merită consemnat ca fapt verificat.

## Decizia

Orice politică RLS compară coloana **necastată** cu setarea castată:

```sql
coloana = current_setting('app.x', true)::bpchar
```

Generată exclusiv prin `EnablesRowLevelSecurity::matchesSetting()` — nicio comparație scrisă de mână. `bpchar`, nu `character(26)`: indexul btree folosește operatorul `bpchar = bpchar`, iar lungimea declarată nu intră în alegerea operatorului.

Politica `memberships` din [[ADR-014]] pct. 2 devine:

```sql
CREATE POLICY membership_visibility ON memberships
  USING (
        user_id   = current_setting('app.user_id',   true)::bpchar
     OR tenant_id = current_setting('app.tenant_id', true)::bpchar
  );
```

## Consecințe

### Pozitive

- Calea RLS folosește indexurile compuse: `db:explain-critical` e verde pe toate cele 10 interogări critice, iar lista de comenzi răspunde în 0,8 ms.
- Regresia e prinsă de `IsolationTest::test_no_policy_casts_the_indexed_column`, care citește `pg_policies` și cade dacă vreun `qual` conține `)::text = current_setting`. Prinde și forma B, fiindcă PostgreSQL o stochează deparsată identic cu A.
- Suita Pest: 63/63, inclusiv cele șase cazuri de vizibilitate `memberships` din [[ADR-014]] pct. 2, care rămân valabile sub forma nouă.

### Negative / trade-offs

- Helper-ul presupune chei `character(n)` (convenția ULID a proiectului). O coloană de alt tip cere reverificare cu `EXPLAIN`, nu copiere.
- Migrația `memberships` și trait-ul au fost editate pe loc, fără o migrație corectivă. E valid pentru că `demo:reset` rulează `migrate:fresh` zilnic (FR-DEMO-03), deci orice mediu primește forma nouă la primul reset. O producție cu date persistente ar fi cerut o migrație cu `ALTER POLICY`.

## Verificare

Reprodusă pe `postgres:16-alpine` (PostgreSQL 16.14), bază de dev cu seed complet după `demo:reset` (3 tenanți, 50.000 de comenzi), ca rol `throughput_app` (fără `BYPASSRLS`). Metodă: fiecare formă a comparației aplicată cu `ALTER POLICY` într-o tranzacție anulată cu `ROLLBACK`, ca politica reală să nu fie atinsă în timpul măsurătorii. Rezultatele sunt cele din tabelul de mai sus, plus cazurile „cade închis" verificate separat pe forma finală.

Forma C e cea din cod, comisă în `8da5f90` (`fix(rls): cast pe setare, nu pe coloană`).
