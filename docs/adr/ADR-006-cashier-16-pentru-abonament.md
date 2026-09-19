# ADR-006: Laravel Cashier 16 pentru abonamentul tenantului

- **Status**: Accepted — **fraza despre randarea PDF-ului de factură e superseded parțial de [[ADR-021]]**
- **Date**: 2026-09-12
- **Deciders**: Proprietar
- **Related**: [[ADR-005]] (delimitarea fluxurilor de bani), [[ADR-021]] (randarea PDF-ului de factură de abonament — corecție)
- **Tags**: stripe, cashier, abonament, sprint-4

## Context și problema

Proiectul `travel` din același portofoliu a ales SDK-ul raw `stripe/stripe-php`, respingând Cashier. Întrebarea firească: se aplică același precedent și aici?

**Nu se aplică.** La `travel` erau plăți one-off pentru rezervări de tip guest, fără model `Billable` și fără abonamente — exact cazul în care Cashier aduce migrații și un webhook flow nefolosite. Throughput are abonamente recurente per organizație, adică fix cazul pentru care există Cashier.

## Decizia luată

**Laravel Cashier 16**, pe versiunea de API Stripe `2025-06-30.basil`.

Entitatea `Billable` e **tenantul (organizația), nu utilizatorul** — fiecare workspace are propriul Stripe Customer și propriul abonament, gestionat de rolul Owner. E recomandarea de research pentru SaaS B2B și e singura care are sens când mai mulți utilizatori împart un plan.

Generarea PDF-urilor de factură de abonament folosește `spatie/laravel-pdf`, nu `dompdf` — schimbarea introdusă în Cashier 16.

Idempotența webhook-urilor rămâne a noastră, prin tabelul `webhook_events` cu unicitate pe `event_id`: **Stripe garantează livrare at-least-once, niciodată exactly-once.**

## Consecințe

### Pozitive

- Portal de facturare, schimbare de plan și istoric vin din cutie.
- Abonamentul la nivel de organizație e modelul corect și pentru demonstrație, și pentru realitate.

### Negative / trade-offs

- Dependență de un pachet care urmează ritmul de versionare al Stripe; o schimbare majoră de API poate cere migrare.
- Două mecanisme de PDF în proiect dacă facturile către clienți (ADR-005) ajung să folosească altceva. De unificat pe `spatie/laravel-pdf`.
