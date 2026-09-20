# Frontend — tokens, teme, props

## Niciun cod de culoare literal în componente

Culorile vin **exclusiv** din tokens (`resources/css/app.css`, expuse prin `@theme`). Dacă un
ecran are nevoie de o nuanță care nu e token, se adaugă **tokenul**, nu excepția.

Valorile și auditul de contrast măsurat sunt în `specs_si_design/urls.md`. Paleta e direcția
**B — „Workshop"**: cerneală albastră, accent cyan, IBM Plex Sans + IBM Plex Mono.

## Două teme, ambele definite complet

Tema închisă e **implicită**, dar nu singura. Strategia e **pe clasă**
(`@custom-variant dark`), nu media query, fiindcă utilizatorul poate suprascrie preferința
sistemului. Ambele seturi de tokens se declară integral — tema deschisă **nu** e o inversare a
celei închise: accentul ca text coboară de la `#56D4E0` la `#06697A`, fiindcă `#56D4E0` pe alb
dă 1,6:1.

Clasa de temă se randează **server-side din cookie**, la primul răspuns. Un `useEffect` care
aplică tema după hidratare produce licărirea pe care FR-PREF-03 o interzice explicit.

## Accentul are două trepte

`--accent-text` pentru text și iconuri, `--accent-fill` pentru umplere. Nu sunt
interschimbabile: umplerea ține etichetă albă dar ar picat ca text pe fundal, iar varianta de
text n-ar ține eticheta. Hover-ul pe umplere e **mai închis**, deci contrastul doar crește la
interacțiune.

## Tentele de chip sunt tokens fixe

Nu `color-mix()` peste suprafața curentă. Recalculate per context, contrastul unui chip se
schimbă cu fundalul pe care e pus — bug găsit la audit: chip-ul de eroare cobora de la 4,74:1
la 4,17:1 când tenta se amesteca peste `--raised`.

## Cifre tabulare

`font-variant-numeric: tabular-nums` pe orice coloană de sume sau cantități. Cifrele sunt pe
mono (IBM Plex Mono), care **ocupă mai mult** la aceeași dimensiune — verifică lățimea
coloanelor pe cel mai lung total din seed, nu pe un exemplu scurt.

## Numele accesibil al unui `<table>`

Găsit la audit: trei convenții diferite pe tabelele de listă (`<caption>`, `aria-labelledby`,
nimic). Regula, aplicată pe toate listele existente:

- **Implicit, `<caption className="sr-only">`** cu numele entității (`Accounts`, `Orders`,
  `Deals`…). E mecanismul nativ (tehnica WCAG H39), nu depinde de un `id` extern care se poate
  rupe tăcut dacă cineva redenumește sau mută un heading — auto-conținut în tabel.
- **Excepție**: când o pagină are MAI MULTE tabele, fiecare cu propriul heading vizibil chiar
  deasupra (nu titlul paginii — un `h2`/`h3` DEDICAT acelui tabel, ca în `Unassigned/Index.tsx`:
  „Open deals (N)" / „Active orders (N)"), tabelul se leagă cu `aria-labelledby` la headingul
  respectiv, în loc de un `<caption>` separat. Altfel numele ar exista în două locuri
  (headingul vizibil + caption invizibil) care pot ajunge să diveargă în timp — `aria-labelledby`
  refolosește textul deja vizibil, o singură sursă de adevăr.
- Un tabel simplu, singur pe pagină, sub filtre (fără heading propriu — titlul de mai sus e al
  PAGINII, nu al tabelului) intră mereu pe cazul implicit: caption.

Notă (nu o obligație WCAG): un `<table>` fără nume NU e, prin el însuși, o încălcare a SC 1.3.1
sau 4.1.2 — ambele privesc relația header/celulă (`<th scope>`), nu un nume al tabelului
însuși. Caption-ul e totuși valoare reală de orientare (navigare pe tabele cu cititorul de
ecran) și cost aproape zero — de-asta regula de mai sus se aplică oricum, dar nu o raporta
ca „am reparat o încălcare AA": e o îmbunătățire de calitate, nu un blocaj de conformitate.

## Props Inertia

