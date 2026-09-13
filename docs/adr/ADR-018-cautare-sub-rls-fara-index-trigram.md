# ADR-018: Căutarea globală sub RLS filtrează pe rândurile tenantului, fără indexuri GIN trigram

- **Status**: Accepted
- **Data**: 2026-09-13
- **Decidenți**: proprietarul proiectului
- **Related**: [[ADR-003]], [[ADR-016]]

## Context și problema

Căutarea globală (FR-SEARCH-01/02) folosește `pg_trgm` direct în PostgreSQL: operatorul `%` pentru toleranța la greșeli de tastare și `ILIKE` pentru subșiruri. BR-SEARCH-01 cerea indexuri GIN trigram pe `accounts.name`, pe `contacts.(first_name || ' ' || last_name)` și pe `deals.title`, iar pachetul de căutare din Faza 2 le-a creat.

La verificarea cu `db:explain-critical`, interogarea pe contacte a căzut pe `Seq Scan`. Code review-ul a identificat cauza, iar măsurătoarea de mai jos a confirmat-o: **pe o tabelă cu RLS, PostgreSQL nu poate folosi un index pentru o condiție care conține funcții non-LEAKPROOF.** Politica RLS acționează ca o barieră de securitate. Orice funcție care ar putea scurge informații despre rânduri invizibile (prin erori sau timing) se evaluează DUPĂ condiția politicii, niciodată ca `Index Cond`. Nici `similarity` / `similarity_op` (operatorul `%`), nici `texticlike` (`ILIKE`) nu sunt LEAKPROOF.

Nu e o chestiune de volum. Indexurile nu devin utilizabile la mai multe date; doar filtrul devine mai scump.

## Drivers de decizie

- **Măsurat, nu dedus** — aceeași regulă ca în [[ADR-016]].
- **Izolarea rămâne neatinsă** ([[ADR-003]]): nicio relaxare a barierei de securitate a bazei.
- **Buget de memorie și scriere** (`.ai/rules/project.md`): un index care nu poate fi folosit costă la fiecare scriere, fără niciun beneficiu.
- **Pragul real e p95 < 200 ms pe citiri simple** (specs §20.1), nu „are index".

## Măsurătoarea

Mediu: PostgreSQL 16.14, baza de dev după `demo:reset` (Marlin: 4.000 de conturi, ~5.200 de contacte dintr-un total de 10.385, 2.200 de deals), indexurile GIN încă prezente. Aceeași interogare ca în `GlobalSearchService`, cu filtrul pe tenant pe care îl adaugă global scope-ul.

```
proname       | proleakproof
similarity    | f
similarity_op | f
texticlike    | f
```

| Tabelă | Fără RLS (superuser) | Sub RLS (`throughput_app`, context Marlin) |
|---|---|---|
| `accounts` (`name % 'fastners' OR name ILIKE …`) | BitmapAnd: indexul de tenant ∧ `accounts_name_trgm` (Index Cond pe `%` și `~~*`), 8,2 ms | Bitmap Index Scan pe indexul de tenant; `%`/`ILIKE` doar ca **Filter**, 3.842 de rânduri eliminate, 7,6 ms |
| `contacts` (nume complet) | Bitmap Index Scan pe `contacts_name_trgm`, 0,6 ms | **Seq Scan**, filtru pe tenant + trigram, 10.365 de rânduri eliminate, 22,5 ms |
| `deals` (`title`) | — | Bitmap Index Scan pe indexul de tenant; trigram ca Filter, 1.923 de rânduri eliminate, 6,4 ms |

Singura diferență dintre coloane e RLS-ul. Fără el, GIN-ul intră în plan; cu el, lipsește din plan pe toate trei tabelele.

## Opțiuni considerate

1. **Funcțiile `pg_trgm` marcate LEAKPROOF** (în scriptul de bootstrap, ca superuser). GIN-ul devine utilizabil, dar e o relaxare deliberată a barierei de securitate. `ILIKE` ar trebui scos (`texticlike` e o funcție de bază, folosită de tot `ILIKE`-ul din aplicație), iar căutarea ar rămâne doar pe `%` / word similarity. Respinsă: câștigul (de la ~20 ms la ~1 ms) nu contează la scara acestui produs, riscul e permanent.
2. **Filtrul acceptat, indexurile păstrate** pentru o eventuală trecere la opțiunea 1. Costă la scriere și în memorie fără beneficiu azi. Respinsă.
3. **Filtrul acceptat, indexurile scoase.** **Aleasă.**

## Decizia

- Căutarea globală rămâne pe `%` (prag implicit 0.3) + `ILIKE`, evaluate pe rândurile tenantului curent. Accesul la aceste rânduri trece prin indexul compus cu `tenant_id` pe prima poziție (addendum [[ADR-003]]) sau prin Seq Scan, după alegerea planificatorului.
- Migrația cu indexurile GIN trigram se șterge. Nimic nu era împins sau implementat în producție.
- `db:explain-critical` tratează intrările de căutare separat: Seq Scan-ul e acceptat explicit, iar în locul lui se aplică un buget de timp de execuție (implicit 200 ms, pragul §20.1). Restul interogărilor critice își păstrează regula strictă.
- BR-SEARCH-01 (specs.md) și plan §8/§14 se corectează, cu intrare în Change Log.

## Consecințe

### Pozitive

- Nicio relaxare a securității bazei. Izolarea pe două straturi rămâne exact cea din [[ADR-003]].
- Mai puțin cost la scriere și mai puțină memorie (3 indexuri GIN în minus, plus cele pregătite pentru produse).
- `db:explain-critical` nu mai pică pe un plan corect și nu mai cere un index imposibil de folosit.

### Negative / trade-offs

- Costul căutării crește liniar cu numărul de rânduri ale tenantului: ~20 ms pe ~5.000 de contacte azi. Un tenant de ordinul sutelor de mii de rânduri ar cere reevaluarea — opțiunea 1 sau un motor dedicat (FR-SEARCH-02 fixează pragul acela la „zeci de milioane de rânduri").
- Căutarea pe contacte face Seq Scan pe tenantul vitrină. Corect ca plan, dar vizibil în `EXPLAIN`; de aceea regula din `db:explain-critical` e explicit diferită pentru căutare.

## Istoric

- 2026-09-13 — creat. Proprietarul a ales opțiunea 3, pe baza măsurătorii de mai sus și a code review-ului pachetului de căutare.
