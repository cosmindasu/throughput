# ADR-002: Multi-tenancy pe cale (workspace slug), nu pe subdomeniu

- **Status**: Accepted
- **Date**: 2026-09-12
- **Deciders**: Tech Lead
- **Related**: [[ADR-003]] (mecanismul de izolare), [[ADR-001]]
- **Tags**: multi-tenancy, dns, tls, routing, sprint-1

## Context și problema

Throughput e multi-tenant: mai multe organizații, date complet izolate, comutator de spațiu de lucru în interfață. Rămâne de ales **cum se identifică tenantul în URL**: subdomeniu (`acme.throughput.dbg.ro`) sau cale (`throughput.dbg.ro/acme`).

Decizia trebuie luată înainte de primul deploy, pentru că determină înregistrările DNS și forma certificatului TLS.

Verificare făcută pe DNS-ul real (2026-09-12): domeniul `dbg.ro` are un wildcard `*.dbg.ro` care trimite spre `86.35.3.192/193` (găzduire partajată), iar cele 11 proiecte îl suprascriu cu înregistrări A explicite spre VPS. Wildcard-ul rezolvă **și pe două niveluri** — `acme.throughput.dbg.ro` întoarce deja IP-ul de parking.

## Drivers de decizie

- **Fiabilitatea certificatului** — e un demo de portofoliu; un certificat expirat exact când se uită un client potențial e cel mai prost mod de a pierde un contract.
- **Efort de operare** — orice pas manual recurent se va rata într-o zi.
- **Valoare demonstrativă** — ce anume convinge un cumpărător că aplicația e cu adevărat multi-tenant.

## Opțiuni considerate

### Opțiunea 1: Tenancy pe subdomeniu

- **Pro**: percepția de „SaaS adevărat"; izolare vizuală clară între organizații; precedent Slack, Freshdesk.
- **Contra**: cere înregistrare A explicită `*.throughput.dbg.ro` (wildcard-ul existent duce spre parking) **și** certificat TLS wildcard. Wildcard-urile Let's Encrypt se emit exclusiv prin provocare DNS-01, nu HTTP-01. DNS-ul e la Romarg; fără API pentru automatizare, înseamnă reînnoire manuală la fiecare 90 de zile.

### Opțiunea 2: Tenancy pe cale, cu comutator de spațiu de lucru (ALEASĂ)

- **Pro**: certificat normal, emis și reînnoit automat ca la celelalte 11 proiecte. Zero pași manuali recurenți. Precedent puternic: Linear, Notion, Vercel, Height.
- **Contra**: URL-ul nu mai poartă identitatea organizației; ceva mai puțin „SaaS" la prima privire.

## Decizia luată

**Aleasă: Opțiunea 2 — tenancy pe cale.**

Tenantul se rezolvă din segmentul de cale (`/{workspace}/...`) după autentificare, cu un comutator de spațiu de lucru în interfață. Un singur certificat, pe `throughput.dbg.ro`.

Raționamentul de fond: **valoarea demonstrativă nu vine din forma URL-ului, ci din ce vede cumpărătorul** — comuți spațiul de lucru și toate datele se schimbă, cu izolarea impusă în bază ([[ADR-003]]). Aia e demonstrația; URL-ul e decor.

Dacă un client cere explicit tenancy pe subdomeniu, se adaugă **un singur** subdomeniu de probă, cu înregistrare și certificat proprii, ca să demonstreze mecanismul — fără wildcard.

## Consecințe

### Pozitive

- Emitere și reînnoire TLS complet automate, identic cu restul portofoliului.
- Un singur A record de întreținut: `throughput` → VPS.
- Nicio dependență de capacitățile API ale DNS-ului Romarg.

### Negative / trade-offs

- Rutele poartă un segment în plus; `route()` și link-urile din Inertia trebuie să îl propage consecvent. Se rezolvă cu un helper central plus un default în `URL::defaults()`, stabilit în Sprint 1.
- Dacă produsul ar deveni vreodată real, trecerea la subdomenii ar cere redirecționări. Acceptat: e un demo.