- Niciun model sau collection Eloquent trimis direct în `Inertia::render()` — doar
  `Resource`-uri. Contractul de props e explicit.
- Fiecare pagină primește `can: Record<string, boolean>` calculat server-side. Orice element
  care declanșează o acțiune e condiționat de `can`-ul lui — un buton care duce la 403 se
  citește ca „aplicație stricată", nu ca „aplicație securizată".
- `can.export` e **separat** de `can.bulkWrite`: exportul e o citire, permisă și Viewer-ului.
- Props comune (`auth`, `workspace`, `workspaces`, `flash`, `demoMode`, `theme`) vin dintr-un
  singur loc, `HandleInertiaRequests::share()`. Niciun controller nu le repetă.

## Focusul nu se pierde niciodată pe `<body>`

Trei forme ale aceluiași defect, găsite la auditul de accesibilitate al Fazei 3 (selectorul de
coloane, dialogul de dezactivare a unui membru). Toate trec testele funcționale și lasă
utilizatorul de tastatură „în gol", la începutul paginii:

- **`disabled` pe un buton care are focus.** Browserul blurează elementul, iar focusul cade pe
  `<body>`, chiar și într-un `<dialog>` modal. Butoanele care se blochează în timpul unei cereri
  sau la capătul unei liste (Move up/down) folosesc `aria-disabled="true"` plus un handler care
  nu face nimic, cu starea spusă în text („Deactivating…"). `disabled` nativ rămâne doar pe
  controale care nu pot avea focus în momentul schimbării.
- **Dialogul închis și la eroare.** `onFinish` rulează și la 422. Dialogul se închide în
  `onSuccess`, iar erorile se leagă de câmp prin `Form/Field` (`aria-describedby`,
  `aria-invalid`) sau, fără câmp, într-un `role="alert"` în dialog. `FlashMessages` nu afișează
  `errors`.
- **Declanșatorul dispare după succes** (rândul își schimbă starea, bara de selecție se golește).
  `<dialog>` nu mai are unde să readucă focusul. Focusul se mută explicit pe un element stabil,
  de obicei un mesaj `role="status"` cu `tabIndex={-1}` care spune ce s-a întâmplat.

Un nume accesibil repetat pe fiecare rând („Deactivate member" × N) se disambiguizează
**adăugând** discriminatorul, nu înlocuind numele:

```tsx
// Corect — numele accesibil devine „Edit Acme Corp", dar ÎNCEPE cu textul vizibil.
<Link href={…}>Edit<span className="sr-only"> {account.name}</span></Link>

// Greșit — `aria-label` ÎNLOCUIEȘTE textul vizibil.
<Link href={…} aria-label={`Edit ${account.name}`}>Edit</Link>
```

Motivul e **SC 2.5.3 Label in Name**: cine dictează „click Edit" unui software de control vocal
are nevoie ca textul vizibil să fie un prefix al numelui accesibil — `aria-label` îl rupe.

**Consecința asupra testelor E2E, în ambele direcții** (măsurată în valul 3 al Fazei 5, nu
dedusă). `getByRole` din Playwright caută **substring**, deci:

- un selector pe eticheta scurtă (`name: 'Edit'`) continuă să se potrivească, și devine
  disambiguabil per rând (`name: 'Edit Acme Corp'`);
- dar un selector pe **numele entității** (`name: 'Acme Corp'`), scris pentru linkul de rând, se
  potrivește de acum **și** cu `Edit Acme Corp` → încălcare de strict mode.

Deci: orice test care caută un rând după numele entității are nevoie de `{ exact: true }`. Șase
aserțiuni au fost ajustate exact pentru asta. A crede că adăugarea unui sufix e inofensivă
pentru selectoare e adevărat doar pe jumătate.

Convenția a fost amestecată până în valul 3 al Fazei 5 (`Settings/Index` și `Settings/Shipping`
încă folosesc `aria-label`); forma de mai sus e cea care se aplică de acum.

O regiune `aria-live` peste o listă cu polling anunță doar schimbările de stare, nu fiecare poll.

## `usePoll` are nevoie de `start()`, nu doar de `autoStart`

`usePoll` pornește polling-ul într-un `useEffect` cu dependențe **goale** (citit direct în sursa
`@inertiajs/react`). `autoStart` se evaluează **o singură dată, la montare**. O condiție care
devine adevărată mai târziu nu repornește nimic:

```tsx
// GREȘIT — dacă la montare condiția e falsă, polling-ul rămâne oprit PERMANENT.
const { stop } = usePoll(3000, {}, { autoStart: inProgress });
useEffect(() => { if (! inProgress) { stop(); } }, [inProgress, stop]);

// CORECT — ramura `start()` e obligatorie, simetric cu `stop()`.
const { start, stop } = usePoll(3000, {}, { autoStart: inProgress });
useEffect(() => { inProgress ? start() : stop(); }, [inProgress, start, stop]);
```

Tiparul greșit e periculos fiindcă **arată** complet: are `useEffect`, are dependențe corecte,
are `stop()`. Îi lipsește doar ramura cealaltă, iar defectul apare doar în fluxul real — cel în
care utilizatorul declanșează acțiunea pe o pagină **deja deschisă**, nu o reîncarcă după.

Găsit de două ori: prima dată de E2E-urile Fazei 3 (eticheta de curierat rămânea pe
„Generating label…" la nesfârșit, deși serverul o cumpărase), a doua oară la review-ul Fazei 4
(„Run now" pe un raport nu actualiza niciodată pagina, deși mesajul flash promitea exact asta).
A doua oară, tiparul corect exista deja în `ShipmentsSection.tsx`, cu comentariu explicativ, la
trei fișiere distanță. De aici regula, nu un al treilea comentariu.

**Corolar pentru orice ecran cu polling:** verifică fluxul în care starea urmărită devine
adevărată **după** montare. Dacă răspunsul serverului e un redirect către **aceeași** rută,
componenta nu se remontează, deci `autoStart` nu se reevaluează niciodată.

## Componenta de pagină se remontează la fiecare navigare — `key: Date.now()`

Defectul opus celui de mai sus, găsit la auditul de accesibilitate (lotul L1): o regiune
`aria-live` pusă ÎNTR-O COMPONENTĂ DE PAGINĂ (`CursorPagination.tsx`, ca să anunțe „Results
updated." la Next/Previous) nu anunța NICIODATĂ nimic, deși `tsc`/`eslint` treceau curat și
markup-ul (`role="status"`) exista în DOM.

Motivul, verificat direct în sursă, nu dedus — `node_modules/@inertiajs/react/dist/index.js`:

```
// swapComponent (linia ~127)
key: preserveState ? current2.key : Date.now()

// renderChildren (linia ~154)
const child = createElement(Component, { key, ...props });
```

Componenta de pagină (`child`) primește o `key` **nouă la fiecare navigare** ori de câte ori
`preserveState` nu e explicit `true` — adică implicit pe orice `<Link>`/`<ButtonLink>` obișnuit
(inclusiv Next/Previous). O `key` nouă = React vede alt element = **demontează și remontează**
componenta de pagină, indiferent cât de banală pare navigarea (aceeași rută, doar `?cursor=`
schimbat). Orice `useState`/`useRef` local moare și renaște înainte ca vreun efect să apuce să
observe o schimbare reală.

Layout-ul (`Component.layout`, ex. `AppLayout`) e wrapper-ul aplicat SEPARAT, în jurul lui
`child`, **fără** `key` proprie — deci React îl păstrează (poziție + tip neschimbate). **Doar
layout-ul e persistent peste o navigare Inertia, niciodată componenta de pagină.**

Consecințe directe:
- **Orice regiune `aria-live` care trebuie să supraviețuiască unei navigări stă în layout-ul
  persistent** (ex. `ListUpdateAnnouncer.tsx`, montat o singură dată în `AppLayout`), niciodată
  într-o componentă de pagină — sursa de adevăr pentru „ce s-a schimbat" e `usePage().url` (prin
  context, actualizat pe loc) sau evenimentele router-ului (`router.on('navigate', …)`),
  niciodată starea locală a unei componente care tocmai s-a remontat.
- Mai general: **nicio stare de pagină nu supraviețuiește unei navigări** — un `useState` care
  „ține minte" ceva peste un clic pe un link e, cel mai probabil, o presupunere greșită. Dacă
  ceva TREBUIE să supraviețuiască (un draft de formular, o selecție), el stă fie în layout, fie
  în URL/sessionStorage, niciodată doar în `useState`-ul paginii.
- **`preserveState: true`** (ex. `useListFilters`, deja documentat în `useBulkSelection`) e
  EXCEPȚIA care ține pagina montată — verifică explicit dacă o navigare o folosește înainte să
  presupui oricare din cele două comportamente.

## O regiune `aria-live` nu reacționează la `setState`, ci la mutația DOM-ului

A doua jumătate a aceleiași capcane, găsită imediat după prima, pe același anunț de paginare.
Regiunea mutată corect în layout-ul persistent tot nu anunța decât **prima** schimbare din
sesiune:

```tsx
// GREȘIT — al doilea „Next" scrie ACELAȘI șir.
setAnnouncement('Results updated.');
```

React iese din `setState` la valoare identică, **înainte** de a programa randarea —
`react-dom-client.development.js` (react-dom 19.3.0), în `dispatchSetStateInternal`:

```js
if (objectIs(eagerState, currentState))
  return (enqueueUpdate$1(fiber, queue, update, 0), …, !1);
```

Fără randare nu există mutație de DOM, iar o regiune `aria-live` raportează **schimbarea
conținutului**, nu intenția de a-l scrie. Deci: pagina 1→2 se aude, 2→3 și 3→4 nu — cod care
arată complet, trece `tsc`, `eslint` și orice test care verifică prezența lui `role="status"`.

Forma corectă e să garantezi tranziția `'' → text` la **fiecare** anunț: golire sincronă, apoi
scrierea textului pe `requestAnimationFrame`, cu `cancelAnimationFrame` la cleanup
(`ListUpdateAnnouncer.tsx`). Ordinea contează — „anunță acum, golește după un timp" are o cursă
reală: un al doilea clic dat înainte ca golirea întârziată să treacă prin DOM reproduce exact
bug-ul. Un text care „variază natural" (direcția Next/Previous) **nu** rezolvă nimic: trei
clicuri pe Next produc iarăși de trei ori același șir.

Regula generală, dincolo de `aria-live`: **când un efect secundar depinde de faptul că DOM-ul
chiar s-a schimbat, valoarea identică nu e o actualizare.**

## O mutație pe `document.documentElement` se scrie într-o funcție din afara componentei

`react-hooks/immutability` (din `eslint-plugin-react-hooks`, activ în `eslint.config.js`) respinge
scrierea unei valori globale din corpul unui component sau al unui hook — inclusiv atributele de pe
`<html>`, care sunt exact locul unde ajung tema și limba:

```tsx
// GREȘIT — pică `npx eslint resources/js`, nu `tsc`.
document.documentElement.lang = next;
```

Forma corectă e o funcție simplă, definită **în afara** oricărui component, apelată din handler:
`applyResolvedTheme()` în `hooks/useThemeSync.ts` (clasa `dark` + `style.colorScheme`) și
`applyDocumentLocale()` în `lib/i18n.ts` (`lang`) sunt cele două instanțe existente — a doua a fost
scrisă abia după ce regula a picat din nou, pe aceeași cauză, în Lotul I18N.

De reținut, fiindcă tocmai asta a costat de două ori: capcana nu se vede la `tsc`, doar la `eslint`,
deci un agent care rulează numai typecheck-ul o ratează. Iar `<html>` atrage genul ăsta de scriere
mai des decât orice alt element, pentru că preferințele randate server-side (temă, limbă) trebuie
oglindite pe el și din client, ca să nu existe fereastră de licărire.

## Inertia 3, nu 2

`Inertia::lazy()` / `LazyProp` **au fost eliminate** — se folosește `Inertia::optional()`.
Axios a fost scos; există client XHR propriu. `deferred props`, `polling`, `prefetching` și
`infinite scroll` sunt păstrate din v2 și sunt cerute de FR-PERF-01/02.
