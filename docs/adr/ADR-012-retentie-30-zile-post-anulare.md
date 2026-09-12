# ADR-012: Fereastră de retenție de 30 de zile după anularea abonamentului

- **Status**: Accepted
- **Date**: 2026-09-12
- **Deciders**: Proprietar
- **Related**: [[ADR-005]], [[ADR-006]] (abonamentul), [[ADR-007]] (audit)
- **Tags**: abonament, gdpr, retentie, stergere, sprint-5

## Context și problema

După ce abonamentul unui tenant e anulat definitiv — voluntar sau după eșecul repetat al plății — datele lui trebuie să dispară la un moment dat. Întrebarea e **când**.

Research-ul e explicit pe un punct care contează: **nu există un termen corect.** GDPR cere minimizare, nu un număr. Fiecare furnizor își stabilește politica contractual.

## Decizia luată

**30 de zile**, cu ștergere în doi timpi.

- La anulare: `subscription_canceled_at` setat, accesul blocat, datele intacte.
- În fereastră: tenantul **poate exporta** datele (§20.5) și **poate reactiva** abonamentul fără să reia onboarding-ul.
- La expirare: job programat de purjare.

Motivul alegerii lui 30 și nu 60 sau 90: e valoarea pe care converg cel mai frecvent implementările din industrie (research: interval observat 30–90, cu grupare pe 30 ca prag de recuperare „accidentală"). Pentru un demo contează că fereastra **există** și că în interiorul ei poți exporta și reactiva — nu lungimea ei.

**Se scrie explicit în specificație că e o decizie de produs, nu o cerință legală.** Un document care spune „30 de zile conform GDPR" arată că autorul n-a citit regulamentul.

## Ce nu se șterge la purjare

Facturile și înregistrările din jurnalul de audit legate de tranzacții financiare se **anonimizează**, nu se șterg — Art. 17(3)(b) exceptează datele necesare pentru obligații legale. Se rup legăturile către persoana fizică, se păstrează documentul și cifrele.

Termenul de retenție fiscală **variază pe jurisdicție**. Nu se fixează un număr în cod: e parametru configurabil per tenant. Research-ul refuză explicit să recomande o valoare universală, și pe bună dreptate — piața țintă e internațională.

## Consecințe

### Pozitive

- Un tenant care anulează din greșeală sau din cauza unui card expirat își poate recupera contul.
- Exportul rămâne posibil exact când e cel mai probabil să fie cerut.
- Distincția anonimizare/ștergere e scrisă, nu descoperită la prima cerere de ștergere.

### Negative / trade-offs

- Datele ocupă spațiu 30 de zile după ce tenantul a plecat. Irelevant la scara unui demo.
- Cifra e arbitrară prin natura ei. Documentată ca atare — se schimbă printr-un ADR nou, nu printr-o editare tăcută.
