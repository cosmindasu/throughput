# ADR-003: Izolarea tenanților în două straturi — global scope Eloquent + Row-Level Security

- **Status**: Accepted
- **Date**: 2026-09-12
- **Deciders**: Tech Lead
- **Related**: [[ADR-001]] (alegerea PostgreSQL), [[ADR-002]] (identificarea tenantului)
- **Tags**: multi-tenancy, securitate, postgresql, rls, eloquent, sprint-1

## Context și problema

Într-o aplicație multi-tenant, scurgerea de date între organizații e singura clasă de defect care nu are grade: e fie absentă, fie fatală. Un client care vede comenzile altui client încheie relația și, în funcție de jurisdicție, raportează incidentul.

Mecanismul uzual în Laravel e un **global scope** pe fiecare model, care adaugă automat `WHERE tenant_id = ?`. Funcționează, dar are puncte oarbe cunoscute: interogările cu `DB::table()` care ocolesc Eloquent, joburile din coadă care rulează fără contextul cererii, comenzile din consolă, seederele, și orice loc unde cineva scrie `withoutGlobalScopes()` fără să înțeleagă ce face.

## Drivers de decizie

- **Consecința unei scăpări e disproporționată** față de costul unei a doua plase.
- **Valoare demonstrativă** — pentru recenzentul tehnic al unui client, izolarea impusă în bază e un semnal puternic de maturitate. E, din perspectiva portofoliului, cel mai bun raport impresie/efort din tot proiectul.
- **Codul se schimbă, baza nu** — un dezvoltator nou care adaugă un query brut nu trebuie să poată produce o scurgere.

## Opțiuni considerate

### Opțiunea 1: Doar global scope Eloquent

- **Pro**: simplu, idiomatic Laravel, zero configurare de bază de date.
- **Contra**: o singură plasă, cu găuri cunoscute (query builder brut, joburi, consolă). Nimic nu oprește o scăpare odată ce cineva ocolește Eloquent.

### Opțiunea 2: Doar Row-Level Security în PostgreSQL

- **Pro**: imposibil de ocolit din cod; garanție la nivelul bazei.
- **Contra**: mesaje de eroare opace la dezvoltare (rândurile pur și simplu „nu există"); cere disciplină la setarea contextului pe fiecare conexiune; testele devin mai greu de citit.

### Opțiunea 3: Ambele straturi (ALEASĂ)

- **Pro**: scope-ul dă erori inteligibile și cod idiomatic în 99% din cazuri; RLS prinde restul, inclusiv ce nu s-a scris încă. Fiecare strat acoperă slăbiciunea celuilalt.
- **Contra**: două mecanisme de înțeles și de ținut sincronizate; context de setat corect pe conexiune.

## Decizia luată

**Aleasă: Opțiunea 3.**

Fiecare tabel cu date de tenant poartă `tenant_id`. Modelele Eloquent au un global scope; în plus, PostgreSQL are `ENABLE ROW LEVEL SECURITY` cu politici pe `current_setting('app.tenant_id')`.

Două detalii de implementare care decid dacă merge sau nu:

1. **Contextul se setează cu `SET LOCAL`, în tranzacție, nu cu `SET` pe conexiune.** Conexiunile sunt refolosite între cereri și între joburi; un `SET` persistent ar lăsa contextul unui tenant activ pentru cererea următoare — exact scurgerea pe care o prevenim.
2. **Rolul aplicației nu are `BYPASSRLS`.** Migrațiile și comenzile de întreținere rulează cu un rol separat, care îl are. Altfel politicile sunt decorative.

Testele acoperă explicit ambele straturi: un test care verifică scope-ul și un test care ocolește Eloquent (`DB::table()`) și confirmă că RLS oprește accesul.

## Addendum 2026-09-12 — detalii confirmate de research

Adăugat în aceeași zi cu decizia, după raportul din `docs/research/best-practices-throughput.md`. **Decizia nu se schimbă**; se completează notele de implementare, pentru că research-ul a scos patru puncte pe care nu le aveam.

1. **Index compus obligatoriu.** `tenant_id` trebuie să fie **coloana de lider** în orice index folosit de interogări filtrate — `(tenant_id, created_at)`, nu `(created_at)`. Altfel RLS poate fi cu ordine de mărime mai lent. Se aplică tuturor tabelelor tenant-scoped cu volum mare.

2. **Joburile nu primesc modele Eloquent tenant-scoped în payload.** La deserializare, `SerializesModels::restoreModel()` re-aduce modelul din bază **înainte** ca vreun bootstrapper de tenancy să restaureze contextul — deci interogarea rulează fără scope corect sau eșuează tăcut. Regula: serializezi `tenant_id` explicit, iar primul rând din `handle()` re-leagă tenantul în container, înaintea oricărei interogări.

3. **Canalele de broadcast se prefixează cu tenantul** (`tenant.{id}.orders`), altfel autorizarea poate scăpa între tenanți.

4. **Testele cu `Queue::fake()` sau driver `sync` nu pot vedea scurgerea din joburi.** Suita are nevoie de cel puțin un test cu driver real de coadă, care rulează un job adevărat. Plus un test de izolare per model tenant-scoped: creezi doi tenanți, aserți că al doilea nu vede datele primului.

**Notă de onestitate din research:** nu există consens ferm în sursele găsite că RLS e obligatoriu la scara unui proiect single-database de complexitate medie — o parte din surse îl tratează drept „nice to have enterprise-grade". Decizia noastră îl păstrează, dar motivul principal rămâne cel din secțiunea de drivers: raportul impresie/efort pentru un demo de portofoliu, plus faptul că e a doua plasă acolo unde o scăpare e fatală. Nu pretindem că e consensul industriei.

**Relevanță de securitate:** scenariul „citesc comanda altui tenant incrementând un ID" e **OWASP API1:2023 — Broken Object Level Authorization**, riscul API numărul 1. RLS îl acoperă la nivel de bază; verificarea de ownership pe fiecare endpoint care primește un ID rămâne obligatorie și la nivel de aplicație.

## Consecințe

### Pozitive

- O scăpare cere ambele straturi să greșească simultan.
- Argument tehnic vizibil într-o demonstrație, cu cost mic de implementare.
- Joburile din coadă și comenzile de consolă sunt la fel de protejate ca cererile HTTP.

### Negative / trade-offs

- La dezvoltare, un context nesetat face rândurile să pară inexistente. Se atenuează cu un guard care aruncă explicit când `app.tenant_id` lipsește într-un context care ar trebui să-l aibă.
- Migrațiile cer un rol separat, deci configurare de conexiune în plus.
- Pool-ul de conexiuni trebuie înțeles înainte, nu după. Documentat în `plan-implementare.md`, Sprint 1.
