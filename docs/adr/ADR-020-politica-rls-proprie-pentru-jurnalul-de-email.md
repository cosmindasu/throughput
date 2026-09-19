# ADR-020: Politică RLS proprie pentru jurnalul de email, cu tenant opțional

- **Status**: Accepted
- **Data**: 2026-09-19
- **Decidenți**: proprietarul proiectului
- **Related**: [[ADR-003]], [[ADR-009]], [[ADR-014]], [[ADR-016]]

## Context și problema

`Database\Migrations\Concerns\EnablesRowLevelSecurity::enableRlsWithPolicy()` are în docblock o regulă explicită: „Un singur apelant în toată aplicația: `memberships` ([[ADR-014]], pct. 2). Orice al doilea apelant are nevoie de un ADR, nu de un commit." Tabela `sent_emails` (BR-DEMO-02, specs §22.3, Faza 4) e acel al doilea apelant. ADR-ul de față e condiția pusă de regulă.

Regula există pentru un motiv măsurat, nu din formalism: politica uniformă generată de `enableRls()` e singura formă verificată pentru izolare ([[ADR-014]]) și pentru plan de execuție ([[ADR-016]] — cast pe setare, nu pe coloană, altfel un `Seq Scan` pe 50.000 de rânduri înlocuiește un `Index Scan`). Fiecare politică scrisă de mână e o suprafață nouă unde ambele garanții pot fi pierdute tăcut, fără ca vreun test funcțional să observe.

**Problema concretă:** BR-DEMO-02 cere ca *orice* email tranzacțional să fie jurnalizat — și enumeră explicit „invitații de membri, rapoarte programate, **resetare parolă**". Primele două au tenant: pornesc dintr-un job sau o cerere deja aflată în contextul unui workspace. **Resetarea parolei nu are.** `PasswordResetLinkController` stă pe rute `guest`, în afara grupurilor `session.context` și `workspace` (`routes/web.php`) — nu există niciun tenant de atribuit în momentul trimiterii. Deci `sent_emails.tenant_id` trebuie să fie nullabil, iar politica uniformă nu acoperă rândurile fără tenant.

## Drivers de decizie

- **Cerința e explicită în specificație**, nu dedusă: resetarea parolei e numită pe litere în BR-DEMO-02.
- **Niciun INSERT care eșuează tăcut.** Politicile RLS au doar `USING`, iar PostgreSQL o aplică **și** ca `WITH CHECK` (`.ai/rules/tenancy.md`). O politică ce nu prevede cazul „fără tenant" nu ascunde doar rândul — respinge scrierea lui.
- **Zero scurgere cross-tenant**, condiție care nu se negociază ([[ADR-003]]).
- **Niciun rând scrie-o-dată-citește-niciodată.** Jurnalul conține conținut complet de email, deci date personale: retenția (§20.5) trebuie să-l poată și șterge, nu doar scrie.
- **Forma indexabilă din [[ADR-016]]** se păstrează pe ramura care contează pentru volum.

## Opțiuni considerate

1. **`tenant_id` NOT NULL, fără jurnalizare pentru emailul fără tenant.** Cea mai simplă: politica uniformă rămâne singura din proiect, regula trait-ului nu se atinge. **Respinsă** — contrazice direct litera BR-DEMO-02, care cere resetarea parolei în jurnal. Ar fi însemnat să tăiem o cerință ca să păstrăm o convenție.
2. **`tenant_id` nullabil, cu politica uniformă `enableRls()`.** **Respinsă, și e cazul cel mai instructiv:** politica uniformă e `tenant_id = current_setting('app.tenant_id', true)::bpchar`. O comparație cu `NULL` dă `NULL`, deci rândul ar fi invizibil la citire — ceea ce pare acceptabil. Dar aceeași expresie e aplicată de PostgreSQL și la INSERT: un rând cu `tenant_id = NULL` dă `NULL` la verificare, iar scrierea e respinsă cu „new row violates row-level security policy". Jurnalizarea resetării de parolă n-ar fi fost incompletă — ar fi fost o eroare în fluxul de trimitere.
3. **`tenant_id` nullabil, cu o a doua ramură explicită pentru „fără niciun context".** **Aleasă.**

## Decizie

`sent_emails` primește o politică proprie, numită `sent_email_visibility`:

```sql
(tenant_id = current_setting('app.tenant_id', true)::bpchar)
OR (tenant_id IS NULL AND coalesce(current_setting('app.tenant_id', true), '') = '')
```

