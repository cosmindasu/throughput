# ADR-022: Interfața devine bilingvă (EN implicit + FR) — limba e o preferință per utilizator, nu un segment de URL

- **Status**: Accepted
- **Data**: 2026-09-20
- **Decidenți**: proprietarul proiectului
- **Amendează**: [[ADR-002]] — adaugă o notă care exclude explicit segmentul de limbă din URL, ca dezbaterea despre forma URL-ului să nu se redeschidă fără context. [[ADR-002]] rămâne Accepted, nesuperseded.
- **Related**: [[ADR-013]], [[ADR-014]] (regula de serializare a contextului în joburi — `tenantId` scalar în constructor, restaurat la începutul lui `handle()`; `locale` urmează același tipar)
- **Tags**: i18n, frontend, backend, locale, cozi, e2e, portofoliu

## Context și problema

`specs_si_design/README.md:7` afirmă explicit: „Piața e exclusiv internațională. Interfața e în engleză." `specs_si_design/specs.md:9` repeta aceeași premisă **până la v1.21**, motivată prin piața țintă: „construită ca piesă de portofoliu pentru cumpărători tehnici de pe platforme internaționale de freelancing... Interfața aplicației e în engleză... Piața țintă e exclusiv internațională: nu există e-Factura ANAF, TVA românesc sau Netopia în acest produs, deliberat." (Linia a fost rescrisă în v1.22, ca urmare a acestui ADR — citatul de mai sus e premisa înlocuită, nu textul curent.)

Acest ADR **schimbă** premisa de mai sus, nu o completează. De acum, aplicația e bilingvă: **engleză (implicit) + franceză.**

**Motivul real, scris ca atare, nu dedus:** demonstrație de competență i18n pentru cumpărători tehnici — motor de pluralizare CLDR, rezoluție de limbă corectă sub RLS și în joburi de coadă, catalog de traduceri complet (UI, validări, email, PDF, export, panou de ajutor). **Nu** e o piață francofonă țintită. Proiectul rămâne exact ce era: un demo B2B internațional, fără e-Factura, fără TVA românesc, fără Netopia — nimic din premisa de piață din `specs_si_design/README.md:7`/`specs.md:9` nu se schimbă în afară de numărul de limbi ale interfeței. A inventa o justificare de piață francofonă ar fi o afirmație falsă într-o piesă de portofoliu care se vinde tocmai pe onestitatea deciziilor documentate.

**Momentul livrării:** un lot dedicat, **după Faza 5**, peste o suprafață de ecrane înghețată. Motivul: extragerea string-urilor din UI, validări, email și PDF se face o singură dată — peste o suprafață care include deja ecranele Faza 5 (abonament, facturare) — cu un singur proprietar pe catalogul de traduceri. A face i18n în paralel cu fazele care încă adaugă ecrane ar fi însemnat fie extragere repetată, fie un catalog mereu în urma codului.

## Drivers de decizie

- **Valoare demonstrativă, nu cerință de piață.** Semnal de competență full-stack i18n pentru cumpărători tehnici (Upwork), nu localizare pentru un segment de clienți reali.
- **Zero mecanism nou unde unul deja funcționează.** `users.theme` + `ThemePreference` (`app/Support/ThemePreference.php`) e deja pattern-ul verificat de rezoluție a unei preferințe per utilizator, cu cookie de fallback și randare server-side fără licărire. Limba refolosește exact acest tipar, nu inventează unul paralel.
- **URL-ul e deja decor, prin decizie anterioară.** [[ADR-002]] a stabilit asta explicit pentru workspace; extinderea firească e ca nici limba să nu intre în URL.
- **Regula de serializare a contextului în joburi e deja scrisă** ([[ADR-013]], [[ADR-014]], `.ai/rules/tenancy.md:123-138`) — `locale` o respectă, nu o redeschide.
- **Cost conținut, nu recurent.** Un singur lot, peste o suprafață înghețată, cu suita E2E existentă neatinsă (fixată pe `en`) și un subset FR nou, rulat doar pe push la `main`.
- **Motorul de pluralizare nu se reinventă.** Proiectul are deja **trei** tipare de pluralizare hardcodată, independente, găsite la scrierea acestui ADR: `resources/js/Components/BulkSelectionBar.tsx:107` (`effectiveCount === 1 ? resourceNounSingular : resourceNounPlural`), `resources/js/Components/BulkSelectionBar.tsx:321` (`effectiveCount === 1 ? 'draft order' : 'draft orders'`) și `resources/js/Components/GlobalSearch.tsx:267` (`` `${flatResults.length} result${flatResults.length === 1 ? '' : 's'}` ``). Trei implementări independente ale aceluiași `=== 1 ? singular : plural` sunt exact semnalul că lipsește un motor, nu o bibliotecă de string-uri.

## Opțiuni considerate

### 1. Mecanismul de selecție a limbii

**Opțiunea A (ALEASĂ): coloană `users.locale`, pe modelul exact al lui `users.theme`.**

