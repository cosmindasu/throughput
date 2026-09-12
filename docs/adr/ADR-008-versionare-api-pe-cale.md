# ADR-008: Versionarea API-ului public pe cale (`/api/v1/...`)

- **Status**: Accepted
- **Date**: 2026-09-12
- **Deciders**: Proprietar
- **Tags**: api, versionare, openapi, sprint-5

## Context și problema

API-ul public trebuie versionat. Două forme uzuale: pe cale (`/api/v1/orders`) sau prin negociere de conținut în antet (`Accept: application/vnd.throughput.v1+json`).

## Opțiuni considerate

### Opțiunea 1: Pe antet

- **Pro**: considerată mai „pură" — aceeași resursă păstrează același URI indiferent de versiune.
- **Contra**: invizibilă. Nu se poate testa deschizând o adresă în browser, e mai greu de depanat, iar consumatorii o greșesc frecvent (omit antetul și primesc versiunea implicită fără să observe).

### Opțiunea 2: Pe cale (ALEASĂ)

- **Pro**: vizibilă și verificabilă cu `curl` sau direct în browser; universal înțeleasă; se documentează natural în OpenAPI.
- **Contra**: obiecția teoretică privind identitatea resursei.

## Decizia luată

**Pe cale.**

Argumentul care decide e specific acestui proiect: e un **demo**. Un cumpărător care deschide `/api/v1/orders` și vede JSON valid formează o impresie pe loc. Aceeași persoană nu va construi o cerere cu antet de negociere de conținut ca să verifice dacă API-ul există.

Puritatea REST e un cost pe care îl plătește cineva care nu se uită. Vizibilitatea e un câștig la fiecare vizitator.

Specificația lucra deja pe această premisă (§18.3), deci nu sunt necesare modificări.

## Consecințe

### Pozitive

- Zero fricțiune la demonstrare; documentația OpenAPI e direct navigabilă.
- Rutarea Laravel o exprimă natural prin grupuri de prefix.

### Negative / trade-offs

- La o eventuală `v2`, rutele se dublează pe prefix. Acceptabil; e problema oricărui API cu adevărat versionat, și nu se pune în orizontul unui demo.
