# ADR-019: Exportul PDF de listă (Orders) cu DomPDF, nu cu Chromium în imagine

- **Status**: Accepted
- **Date**: 2026-09-14
- **Deciders**: Proprietar
- **Related**: [[ADR-013]] (generarea rămâne în coadă, niciodată în cererea HTTP), [[ADR-006]] (facturile de abonament prin Cashier 16 — vezi neconcordanța din nota de la final)
- **Tags**: pdf, exporturi, memorie, faza-3

## Context și problema

specs.md §13.5 cere pe Comenzi „Export CSV/PDF, anulare în masă (doar `draft`), reasignare owner"; plan §9 (Faza 3) are ca livrabil explicit „Export CSV/PDF în masă funcțional pe Orders". Mecanismul de export de listă există deja (`ExportableResources` → `ListExport` → `ExportListJob`, pe coada `bulk`, ADR-013), dar exportă azi doar CSV, pentru Accounts și Contacts (specs.md §13.2, §13.5). Orders e prima resursă care cere și PDF.

`spatie/laravel-pdf` (2.13.1) e dependență directă din Sprint 0 (plan §6, task 3), prevăzută pentru facturi și rapoarte. Config-ul lui nu e publicat, deci driverul implicit e cel din pachet, `browsershot` (`LARAVEL_PDF_DRIVER`), care randează prin Chromium. Nicio cale din cod nu îl apelează încă. Plan §3.1, la justificarea celor 384m ai containerului `horizon`, scrie: „Job-urile de PDF (`spatie/laravel-pdf`, facturi + rapoarte) pot lansa un proces Chromium efemer (150-250 MB) — plafonul acoperă un vârf, nu media." Nota a fost scrisă cu facturile (Faza 5) și rapoartele (Faza 4) în minte, nu cu exportul de listă din Faza 3, care apare acum, primul.

`docker/app/Dockerfile` confirmă că imaginea de producție **nu are Chromium sau Node la runtime**: stage-ul `node:22-alpine` (`assets`) compilează doar `public/build` prin Vite și nu e copiat în stage-ul `production`, dincolo de acel director static. A folosi `browsershot` pentru exportul de Orders ar cere fie un binar Chromium adăugat în imaginea `production` (greutate + suprafață de atac în plus), fie un proces Node persistent — niciuna dintre ele o linie de config, ambele o schimbare de imagine.

`vendor/spatie/laravel-pdf/src/Drivers/DomPdfDriver.php` există deja, dar cere `dompdf/dompdf` (^3.0), care **nu** era instalat. Pachetul intră în `composer.json` odată cu această decizie (instalat 3.1.6, `composer audit` curat) — singura dependență nouă, PHP pur. E, în plus, exact pachetul pe care `laravel/cashier` 16.8 îl folosește implicit la factura de abonament (`DompdfInvoiceRenderer`, config-ul Cashier nepublicat).

## Drivers de decizie

- **Bugetul de memorie** (`.ai/rules/project.md`): 250-400 MB la vârf, VPS partajat cu 11 ucideri OOM deja măsurate. `horizon` are 384m, deja parțial ocupați de restul cozii (`imports`, `reports`, `bulk`, cu un singur worker — plan §3).
- **„Dacă o soluție cere încă un serviciu, răspunsul implicit e nu"** (project.md) — Gotenberg ar introduce exact asta.
- **Imaginea de producție nu are Chromium/Node la runtime** — a-l adăuga e o schimbare de `Dockerfile`, nu o alegere de driver într-un `->driver()`.
- **Exportul de listă e un tabel lung, nu un document cu layout complex** — cazul exact pentru care limitările DomPDF (CSS 2.1, fără JS) nu costă nimic vizibil, spre deosebire de o factură cu accente grafice.
- **ADR-013 rămâne în vigoare indiferent de driver**: generarea nu poate ține tranzacția cererii deschisă — trebuie job pe coadă, cu driverul ca detaliu de implementare din interiorul acelui job.
- **Facturile și rapoartele nu sunt decise aici** — planul presupune Chromium pentru ele (§3.1); o decizie prematură de driver „pentru tot PDF-ul din proiect" ar lega greșit două cazuri de uz diferite.

## Opțiuni considerate

### Opțiunea 1: DomPDF, via `spatie/laravel-pdf` (ALEASĂ)

