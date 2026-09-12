# ADR-004: Stocul ca registru append-only, nu ca o cantitate mutabilă

- **Status**: Accepted
- **Date**: 2026-09-12
- **Deciders**: Tech Lead
- **Related**: [[ADR-001]]
- **Tags**: inventar, model-date, audit, sprint-3

## Context și problema

Modulul de stoc trebuie să răspundă la două întrebări: „câte bucăți am acum" și „de ce atâtea". A doua e cea care creează încredere într-un demo.

Implementarea naivă ține o coloană `quantity` pe care o incrementează și decrementează. Răspunde rapid la prima întrebare și deloc la a doua.

## Drivers de decizie

- **Auditabilitate** — „de ce arată 7 bucăți când eu am numărat 9" e întrebarea pe care orice operator de depozit a pus-o măcar o dată. Un sistem care nu poate răspunde pierde încrederea.
- **Corectitudine sub concurență** — două operații simultane pe aceeași coloană cer blocare; un registru doar-adăugare nu are conflict de scriere.
- **Semnal de competență** — e distincția care se vede cel mai clar între o implementare de începător și una matură, iar proiectul are ca scop exact demonstrarea acestei diferențe.

## Opțiuni considerate

### Opțiunea 1: Coloană `quantity` mutabilă

- **Pro**: trivial de implementat; citire instantanee.
- **Contra**: fără istoric; imposibil de reconciliat; necesită blocare pesimistă la scrieri concurente; o corecție greșită e ireversibilă și invizibilă.

### Opțiunea 2: Registru append-only + `on_hand` materializat (ALEASĂ)

- **Pro**: fiecare mișcare are motiv, moment, autor și referință la documentul care a produs-o. Reconciliere completă. Scrierile sunt inserări, deci fără conflict. Corecțiile sunt mișcări noi, nu ștergeri.
- **Contra**: mai mult cod; `on_hand` trebuie menținut corect, altfel are două surse de adevăr.

## Decizia luată

**Aleasă: Opțiunea 2.**

`stock_movements` e append-only: `variant_id`, `location_id`, `delta` (pozitiv sau negativ), `reason` (`receipt` / `sale` / `adjustment` / `return` / `transfer`), `ref_type` + `ref_id` spre documentul sursă, `created_by`, `created_at`. Fără `UPDATE`, fără `DELETE`.

Cantitatea disponibilă se materializează într-un tabel `inventory_levels`, actualizat în aceeași tranzacție cu inserarea mișcării. Există o comandă de reconciliere care recalculează din registru și raportează divergențele — folosită și ca test: dacă recalcularea nu dă același rezultat, ceva a scris `on_hand` fără să scrie mișcarea.

## Consecințe

### Pozitive

- Orice cantitate afișată e explicabilă până la documentul care a produs-o.
- Rezervarea de stoc la confirmarea comenzii și eliberarea la anulare devin naturale (mișcări cu motive distincte).
- Demonstrația „am expediat 500 de comenzi, uite stocul mișcându-se rând cu rând" e posibilă doar cu această structură.

### Negative / trade-offs

- Registrul crește nelimitat. Pentru un demo e irelevant; pentru producție ar cere agregare periodică pe perioade închise. Notat, nu implementat.
- Două locuri de scris la fiecare mișcare (registru + nivel materializat), obligatoriu în aceeași tranzacție. Protejat de comanda de reconciliere și de un test dedicat.