Prima ramură e generată de `matchesSetting()`, deci forma indexabilă din [[ADR-016]] rămâne neschimbată acolo unde contează pentru volum (indexul e `(tenant_id, created_at)`). A doua ramură se aplică exclusiv rândurilor fără tenant.

**De ce `coalesce(…, '') = ''` și nu `IS NULL`.** `current_setting('app.tenant_id', true)` întoarce `NULL` doar pe o conexiune care n-a apelat **niciodată** `set_config` pentru acea variabilă. După ce o tranzacție anterioară a golit-o, întoarce `''`, nu `NULL` (comportament verificat în [[ADR-016]]). Workerul de coadă Horizon e un proces cu viață lungă care reutilizează aceeași conexiune între joburi ale unor tenanți diferiți — deci ajunge la `''` imediat după primul job de tenant, nu la starea virgină. Cu un `IS NULL` strict, a doua ramură ar fi fost practic nefuncțională pe orice conexiune care a văzut vreodată un tenant: exact tiparul de bug tăcut pe care `.ai/rules/tenancy.md` îl documentează la „Memoizarea per cerere". `coalesce(…, '') = ''` tratează `NULL` și `''` identic, consecvent cu restul politicilor („setare lipsă → zero rânduri; setare goală → zero rânduri. Cade tot închis.").

**De ce a doua ramură nu e opțională.** Fără ea, rândurile fără tenant ar fi fost scrie-o-dată-citește-niciodată: nicio interogare, din niciun context, n-ar fi putut să le citească sau să le șteargă la retenție (`PruneSentEmailsJob`). Ar fi rămas definitiv în tabelă, cu conținut complet de email — opusul cerinței de minimizare din §20.5.

## Verificare

Comportamentul e **măsurat cu două sesiuni `psql` reale**, ca `throughput_app` (rolul fără `BYPASSRLS`), nu dedus din citirea politicii:

| Context | Rezultat observat | Așteptat |
|---|---|---|
| `app.tenant_id` = un ULID | vede doar rândurile tenantului; rândul fără tenant e invizibil | da |
| `app.tenant_id` = `''` (workerul Horizon între joburi) | vede doar rândurile fără tenant | da |
| INSERT cu `tenant_id = NULL` sub context de tenant | `ERROR: new row violates row-level security policy` | da |

Al treilea rând e cel important: un tenant activ nu poate nici măcar *scrie* un rând fără tenant, deci a doua ramură nu poate fi folosită ca portiță de ascundere a datelor față de propriul tenant.

## Consecințe

**Pozitive**
- BR-DEMO-02 e acoperită integral, inclusiv fluxul de resetare a parolei.
- Izolarea cross-tenant rămâne totală, verificată empiric (tabelul de mai sus) și acoperită de
  teste: `SentEmailsTest::test_a_tenant_never_sees_another_tenants_sent_emails` și
  `::test_a_tenant_less_row_never_appears_in_any_workspace`, plus garda structurală generică
  `IsolationTest::test_every_table_with_a_tenant_id_column_has_row_level_security_enabled`,
  care prinde automat orice tabelă cu `tenant_id`, deci și pe aceasta.
- Retenția poate șterge și rândurile fără tenant.
- Forma indexabilă din [[ADR-016]] e păstrată pe ramura cu volum.

**Negative, asumate**
- A doua politică scrisă de mână din proiect. Regula „al doilea apelant are nevoie de un ADR" rămâne în vigoare; **un al treilea apelant are nevoie de un ADR nou**, nu de o trimitere la acesta.
- Rândurile fără tenant nu sunt vizibile în niciun ecran de workspace, prin construcție. Sunt accesibile doar codului care rulează fără context (jobul de retenție). Dacă un ecran de operare pentru ele devine necesar, cere o decizie separată — nu se rezolvă slăbind politica.
- **Gol cunoscut, documentat, nu închis de acest ADR:** un email trimis dintr-un job care și-a închis deja contextul de tenant înainte de apelul extern (cum cere [[ADR-013]]) ajunge în jurnal cu `tenant_id = NULL`, deci invizibil în Settings-ul tenantului care l-a declanșat. Atribuirea corectă cere ca jobul să transmită explicit tenantul odată cu mesajul; mecanismul există (antetul `X-Throughput-Tenant-Id`, citit și eliminat de `DemoInterceptingTransport`), dar fiecare emitent trebuie să-l seteze.