- **Pro**: PHP pur, fără proces extern, fără binar și fără serviciu nou. Imaginea de producție rămâne neschimbată. Rulează în interiorul worker-ului `horizon` deja bugetat, fără vârf de proces separat. Aceeași bibliotecă pe care Cashier 16.8 o folosește implicit pentru facturile de abonament.
- **Contra**: o dependență nouă în `composer.json`. CSS 2.1 (fără flex/grid) — șablonul trebuie construit tabelar, simplu. Lent și cu memorie crescândă pe tabele foarte lungi — de aici plafonul de rânduri. Fonturi limitate (DejaVu inclus, acoperă diacriticele românești). Fără JavaScript.

### Opțiunea 2: Chromium în imagine (Browsershot / Chrome PHP)

- **Pro**: randare fidelă (CSS modern, layout complex); ar deservi și facturile din Faza 5 cu același mecanism.
- **Contra**: vârf de 150-250 MB pe worker-ul unic Horizon (plan §3.1), pe un container deja aproape de plafon cu reset-ul de demo (ADR-017); imagine de producție mai mare; Chromium la runtime pe VPS-ul cu istoric de OOM e exact riscul pe care bugetul de memorie vrea să-l evite.

### Opțiunea 3: Gotenberg

- **Pro**: randare prin Chromium, izolată într-un container dedicat, fără Node în imaginea aplicației.
- **Contra**: un container în plus — contrazice direct regula „încă un serviciu = nu" (project.md) și adaugă un nou plafon de memorie pe un VPS deja la limită.

### Opțiunea 4: WeasyPrint

- **Pro**: randare CSS mai bună decât DomPDF (suport parțial flex), fără Chromium.
- **Contra**: binar Python în imagine — o dependență de sistem nouă, absentă azi din `docker/app/Dockerfile`, pentru un caz de uz (tabel lung) care nu are nevoie de ea.

### Opțiunea 5: Cloudflare Browser Rendering

- **Pro**: fără proces sau binar în imagine, driver deja disponibil în `spatie/laravel-pdf` (`CloudflareDriver`).
- **Contra**: subprocesator extern nou, absent din specs.md §28.2 — cost recurent și date de comenzi (nume de clienți, adrese) trimise în afara infrastructurii proprii pentru o operație care n-are nevoie de asta. Ar cere și actualizarea listei de subprocesatori și, probabil, a politicii de confidențialitate.

### Opțiunea 6: Amânarea PDF-ului la Faza 5

- **Pro**: zero cod nou acum.
- **Contra**: livrabilul Fazei 3 din plan §9 („Export CSV/PDF în masă funcțional pe Orders") ar rămâne parțial fără motiv tehnic real — DomPDF acoperă cazul azi, cu o singură dependență PHP pură.

## Decizia luată

**Opțiunea 1.** Exportul PDF de listă (primul caz: Orders, Faza 3) folosește driverul `dompdf` al `spatie/laravel-pdf`, ales **explicit la fiecare apel** (`Pdf::view(...)->driver('dompdf')->save($path)`), **fără să schimbe** `config('laravel-pdf.driver')`/`LARAVEL_PDF_DRIVER` (rămâne `browsershot`, implicitul pachetului). Alegerea per-apel, nu globală, ține independentă decizia de driver pentru facturile către clienți (Faza 5) și pentru rapoartele PDF (Faza 4) — niciuna nu e decisă de acest ADR.

- **Job**: `ExportListJob` (sau succesorul lui direct, când capătă parametrul de format) rămâne pe coada `bulk`, în afara tranzacției cererii — regula ADR-013 nu depinde de driver.
- **Plafon de rânduri pentru formatul PDF**: config nou, `limits.export_pdf_max_rows` (env `EXPORT_PDF_MAX_ROWS`), **implicit 500**. Pornit de la „ordinul a 1.000", coborât după prima măsurătoare: `PdfExporter` rulat izolat, pe mașina de dezvoltare, a dat 97 MB / 0,5 s la 100 de rânduri, 227 MB / 2,8 s la 500, 347 MB / 5,3 s la 750 și 499 MB / 8,3 s la 1.000. Creșterea nu e liniară, fiind dată de layout-ul de tabel al DomPDF. La 1.000 de rânduri vârful depășește plafonul de 384m al lui `horizon`. Cifra rămâne de reconfirmat pe imaginea de producție (același tipar ca §7.10 și ca ADR-017: „măsurat, nu presupus"). Peste plafon, formatul PDF e refuzat cu mesaj explicit care direcționează spre CSV (fără format oprit — CSV rămâne disponibil până la `export_sync_max_rows`/`bulk_max_rows`, deja existente); niciun plafon nou pe CSV.
- **Config `dompdf.is_remote_enabled`** rămâne `false` (implicitul pachetului) — șabloanele de export nu încarcă resurse remote (imagini, fonturi de la un URL extern), doar CSS 2.1 inline/local.
- **Vârful de memorie al jobului** se măsoară la plafonul de rânduri, pe imaginea de producție, înainte de lansare. Dacă depășește bugetul lui `horizon` (384m, deja parțial ocupat de restul cozii concurente — imports/reports/bulk), **plafonul de rânduri scade**, nu crește bugetul containerului (project.md: bugetul e fix, se negociază lucrul care intră în el, nu limita).

