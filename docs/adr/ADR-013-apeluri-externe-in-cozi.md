# ADR-013: Apelurile externe ies din cererea HTTP, în cozi

- **Status**: Accepted
- **Date**: 2026-09-12
- **Deciders**: Proprietar
- **Related**: [[ADR-003]] (tranzacția vine din mecanismul de izolare), [[ADR-010]] (curierat)
- **Tags**: performanta, tranzactii, cozi, postgresql, sprint-5

## Context și problema

[[ADR-003]] impune `SET LOCAL app.tenant_id` pentru fiecare cerere, iar `SET LOCAL` există **doar** în interiorul unei tranzacții. Implementarea literală — middleware-ul `ApplyTenantContext` — înfășoară tot `$next($request)` într-un `DB::transaction()`.

Consecința nu e evidentă: **orice apel extern sincron dintr-un controller se execută cu o tranzacție Postgres deschisă.** Specificația descrie două astfel de apeluri:

- **crearea unui shipment** cheamă adapterul de curierat și așteaptă `tracking_number` (§11.2, pasul 4);
- **generarea unui PDF de factură** pornește un Chromium efemer prin `spatie/laravel-pdf` — planul estimează 150–250 MB și câteva secunde (§3.1).

Pe un container cu `shared_buffers=64MB` și `max_connections=30`, o tranzacție ținută deschisă câteva secunde, cu blocările de rând acumulate până acolo, e o problemă reală sub concurență. Nu se manifestă la un singur dezvoltator; se manifestă când doi vizitatori fac simultan expedieri.

Auditul (P2-003) a semnalat-o și a propus **îngustarea tranzacției** la interogările propriu-zise.

## Drivers de decizie

- **Sub RLS, fiecare interogare are nevoie de context.** Nu poți scoate citirile din tranzacție fără să primești zero rânduri — asta face îngustarea mult mai puțin simplă decât pare.
- **Un utilizator nu trebuie să aștepte după Chromium.** Trei secunde de așteptare la „Generează factura" e o experiență proastă independent de orice problemă de tranzacții.
- **Progresul vizibil e cel mai ieftin „wow moment"** al demo-ului, per research. Încă un loc unde apare e un câștig, nu un cost.

## Opțiuni considerate

### Opțiunea 1: Îngustarea tranzacției (propunerea auditului)

- **Pro**: atacă direct simptomul; nicio schimbare de flux.
- **Contra**: sub RLS, contextul e necesar la **fiecare** interogare, inclusiv citirile din controller. Îngustarea cere fie o a doua strategie de setare a contextului pentru citiri, fie disciplină manuală în fiecare acțiune — exact genul de regulă pe care cineva o încalcă peste șase luni, cu efect tăcut.

### Opțiunea 2: Apelurile externe ies din cerere, în cozi (ALEASĂ)

- **Pro**: tranzacția rămâne scurtă prin construcție, nu prin disciplină. Cererea răspunde imediat. Chromium iese de pe calea critică. Se adaugă încă un loc cu progres vizibil.
- **Contra**: fluxul devine asincron — interfața trebuie să afișeze o stare intermediară („Se generează…") și să facă polling.

## Decizia luată

**Opțiunea 2.** Niciun apel către un serviciu extern nu se execută în interiorul unei cereri HTTP.

- **Eticheta de curierat**: `CreateShipmentAction` persistă shipment-ul cu `status = label_pending` și pune în coadă `GenerateShippingLabelJob`. Jobul apelează adapterul ([[ADR-010]]), apoi deschide o tranzacție **scurtă** doar ca să scrie `tracking_number` și `label_url`. Interfața face polling, ca la operațiile în masă.
- **PDF-ul de factură**: idem — `invoices.pdf_status` (`pending` / `ready` / `failed`), job de generare, buton de descărcare activ abia când e gata.

Regula, scrisă ca atare în plan: **dacă o acțiune apelează ceva ce nu e baza de date proprie, acțiunea aceea aparține unei cozi.**

## Consecințe

### Pozitive

- Tranzacțiile rămân de ordinul milisecundelor, indiferent de starea unui serviciu extern.
- Un sandbox de curierat căzut nu mai ține blocări de rând — jobul eșuează și se reîncearcă.
- Două locuri în plus unde demo-ul arată progres live.
- Chromium nu mai concurează cu PHP-FPM pentru memorie în timpul unei cereri.

### Negative / trade-offs

- Două fluxuri devin asincrone, cu stare intermediară de afișat. Reutilizează componenta de polling deja construită pentru operațiile în masă (Faza 3), deci costul e de interfață, nu de arhitectură.
- Un job eșuat trebuie să fie vizibil, altfel utilizatorul așteaptă la nesfârșit. Ambele stări au `failed` explicit, cu mesaj și buton de reîncercare.
- **Worker-ul de coadă e unul singur** (`maxProcesses: 1`, vezi P3-004). Etichetele și PDF-urile intră pe aceeași coadă cu importurile și rapoartele. Prioritizarea cozilor există deja; măsurătoarea de latență rămâne de făcut înainte de lansare.