Clasă `LocalePreference`, replică forma lui `ThemePreference` (`app/Support/ThemePreference.php`): ordine de rezoluție explicită — alegerea userului autentificat > cookie `locale` > implicit `en` — `App::setLocale()` apelat dintr-un middleware devreme, prop `locale` propagat prin `HandleInertiaRequests::share()` (unde azi se propagă deja `theme`, linia 68), și `<html lang="...">` randat server-side în `resources/views/app.blade.php` **înainte** de orice JS (azi, linia 26, `lang` e hardcodat `"en"` — devine dinamic, ca `class="{{ $theme === 'dark' ? ... }}"` de pe aceeași linie). Aceeași tehnică evită aceeași licărire pe care `ThemePreference` o evită azi pentru temă.

`users` e identitate globală, fără `tenant_id`/RLS ([[ADR-014]]) — preferința de limbă persistă la comutarea workspace-ului, la fel ca tema, fără nimic suplimentar de scris.

- **Pro**: zero mecanism nou de proiectat sau verificat — cel existent e deja testat, deja documentat, deja fără licărire. Onboarding zero pentru cine citește codul: cine a înțeles `theme` a înțeles și `locale`.
- **Contra**: niciunul specific mecanismului; contra-urile reale sunt cele discutate mai jos, la consecințe (joburi, E2E).

**Opțiunea B (RESPINSĂ): segment de limbă în URL (`/fr/{workspace}/...`).**

- **Contra, decisiv**: tensiune directă cu [[ADR-002]], care argumentează explicit că „URL-ul e decor" — valoarea demonstrativă vine din ce vede cumpărătorul la comutare, nu din forma căii. A pune limba în URL contrazice acel raționament fără motiv nou.
- **Contra**: `ResolveWorkspace` scoate deja `{workspace}` din parametrii rutei cu `forgetParameter` (`.ai/rules/tenancy.md:35-38`), tocmai pentru că dispatcher-ul Laravel pasează parametrii pozițional și un parametru tipizat necurățat dă 500. Un segment `locale` suplimentar ar cere același `forgetParameter` dublat peste tot unde `ResolveWorkspace` deja îl aplică pentru workspace — același risc de 500 pe controllere cu parametri tipizați, de data asta pe două segmente în loc de unul.
- **Contra**: ar cere extinderea `URL::defaults()` (deja folosit pentru workspace, [[ADR-002]]) pe **toate** generările server-side de URL, ca `route()` să nu piardă segmentul de limbă la fiecare link — al doilea loc unde regula „un segment în plus în cale = un loc în plus de propagat" s-ar aplica identic cu cea deja documentată pentru workspace.

### 2. Motorul de traducere pe frontend

**Opțiunea A (ALEASĂ): `react-i18next`, cataloage JSON în `resources/js/locales/`.**

