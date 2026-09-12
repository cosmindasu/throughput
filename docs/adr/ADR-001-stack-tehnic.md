# ADR-001: Stack tehnic — Laravel 12 + Inertia v2 + React 19 + PostgreSQL 16

- **Status**: Accepted — **versiunile sunt superseded parțial de [[ADR-015]]**
- **Date**: 2026-09-12
- **Deciders**: Tech Lead
- **Related**: [[ADR-003]] (RLS depinde de alegerea PostgreSQL), [[ADR-015]] (versiunile efective)
- **Tags**: stack, laravel, inertia, react, postgresql, sprint-0

> **Corecție de versiune, 2026-09-12 (a se citi împreună cu [[ADR-015]]).** La prima rulare a scaffold-ului, `composer create-project laravel/laravel` a instalat **Laravel 13**, iar pachetul de Inertia e la **v3**. Stack-ul efectiv e deci **Laravel 13 + Inertia 3 + React 19 + Tailwind 4 + PostgreSQL 16**. [[ADR-015]] conține măsurătoarea care arată că întreg setul de pachete al proiectului rezolvă identic pe Laravel 12 și 13, deci schimbarea nu a costat nimic în compatibilitate.
>
> **Restul documentului rămâne în vigoare integral** — alegerea Laravel + Inertia + React + PostgreSQL față de Next.js, Filament și Livewire, cu cele patru opțiuni evaluate, nu e afectată de versiuni. Nu am rescris argumentarea ca să schimb două numere.

## Context și problema

Throughput e al 12-lea proiect din portofoliu și primul care **nu** e un site de prezentare pentru un IMM. Rolul lui e să demonstreze competența de „internal tooling" — aplicații de business folosite zilnic — în fața cumpărătorilor internaționali de pe platforme de freelancing.

Alegerea stack-ului e constrânsă de trei lucruri simultan: ce demonstrează cel mai mult, ce încape pe infrastructura existentă, și ce cere piața.

Portofoliul actual conține deja **6 proiecte pe Next.js** și **4 care folosesc Filament** ca panou de administrare.

Infrastructura: VPS partajat cu celelalte 11 proiecte, 72 de containere, în curs de migrare la 10 GB RAM. Măsurătoarea din 2026-09-08 a arătat 11 ucideri OOM, 10 dintre ele pe procese `next-server`.

## Drivers de decizie

- **Diferențiere de portofoliu** — al 7-lea proiect Next.js sau al 5-lea Filament nu demonstrează nimic nou unui cumpărător care a văzut deja celelalte.
- **Amprentă de memorie** — proiectul se adaugă pe o mașină cu istoric de presiune pe memorie.
- **Cerere pe piață** — „Laravel + React internal tool" e o combinație cu cerere constantă pe platformele de freelancing.
- **Capacitatea de a demonstra UI propriu** — nu doar configurarea unui panou generat.

## Opțiuni considerate

### Opțiunea 1: Next.js 15 full-stack

- **Pro**: consecvent cu majoritatea portofoliului; un singur limbaj; ecosistem React nativ.
- **Contra**: un proces Node care rulează permanent și își păstrează heap-ul chiar inactiv — exact profilul care a produs cele 10 ucideri OOM măsurate. Și nu demonstrează nimic care să nu fie deja demonstrat de celelalte 6.

### Opțiunea 2: Laravel 12 + Filament 4

- **Pro**: cel mai rapid drum la un CRUD complet; RBAC și resurse gata făcute.
- **Contra**: Filament se recunoaște instantaneu. Concluzia unui cumpărător devine „știe să configureze Filament", ceea ce e o afirmație mult mai mică decât „știe să construiască interfața". E deja în 4 proiecte din portofoliu.

### Opțiunea 3: Laravel 12 + Inertia v2 + React 19 + TypeScript + PostgreSQL 16 (ALEASĂ)

- **Pro**: PHP-FPM pe `ondemand` coboară aproape la zero între cereri — diferența dintre a încăpea și a nu încăpea pe mașina actuală. Cozi, scheduler și broadcasting incluse (necesare pentru rapoarte programate și operații în masă). Interfață proprie în React, deci demonstrează construcția, nu configurarea. Combinație cu cerere mare pe piață.
- **Contra**: două limbaje în același proiect; Inertia cere disciplină la granița props/controller ca să nu devină un API deghizat.

## Decizia luată

**Aleasă: Opțiunea 3.**

Laravel 12 (PHP 8.3) + Inertia v2 + React 19 + TypeScript strict + PostgreSQL 16 + Redis + Horizon. Tailwind 4 cu componente proprii, fără bibliotecă de panou generat. Playwright pentru E2E.

**PostgreSQL, nu MySQL**, pentru un motiv anume: Row-Level Security (vezi [[ADR-003]]). Izolarea între tenanți impusă în bază, nu doar în cod, e detaliul care liniștește recenzentul tehnic al unui client.

## Consecințe

### Pozitive

- Amprentă de memorie estimată la 250–400 MB la vârf, față de ~780 MB măsurați pe containerele Next existente.
- Portofoliul capătă o a doua categorie vizibilă, nu încă o variație a primei.
- Cozile și scheduler-ul necesare pentru rapoarte programate și operații în masă vin din cutie.

### Negative / trade-offs

- Două limbaje de întreținut (acceptat: e și un argument de competență).
- Fără Filament, CRUD-ul de bază durează mai mult de scris (acceptat: chiar asta se demonstrează).
- Inertia cere convenții clare pentru props; fără ele, controllerele devin greu de citit. Se stabilesc în Sprint 0.
