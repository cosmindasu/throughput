# ADR-021: Factura de abonament rămâne pe `DompdfInvoiceRenderer`, implicitul Cashier — nu pe `spatie/laravel-pdf`

- **Status**: Accepted
- **Data**: 2026-09-19
- **Decidenți**: proprietarul proiectului
- **Supersedează parțial**: [[ADR-006]] — exclusiv fraza „Generarea PDF-urilor de factură de abonament folosește `spatie/laravel-pdf`, nu `dompdf` — schimbarea introdusă în Cashier 16." Restul [[ADR-006]] rămâne în vigoare, neatins: Cashier 16 pe API `2025-06-30.basil`, `Billable` = tenantul (organizația, nu utilizatorul), idempotența webhook-urilor prin `webhook_events`/unicitate pe `event_id`.
- **Related**: [[ADR-005]] (facturile către clienți, flux separat), [[ADR-013]] (apelurile/procesele grele nu în cererea HTTP), [[ADR-019]] (unde a fost măsurat bugetul de memorie și semnalată prima dată neconcordanța)
- **Tags**: stripe, cashier, pdf, memorie, faza-5

## Context și problema

[[ADR-006]] afirmă: „Generarea PDF-urilor de factură de abonament folosește `spatie/laravel-pdf`, nu `dompdf` — schimbarea introdusă în Cashier 16." Afirmația e o interpretare greșită, semnalată deja la 2026-09-14 în nota de închidere a [[ADR-019]] („ADR-006 nu corespunde codului instalat") și lăsată explicit ca „decizia proprietarului, cel târziu în Faza 5" — adică acum.

Ce spune codul instalat, nu presupunerea:

