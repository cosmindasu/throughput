# ADR-009: Resend ca furnizor de email tranzacțional

- **Status**: Accepted
- **Date**: 2026-09-12
- **Deciders**: Proprietar
- **Related**: §22.3 din specificație (interceptarea email-urilor în demo)
- **Tags**: email, rapoarte, notificari, sprint-4

## Context și problema

Aplicația trimite email: rapoarte programate cu atașament, invitații în workspace, notificări, resetare de parolă. Îi trebuie un furnizor.

Nota din specificație (§16) formula problema ca „coadă proprie vs serviciu extern" pentru livrarea rapoartelor. Formularea era înșelătoare: **nu există un serviciu extern rezonabil** pentru „rulează interogarea asta luni la 8 și trimite-mi CSV-ul", iar research-ul confirmă că nu există un pachet Laravel canonic pentru programarea rapoartelor.

Mecanismul e deci decis de la sine — Laravel Scheduler → job → `Mailable` cu atașament. Întrebarea reală, ascunsă sub ea, era **cine transportă email-ul**.

## Decizia luată

**Resend.**

- Configurare minimă și API modern; driver Laravel disponibil.
- Nivel gratuit suficient pentru volumul unui demo (unde, în plus, email-urile către adrese din afara listei albe sunt oricum interceptate — §22.3).
- Alternativa serioasă e Postmark, cu reputație de livrare mai bună pentru volume reale. Nerelevant aici: volumul e neglijabil, iar destinatarii sunt controlați.

**De verificat înainte de implementare:** termenii nivelului gratuit din 2026. Decizia se ia pe baza caracteristicilor de configurare, nu pe cifre de preț pe care nu le-am confirmat.

## Consecințe

### Pozitive

- Mecanismul de rapoarte rămâne integral în stack, fără dependență SaaS pentru programare — important pe un VPS cu buget de memorie strâns.
- Schimbarea furnizorului e o variabilă de mediu: Laravel abstractizează transportul.

### Negative / trade-offs

- Încă un cont extern și încă un set de chei de gestionat.
- Dacă volumul ar crește vreodată real, reputația de livrare ar trebui reevaluată. Nu se pune pentru un demo.
