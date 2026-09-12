# ADR-007: Jurnal de activitate cu cod propriu, nu `owen-it/laravel-auditing`

- **Status**: Accepted
- **Date**: 2026-09-12
- **Deciders**: Proprietar
- **Tags**: audit, observers, cozi, sprint-5

## Context și problema

Pachetul `owen-it/laravel-auditing` e istoric cel mai folosit pentru acest tipar în Laravel. Research-ul din 2026-09-12 l-a menționat, dar a marcat explicit că **nu a fost validat pentru compatibilitate cu Laravel 12** în sesiunea de cercetare.

Alegerea e între a-l fixa ca dependință pe baza unei presupuneri și a scrie ~o jumătate de zi de cod.

## Decizia luată

**Cod propriu.** Eloquent model observers (`created`, `updated`, `deleted`) pe modelele de business relevante dispatch-uiesc un event; un **listener pe coadă** scrie rândul în `activity_log`, asincron.

Trei motive, în ordinea greutății:

1. **Nu fixăm o dependință nevalidată.** Dacă pachetul nu suportă Laravel 12, descoperim asta în Sprint 5, nu acum.
2. **Scrierea asincronă e cerință**, nu preferință — research-ul §8 o recomandă explicit, iar un pachet care scrie sincron în firul cererii ar trebui oricum ocolit.
3. **Consecvență cu ADR-001** — proiectul demonstrează construcția, nu configurarea. Un jurnal de audit scris de mână e exact genul de cod pe care un recenzent tehnic îl citește cu atenție.

## Consecințe

### Pozitive

- Control total pe forma diff-ului și pe politica de retenție.
- Nicio dependență de ritmul de întreținere al unui pachet terț pe o funcție de conformitate.

### Negative / trade-offs

- ~O jumătate de zi de cod în plus și responsabilitatea testării.
- Funcții pe care pachetul le-ar fi oferit gratis (audit al relațiilor many-to-many, restaurarea unei versiuni anterioare) nu există în MVP. Neplanificate; se adaugă dacă apare nevoia.