- `vendor/laravel/cashier/config/cashier.php:107` — `'renderer' => env('CASHIER_INVOICE_RENDERER', DompdfInvoiceRenderer::class)`. Cashier 16 a **adăugat** `LaravelPdfInvoiceRenderer` ca opțiune, nu ca implicit — implicitul rămâne `DompdfInvoiceRenderer`.
- Config-ul Cashier nu e publicat în proiect (`config/cashier.php` nu există în afara `vendor/`), iar `CASHIER_INVOICE_RENDERER` nu apare setat nicăieri (nici în `.env.example`). Renderer-ul efectiv, azi, e deci `DompdfInvoiceRenderer` — exact ce ADR-006 spune că NU se folosește.
- Dacă s-ar comuta pe renderer-ul pe care [[ADR-006]] îl descrie greșit ca fiind deja activ, `vendor/laravel/cashier/src/Invoices/LaravelPdfInvoiceRenderer.php` apelează `Pdf::html(...)->format($paper)->toResponse(...)`, **fără** `->driver('dompdf')` — adică pe driverul implicit al pachetului `spatie/laravel-pdf`, care e `browsershot` (Chromium), nu DomPDF.
- [[ADR-019]] a măsurat și a documentat că imaginea de producție **nu are Chromium sau Node la runtime**, pe un VPS cu buget de 250-400 MB și 11 ucideri OOM deja măsurate (`.ai/rules/project.md`). Descărcarea unei facturi de abonament pe acel renderer ar fi eșuat la rulare, nu la build.
- Codul propriu al proiectului alege deja driverul **explicit**, niciodată implicit din mediu: `app/Support/Exports/PdfExporter.php:55` și `app/Support/Reports/ReportFileWriter.php:57` apelează `Pdf::view(...)->driver('dompdf')`, cu docblock care spune de ce („implicitul pachetului rămâne liber pentru facturile din Faza 5, care pot alege alt driver fără să atingă exportul de liste" — `PdfExporter`). `dompdf/dompdf` ^3.0 e dependență directă din [[ADR-019]].

Faza 5 (plan §11) construiește acum abonamentul Stripe efectiv — primul cod care va atinge descărcarea unei facturi de abonament pe entitatea `Billable`. Decizia nu mai poate rămâne deschisă.

## Drivers de decizie

- **Bugetul de memorie e fix, nu se negociază în sus** (`.ai/rules/project.md`) — Chromium în runtime a fost deja respins, cu măsurători, în [[ADR-019]].
- **„Dacă o soluție cere încă un serviciu, răspunsul implicit e nu"** (`.ai/rules/project.md`) — valabil și pentru un binar/proces Node adăugat în imagine, nu doar pentru un container nou.
- **Alegerea driverului per apel, niciodată implicit de mediu** — convenția deja stabilită de `PdfExporter`/`ReportFileWriter`: o variabilă de mediu lipsă pe un mediu nou (Coolify, CI, o mașină de dezvoltare) nu trebuie să poată schimba tăcut comportamentul de la „funcționează" la „cade la rulare".
- **Zero cod nou, zero infrastructură nouă**, dacă alternativa nu aduce beneficiu măsurabil.
- **Onestitate în ADR, nu tăcere** — dacă decizia lasă două puncte de intrare spre aceeași bibliotecă, se spune explicit, nu se ascunde sub o „unificare" promisă și nerealizată.

## Opțiuni considerate

### Opțiunea 1: Rămânem pe implicitul Cashier, `DompdfInvoiceRenderer` (ALEASĂ)

Nu se publică `config/cashier.php`, nu se setează `CASHIER_INVOICE_RENDERER`. Cashier randează factura de abonament cu propriul șablon, prin `dompdf/dompdf` direct — fără nicio implicare a lui `spatie/laravel-pdf`.

- **Pro**: zero cod, zero config nou, zero risc de mediu — comportamentul e cel din pachet, deja activ și stabil. `dompdf/dompdf` e deja dependență directă ([[ADR-019]]), deci nu aduce nimic nou în `composer.json`. Niciun risc ca un mediu nou să cadă la rulare din lipsa unei variabile de mediu.
- **Contra**: coexistă două puncte de intrare spre DomPDF în proiect — cel al Cashier (șablonul lui, necontrolat de cod propriu) și `spatie/laravel-pdf` cu driver explicit (facturile către clienți din [[ADR-005]], exporturile și rapoartele din [[ADR-019]]). Promisiunea de „unificare" din `docs/adr/README.md` nu se realizează.

### Opțiunea 2: `CASHIER_INVOICE_RENDERER=LaravelPdfInvoiceRenderer` + `LARAVEL_PDF_DRIVER=dompdf`

Ar unifica pe un singur punct de intrare — `spatie/laravel-pdf` peste tot — exact cum promitea (greșit) formularea inițială din [[ADR-006]].

- **Contra, decisiv**: mută alegerea driverului pe un **implicit de mediu**. `LaravelPdfInvoiceRenderer` (vezi extrasul de mai sus) nu specifică `->driver()` în cod — depinde strict de `config('laravel-pdf.driver')`/`LARAVEL_PDF_DRIVER`. Dacă variabila lipsește pe un mediu nou (Coolify, CI, o mașină de dezvoltare fără `.env` complet), randarea cade pe implicitul pachetului, `browsershot`/Chromium — inexistent în imagine — iar eșecul apare la **rulare** (primul click pe „descarcă factura"), nu la build. Contrazice direct regula pe care `PdfExporter` o aplică deja explicit în cod, nu prin variabilă de mediu.
- **Pro**: un singur punct de intrare spre PDF pentru tot ce ține de Stripe/abonament + facturi + exporturi + rapoarte, dacă mediul e mereu configurat corect.

Respinsă: riscul e exact tiparul de eroare tăcută, dependentă de mediu, pe care restul proiectului îl evită prin construcție (`.ai/rules/tenancy.md` documentează un tipar similar la memoizarea per cerere).

### Opțiunea 3: Fără PDF local de abonament — doar facturile găzduite de Stripe Customer Portal

- **Pro**: elimină problema — Stripe randează și găzduiește PDF-ul, aplicația nu mai apelează niciun renderer local.
- **Contra**: pierde descărcarea din aplicație pentru un demo de portofoliu, unde acel ecran demonstrează integrarea Cashier. Respinsă.

### Opțiunea 4: Chromium în imaginea de producție

- **Contra**: deja respinsă, cu măsurători, în [[ADR-019]] (vârf de 150-250 MB pe un container deja aproape de plafon, plus suprafață de atac și greutate de imagine în plus). Nu se reia argumentarea aici.

## Decizia luată

**Opțiunea 1.** Renderer-ul facturii de abonament rămâne cel implicit al Cashier, `DompdfInvoiceRenderer`. Nu se publică `config/cashier.php`. Nu se setează `CASHIER_INVOICE_RENDERER` în niciun mediu. Costul e zero cod și zero infrastructură nouă.

Consecința asumată, explicit: proiectul are **două puncte de intrare** către aceeași bibliotecă (DomPDF):

1. cel al Cashier, cu șablonul lui de factură, apelat prin `DompdfInvoiceRenderer`, fără nicio implicare a codului propriu;
2. `spatie/laravel-pdf`, cu driverul ales explicit (`->driver('dompdf')`) în `PdfExporter` și `ReportFileWriter`, pentru exporturile de listă ([[ADR-019]]), rapoartele PDF și facturile către clienți ([[ADR-005]]).

Faza 5 construiește abonamentul Stripe: codul acelei faze **nu comută renderer-ul** — nu publică `config/cashier.php`, nu setează `CASHIER_INVOICE_RENDERER`, nu adaugă `->driver()` pe nimic legat de descărcarea facturii de abonament. Dacă apare vreodată nevoia unui download direct din cod propriu, el trece prin API-ul Cashier ca atare, nu prin `PdfExporter`.

## Consecințe

### Pozitive

- Zero cod, zero config publicat, zero variabilă de mediu nouă — descărcarea facturii de abonament funcționează identic pe orice mediu (dev, CI, producție), fără o valoare implicită de care depinde.
- Elimină exact riscul pe care Opțiunea 2 l-ar fi introdus: un eșec la rulare, dependent de mediu, pe calea de business care atinge banii tenantului.
- Consistent cu bugetul de memorie și cu absența Chromium/Node din imaginea de producție ([[ADR-019]]).
- Corectează public neconcordanța semnalată în [[ADR-019]], fără să rescrie [[ADR-006]] — respectă regula „un ADR acceptat nu se rescrie" (`.ai/rules/project.md`).

### Negative / trade-offs, asumate

- Nota „Unificarea generării de PDF" din `docs/adr/README.md` nu se închide pe direcția pe care o presupunea inițial (toate PDF-urile pe `spatie/laravel-pdf`) — se închide pe direcția opusă: rămân două puncte de intrare, prin decizie, nu prin uitare.
- Șablonul facturii de abonament (`Invoice::view()`, din Cashier) nu e sub același control de stil ca șabloanele proprii (`exports.pdf.list`, `reports.pdf.built-in`) — orice personalizare vizuală a facturii Stripe trece prin publicarea view-urilor Cashier, nu prin `PdfExporter`.
- Dacă o versiune viitoare de Cashier schimbă implicitul, sau dacă `LaravelPdfInvoiceRenderer` capătă un mod de a primi driverul explicit (nu doar din `env()`), Opțiunea 2 merită reevaluată — nu e respinsă definitiv, e respinsă **cât timp** alegerea ei ar depinde de o variabilă de mediu nesetată implicit.

## Legături

- [[ADR-006]] — decizia de bază (Cashier 16, `Billable` = tenantul, idempotența webhook-urilor), superseded parțial de acest ADR exclusiv pe fraza despre randarea PDF.
- [[ADR-005]] — facturile către clienți, flux separat, folosesc deja `spatie/laravel-pdf` cu driver explicit.
- [[ADR-013]] — apelurile/procesele grele nu în cererea HTTP; DomPDF rămâne PHP pur, fără proces extern, deci nu ridică aceeași problemă.
- [[ADR-019]] — unde a fost măsurat bugetul de memorie, unde a fost instalat `dompdf/dompdf`, și unde a fost semnalată prima dată neconcordanța cu [[ADR-006]].

## Istoric

- 2026-09-19 (Faza 5) — creat. Proprietarul a confirmat Opțiunea 1 dintre cele patru de mai sus, închizând neconcordanța semnalată în [[ADR-019]] la 2026-09-14.
