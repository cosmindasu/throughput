# ADR-011: Dezactivarea unui membru nu e blocată de înregistrările pe care le deține

- **Status**: Accepted
- **Date**: 2026-09-12
- **Deciders**: Proprietar
- **Related**: [[ADR-007]] (jurnal de activitate — istoricul cere referințe intacte)
- **Tags**: multi-tenancy, securitate, rbac, sprint-2

## Context și problema

Când un membru pleacă dintr-un workspace, înregistrările pe care le deține — conturi, oportunități, comenzi — rămân în urmă. Întrebarea e ce se întâmplă cu ele și, mai ales, **dacă plecarea poate fi blocată până când cineva le preia**.

Versiunea v1.3 a specificației adoptase **reatribuirea obligatorie totală**: eliminarea era blocată până când toate cele 62 de înregistrări din exemplu erau reatribuite. Research-ul din aceeași zi a argumentat explicit împotriva acestei variante, iar argumentul e decisiv și nu fusese cântărit:

> **Blocarea dezactivării blochează revocarea accesului.**

Dacă cineva pleacă în conflict, accesul trebuie tăiat imediat. O politică ce cere mai întâi reatribuirea a mii de înregistrări istorice transformă o acțiune de securitate într-o sarcină de curățenie — și, în practică, întârzie revocarea cu zile.

## Drivers de decizie

- **Revocarea accesului nu se negociază cu ergonomia.** E singura acțiune din tot modulul care are consecințe de securitate imediate.
- **Nimic nu trebuie să dispară tăcut.** Un cont fără proprietar vizibil e un cont pe care nu-l mai vede nimeni.
- **Istoricul rămâne auditabil** ([[ADR-007]]) — deci referințele la utilizatorul plecat nu se pot rupe.

## Opțiuni considerate

Research-ul a comparat patru produse reale și a găsit **trei tipare distincte, fără consens**:

| Produs | Tipar |
|---|---|
| HubSpot | Placeholder, fără blocare — proprietarul devine `Deactivated/Removed (email)` |
| Salesforce | Reatribuire manuală înainte; nu automatizează nativ (e un *Idea* deschis pe portalul lor) |
| Jira | Coadă de neatribuite — `ASSIGNEE = NULL` e stare validă de sistem, nu eroare |
| Zoho CRM | Blocare cu pas obligatoriu de transfer |

## Decizia luată

**Hibrid, cu dezactivarea niciodată blocată definitiv.**

1. **Dezactivarea e imediată.** Accesul se revocă acum. `memberships.status = deactivated`, niciodată `DELETE` fizic — istoricul din `activity_log` cere referința intactă.
2. **Placeholder pe referințele istorice** — „Jane Doe (deactivated)", nu un nume gol și nu o eroare.
3. **Vedere „Unassigned" per tenant**, populată automat cu înregistrările **deschise** ale membrilor dezactivați. Managerul reatribuie de acolo, în ritmul lui. Nimic nu se pierde, nimic nu blochează.
4. **Confirmare suplimentară pentru subsetul critic** — comenzi active și oportunități deschise. Dezactivarea cere o confirmare explicită, cu opțiunea de a reatribui pe loc. **Owner-ul poate alege „Deactivate anyway"**, iar înregistrările trec în vederea „Unassigned".

Punctul 4 e compromisul: păstrează rigoarea vizibilă (ești avertizat că lași 12 oportunități deschise fără proprietar) fără să reintroducă blocajul pe care punctul 1 îl exclude.

**Excepție cu consens puternic: ultimul Owner.** Slack, ClickUp, Webflow și Figma blochează toate eliminarea ultimului Owner al unui workspace. Aici blocarea e corectă — nu există „mai târziu" pentru un workspace fără proprietar. Transferul de proprietate e o acțiune separată, precondiție.

**Out of scope, deliberat:** cazul în care ultimul Owner a plecat deja fără să transfere. Webflow îl documentează ca flux mediat de suport, nu self-service. Facem la fel.

## Consecințe

### Pozitive

- Revocarea accesului rămâne instantanee, indiferent de câte înregistrări deține cineva.
- Vederea „Unassigned" e o demonstrație mai bună decât blocarea: *„nimic nu se pierde când cineva pleacă din echipă"*.
- Un singur drum de cod pentru dezactivare, cu o confirmare deasupra — nu două politici paralele.

### Negative / trade-offs

- Înregistrările pot rămâne neatribuite la nesfârșit dacă nimeni nu se uită în vederea „Unassigned". Mitigare: notificare către Owner la dezactivare, plus un indicator numeric permanent pe vedere.
- Se pierde efectul demonstrativ al blocării ferme. Acceptat — vederea „Unassigned" e la fel de vizibilă și mai onestă operațional.

## Istoric

Această decizie **schimbă** ce scria specificația în v1.3 (§6.4.1, BR-TEN-03, US-TEN-03), unde reatribuirea totală era obligatorie și blocantă. Varianta aceea fusese introdusă la indicația mea, contrar recomandării explicite din `docs/research/best-practices-goluri-2026-09-12.md` §3.2. Specificația se aliniază la acest ADR în v1.6.