- **Pro**: motor de pluralizare CLDR gata făcut — vezi cele trei tipare hardcodate de mai sus, care dispar prin adoptarea lui. ~15-20 KB gzip. Aplicația nu are SSR și e `noindex` — constrângerea de hidratare care ar face alegerea sensibilă pe alt proiect nu există aici.
- **Contra**: o dependență JS în plus, un catalog de întreținut. Acceptat — e exact suprafața pe care lotul de i18n o adaugă prin definiție. (Lotul e o secțiune dedicată **între** Faza 5 și Faza 6, nu Faza 6 însăși — aceea rămâne „Prezentare", fără cod nou de business.)

**Opțiunea B (RESPINSĂ): `@lingui/react`.**

- **Contra, decisiv**: pas de build separat de Vite; macro-uri care cer rescriere JSX pentru fiecare string existent. Cost de migrare mai mare pentru un câștig echivalent cu Opțiunea A.

**Opțiunea C (RESPINSĂ): traduceri ca prop Inertia, servite din `lang/`.**

- **Contra, decisiv**: reinventează motorul de pluralizare CLDR pe cod propriu, fără niciun câștig față de o bibliotecă matură — exact tiparul „trei implementări independente ale `=== 1 ? singular : plural`" pe care Opțiunea A îl elimină.

### 3. Backend

`lang/en/*.php` + `lang/fr/*.php`, nativ Laravel. Nicio opțiune alternativă discutată — e alegerea idiomatică, fără dependență nouă, consecventă cu „Do Things the Laravel Way".

## Decizia luată

**Opțiunea A la fiecare din cele trei puncte de mai sus.**

### Ce se traduce

UI, validări, email, PDF, exporturi, panoul de ajutor (30 de subiecte, ~13.900 de cuvinte) și datele demo (pool de nume FR în `database/seeders/Support/DemoNames.php`).

### Ce NU se traduce

Conținutul introdus de utilizator (`saved_views.name`, `report_definitions.name`) — rămâne în limba în care a fost scris. Nu există „traducere automată" a datelor de business; ar fi o afirmație falsă despre ce face aplicația.

### Import CSV

Maparea rămâne pe cheie stabilă — `ImportRowMapper` (`app/Support/Imports/ImportRowMapper.php`) neatins. Se adaugă aliasuri FR pe `ImportField` (`app/Support/Imports/ImportField.php`), altfel auto-sugestia coloanelor cade la reimportul unui export produs în franceză de aceeași aplicație.

## Consecințe

### Pozitive

- Refolosește un mecanism deja verificat (`ThemePreference`) în loc să inventeze unul nou — risc de proiectare redus la minimum.
- Motorul CLDR elimină cele trei tipare de pluralizare hardcodată găsite la scrierea acestui ADR.
- Un singur lot, peste o suprafață înghețată — extragerea de string-uri nu se repetă la fiecare fază ulterioară.
- Costul de CI rămâne conținut: suita E2E existentă (65 de teste) rămâne fixată pe `en`; un subset FR nou rulează doar pe push la `main`, refolosind mecanismul `@smoke` deja existent (`.github/workflows/ci.yml:308`), fără cost suplimentar pe fiecare PR.
- Backend nativ Laravel — zero dependență nouă pe partea de server.

### Negative / trade-offs, asumate explicit

1. **Joburile de coadă primesc `locale` ca scalar în constructor**, exact cum [[ADR-013]]/[[ADR-014]] impun pentru `tenantId`, plus `App::setLocale($this->locale)` la începutul lui `handle()`. Motivul concret: worker-ul de coadă e un proces de viață lungă; `App::setLocale()` scrie pe singleton-ul `Translator` din container, iar Laravel resetează între joburi doar instanțele `scoped()` — un job FR urmat de unul EN, pe același worker, scurge limba primului către al doilea. E aceeași clasă de bug pe care `.ai/rules/tenancy.md:123-138` o documentează deja pentru memoizarea per cerere sub un worker cu viață lungă, aplicată acum limbii, nu tenantului. **Pentru joburile din domeniul facturare/curierat** (Faza 5, per scope-ul deja planificat în `plan-implementare.md` §11), asta aterizează ca **fix punctual** peste ele când vine lotul de i18n — nu ca rescriere — cu condiția ca tiparul de mai sus să fie cunoscut din timp de cine le scrie, indiferent de momentul la care sunt scrise.
2. **`e2e/setup/auth.setup.ts:24`** caută butonul de login demo după text literal (`` `Log in as ${DEMO_ROLE_LABELS[role]}` ``) și produce `storageState`-ul citit de toate cele 65 de teste din suită. E un punct unic de eșec: dacă acel buton devine vreodată tradus condiționat de limbă, pică toată suita la pasul de autentificare, nu la vreo aserțiune de business. Cuplarea text-literal ↔ setup e **deliberată** (vezi comentariul din `e2e/support/auth.ts:12-17`: „Text literal, nu derivat: dacă eticheta din backend se schimbă, testul de setup trebuie să pice, nu să tacă.") — deci consecința se scrie aici, nu se presupune. Mitigare: suita `en` existentă rămâne pe login în engleză; subsetul FR nou are propriul `storageState`, cu propriul text de buton, nu împrumută fișierul `en`.
3. **`User` trebuie să implementeze `Illuminate\Notifications\HasLocalePreference`**, altfel `users.locale` nu produce niciun efect automat pe notificări (email-uri trimise prin `Notifiable` ignoră preferința fără acest contract explicit).

### Cost, scris onest

Ordinul de mărime e de **câteva sute de ore**, defalcat pe valuri în `plan-implementare.md`, secțiunea „Lot I18N" — acolo e sursa unică pentru cifre de efort, ca să nu existe două totaluri care se contrazic. Traducerea franceză e scrisă de asistent și revizuită de proprietar — risc de calitate asumat pe o piesă de portofoliu, mitigat prin revizie țintită pe pasajele marcate plus un test automat de acoperire care blochează CI la o cheie de traducere lipsă (catalog EN/FR incomplet = build roșu, nu string neobservat în producție).

## Legături

- [[ADR-002]] — amendat de acest ADR: notă adăugată care exclude explicit segmentul de limbă din URL, ca argumentul „URL-ul e decor" să nu fie redeschis fără context de fiecare dată.
- [[ADR-013]] — apelurile externe ies din cererea HTTP, în cozi; regula de bază pentru context serializat pe job.
- [[ADR-014]] — familiile de joburi (tenant / sistem) și regula „context serializat, restaurat la începutul lui `handle()`" pe care `locale` o respectă identic.
- `app/Support/ThemePreference.php` — mecanismul replicat pentru `LocalePreference`.
- `.ai/rules/tenancy.md:35-38` (regula `forgetParameter` pe `ResolveWorkspace`), `.ai/rules/tenancy.md:123-138` (memoizarea pe worker cu viață lungă) — ambele citate ca motiv de respingere/atenție, neatinse.
- `specs_si_design/README.md:7`, `specs_si_design/specs.md:9` — premisa „interfața e în engleză", schimbată de acest ADR.
- `e2e/setup/auth.setup.ts:24`, `e2e/support/auth.ts:12-17` — cuplarea text-literal la autentificarea E2E.
- `.github/workflows/ci.yml:308` — mecanismul `@smoke` refolosit pentru subsetul FR.
