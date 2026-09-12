# ADR-015: Laravel 13 și Inertia 3, nu Laravel 12 și Inertia 2

- **Status**: Accepted
- **Data**: 2026-09-12
- **Decidenți**: proprietarul proiectului
- **Supersedează parțial**: [[ADR-001]] — exclusiv versiunile de framework și de Inertia. Restul lui ADR-001 (alegerea Laravel + Inertia + React + PostgreSQL față de Next.js, Filament sau Livewire, și motivele acelei alegeri) rămâne în vigoare, neatins.

## Context și enunțul problemei

[[ADR-001]] a fost scris la 2026-09-12 dimineață și pinuiește stack-ul la **Laravel 12 + Inertia v2 + React 19 + PostgreSQL 16**. La prima rulare efectivă a scaffold-ului, în aceeași zi, `composer create-project laravel/laravel` a instalat **Laravel 13.31.0**, iar cel mai recent `@inertiajs/react` e **3.7.1** (`inertiajs/inertia-laravel` v3.3.4).

Cu alte cuvinte: ambele au trecut o versiune majoră peste ce presupun documentele, iar discrepanța a apărut în minutul în care s-a scris prima linie de cod, nu peste luni. Trebuia rezolvată înainte de orice altceva — un re-scaffold costă zero acum și crește cu fiecare fază.

Convenția proiectului (`docs/adr/README.md`) spune că **un ADR acceptat nu se rescrie**: dacă decizia se schimbă, se scrie unul nou care îl supersedează. De aici acest document, în loc de o editare tăcută în ADR-001.

## Factori de decizie

1. **Cost de compatibilitate pe stack-ul deja planificat** — dacă un pachet din plan nu suportă Laravel 13, discuția se închide singură.
2. **Fereastra de suport** a versiunii pe care pornește un proiect *nou*.
3. **Ce vede audiența secundară** din `specs.md` §1.5 — recrutorul tehnic / CTO care face un code review rapid, și care rulează el însuși `laravel new` ca punct de comparație.
4. **Costul de sincronizare** a documentelor.

## Opțiuni considerate

### Opțiunea A — Laravel 13 + Inertia 3 (aleasă)

Am **măsurat** compatibilitatea, nu am presupus-o: am rezolvat întreg setul de pachete din plan pe ambele variante de framework, cu `composer update --dry-run`, platformă fixată pe PHP 8.3 și `minimum-stability: stable`.

| Pachet | Pe `laravel/framework ^12.0` | Pe `^13.0` |
|---|---|---|
| `inertiajs/inertia-laravel` | v3.3.4 | v3.3.4 |
| `laravel/cashier` | v16.8.0 | v16.8.0 |
| `laravel/horizon` | v5.49.0 | v5.49.0 |
| `laravel/sanctum` | v4.3.3 | v4.3.3 |
| `spatie/laravel-permission` | 8.3.0 | 8.3.0 |
| `spatie/laravel-pdf` | 2.13.1 | 2.13.1 |
| `maatwebsite/excel` | 4.0.2 | 4.0.2 |
| `pestphp/pest` | v4.7.8 | v4.7.8 |
| **framework** | **v12.69.2** | **v13.31.0** |

**Rezoluția e identică pe toate cele opt pachete.** Singura diferență între cele două variante e versiunea framework-ului. Costul de compatibilitate al lui Laravel 13, pe exact stack-ul din `plan-implementare.md`, e **zero** — iar asta e o măsurătoare reproductibilă, nu o impresie.

Restul stack-ului din ADR-001 se confirmă neschimbat: **React 19** (19.3.0), **Tailwind 4** (4.3.3), **PostgreSQL 16**.

### Opțiunea B — rămânem pe Laravel 12, ca în documente

Zero muncă de sincronizare pe partea de framework și ADR-001 neatins. Respinsă:

- Laravel 12 a apărut în februarie 2025. După politica de suport publicată de Laravel (bug-fix ~18 luni, securitate ~2 ani), fereastra de **bug-fix activ s-a închis în august 2026** — adică o lună înainte de data acestei decizii. Rămâne doar suportul de securitate. A porni un proiect *nou* acolo e o alegere greu de apărat în fața cititorului de la factorul 3.
- Argumentul central al proiectului către cumpărător e „cod actual, idiomatic". Un reviewer care rulează `laravel new` și compară vede imediat o versiune majoră în urmă, iar explicația („planul era scris pentru 12") e exact tipul de răspuns pe care proiectul ăsta există ca să nu-l dea.
- Nu elimină oricum munca de sincronizare: pachetul de Inertia e la v3 pe ambele variante, deci mențiunile „Inertia v2" trebuiau atinse indiferent de framework. Opțiunea B micșorează sincronizarea, nu o anulează.

### Opțiunea C — Laravel 13 cu Inertia 2

Respinsă fără testare serioasă: ar însemna să pinuiesc deliberat un pachet cu o versiune majoră în urmă, fără niciun beneficiu măsurabil, într-o combinație pe care nimeni n-o rulează. Complexitate în plus pentru nimic.

## Decizie

**Laravel 13 + Inertia 3 + React 19 + Tailwind 4 + PostgreSQL 16.** Scaffold-ul instalat la Sprint 0 rămâne cum e; se sincronizează documentele.

Consecințe operaționale imediate:

1. `composer.json` fixează `config.platform.php = 8.3` — PHP-ul local e 8.4, iar runtime-ul din container e 8.3 (`plan-implementare.md` §3). Fără asta, rezolvarea locală ar putea alege pachete care cer 8.4 și ar cădea în producție.
2. Cerințele care depind explicit de funcții introduse în Inertia 2 — **FR-PERF-01** (deferred props) și **FR-PERF-02** (prefetch on hover), plus polling-ul folosit pentru progresul operațiilor în masă și pentru starea etichetelor de curierat ([[ADR-013]]) — se **verifică pe API-ul lui Inertia 3 înainte de a fi implementate**, nu se presupun transferate. Dacă vreuna s-a schimbat de formă, se notează în faza care o construiește.
3. Mențiunile „Laravel 12" și „Inertia v2" din `specs.md`, `plan-implementare.md` și `stack-options.md` se actualizează, cu notă de versiune — nu tăcut.

## Consecințe

**Pozitive**

- Proiectul pornește pe versiunea curentă a framework-ului, cu suport activ de bug-fix.
- Costul măsurat e zero pe pachete; singura muncă e textuală.
- Discrepanța a fost prinsă la prima comandă de scaffold, nu în Faza 3, când ar fi însemnat rescriere.

**Negative / de acceptat**

- Documentele proiectului conțin, de la această dată, un strat de corecție de versiune: cititorul lui ADR-001 trebuie să ajungă și aici. Mitigat prin nota de supersedare din ADR-001 și prin rândul din indexul `docs/adr/README.md`.
- Laravel 13 e recent, deci ecosistemul de pachete mai mici (cele care nu apar în tabelul de mai sus) poate avea întârzieri. Nu afectează nimic din MVP-ul planificat — tot ce e planificat a fost testat mai sus.
- Un ADR care supersedează parțial altul e mai greu de citit decât unul care îl înlocuiește complet. Am preferat-o oricum: ADR-001 conține argumentarea alegerii stack-ului față de patru alternative, care rămâne valabilă integral și n-are de ce să fie rescrisă ca să schimb două numere.

## Legături

- [[ADR-001]] — stack tehnic (superseded parțial: doar versiunile)
- [[ADR-013]] — apelurile externe ies din cererea HTTP (depinde de polling-ul Inertia)
- `specs_si_design/stack-options.md` — cele patru opțiuni de stack evaluate
