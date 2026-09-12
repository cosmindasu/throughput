# ADR-005: Facturarea către clienți e separată de abonamentul Stripe

- **Status**: Accepted
- **Date**: 2026-09-12
- **Deciders**: Proprietar
- **Related**: [[ADR-006]] (Cashier pentru abonament)
- **Tags**: facturare, plati, domeniu, sprint-4

## Context și problema

„Facturare" înseamnă două lucruri diferite în Throughput, iar amestecarea lor ar fi mai simplu de construit:

1. Facturile pe care **tenantul le emite clienților lui** — creanțe pe termene de credit.
2. Abonamentul pe care **tenantul îl plătește către Throughput**.

Tentația evidentă e „Stripe peste tot": checkout cu cardul și pentru facturile către clienți. Ar reduce codul și ar reutiliza aceeași integrare.

## Decizia luată

**Cele două fluxuri rămân separate.** Facturile către clienți sunt creanțe interne (`credit_terms`, `due_date`, `balance_due`), fără procesator de card, reconciliate manual. Stripe apare exclusiv pentru abonamentul tenantului.

Motivul de domeniu: un distribuitor en-gros nu încasează cu cardul la fiecare comandă — lucrează pe net 30 și încasează prin transfer. Un demo care sugerează altceva arată că autorul n-a lucrat niciodată în domeniu.

Motivul de portofoliu, la fel de important: **fiecare portofoliu de pe platformele de freelancing are un checkout cu Stripe.** Aproape niciunul nu arată înțelegerea termenelor de credit. Semnalul rar e al doilea.

## Consecințe

### Pozitive

- Demo-ul demonstrează înțelegerea domeniului, nu doar integrarea unui SDK.
- Modelul `invoices` rămâne curat: fără stări de procesator amestecate cu stări de creanță.

### Negative / trade-offs

- Demo-ul **nu** va conține un ecran „clientul plătește cu cardul". Asumat conștient.
- Plata cu cardul a facturilor către clienți (Payment Link) rămâne listată ca extensie de Fază 2 în §3.3 — se poate adăuga fără a schimba modelul.