## Consecințe

### Pozitive

- Zero servicii noi, zero schimbare de `Dockerfile`, zero dependență de sistem nouă; o singură bibliotecă PHP pură în `composer.json`.
- Memorie predictibilă: PHP pur, în interiorul worker-ului deja bugetat, fără vârf de proces extern suprapus peste restul cozii `bulk`.
- Decizia de driver pentru facturi (Faza 5) și rapoarte (Faza 4) rămâne complet deschisă — DomPDF e acum o opțiune deja prezentă în proiect pentru oricare din ele.
- Consistent cu ADR-013: niciun apel „greu" nu intră în cererea HTTP, indiferent de driver.

### Negative / trade-offs

- CSS 2.1 — fără flex/grid — șablonul PDF de export trebuie să rămână tabelar și simplu; un designer care se așteaptă la fidelitatea unui export CSS modern nu o va găsi aici.
- Plafon de rânduri nou de explicat în UI: un export mare rămâne posibil, dar doar în CSV. Costul e o regulă în plus vizibilă utilizatorului, nu o limitare tăcută.
- DomPDF e lent și cu memorie crescândă pe tabele foarte lungi, motivul exact al plafonului. Prima măsurătoare (de la 97 MB la 100 de rânduri la 499 MB la 1.000) a coborât plafonul de la „ordinul a 1.000" la 500. Până la reconfirmarea pe imaginea de producție, 500 e o cifră de pe mașina de dezvoltare, nu una de producție.
- Nota deja existentă în `docs/adr/README.md` („Unificarea generării de PDF") rămâne deschisă și se extinde cu acest caz, nu se închide de acest ADR.

## Notă pentru plan/specs (de trecut în Change Log de proprietar)

- **plan-implementare.md §3.1**, justificarea celor 384m ai `horizon`, scrie generic „Job-urile de PDF (`spatie/laravel-pdf`, facturi + rapoarte) pot lansa un proces Chromium efemer (150-250 MB)". De la acest ADR, exportul de listă pe Orders (Faza 3) **nu** intră în acea descriere — folosește DomPDF, în proces, fără Chromium. Rândul rămâne corect pentru facturi (Faza 5) și rapoarte (Faza 4) **doar dacă** acelea aleg tot Chromium; nu e garantat — DomPDF e acum o opțiune prezentă și pentru ele. Tabelul din §3.1 va trebui reconciliat cel târziu când se ia acea decizie (Faza 4 pentru rapoarte, Faza 5 pentru facturi), ca justificarea plafonului lui `horizon` să reflecte ce rulează efectiv, nu o presupunere din Sprint 0.
- **ADR-006 nu corespunde codului instalat.** Textul lui spune că facturile de abonament din Cashier 16 se generează cu `spatie/laravel-pdf`, „nu `dompdf`". `laravel/cashier` 16.8 are însă implicit `DompdfInvoiceRenderer` (`vendor/laravel/cashier/config/cashier.php`, `CASHIER_INVOICE_RENDERER`), iar `LaravelPdfInvoiceRenderer` e doar o opțiune comentată; config-ul Cashier nu e publicat în aplicație. Până la instalarea `dompdf/dompdf` pentru acest ADR, descărcarea unei facturi de abonament ar fi eșuat. Corecția ADR-006 (și alegerea renderer-ului de factură) rămâne decizia proprietarului, cel târziu în Faza 5.

## Istoric

- 2026-09-14 — creat. Proprietarul a ales Opțiunea 1 dintre cele șase de mai sus, pentru exportul PDF de listă pe Orders (Faza 3).
