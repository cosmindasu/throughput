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

Un nume accesibil repetat pe fiecare rând („Deactivate member" × N) se disambiguizează cu
`aria-label` („Deactivate Jane Doe"), iar o regiune `aria-live` peste o listă cu polling anunță
doar schimbările de stare, nu fiecare poll.

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

## Inertia 3, nu 2

`Inertia::lazy()` / `LazyProp` **au fost eliminate** — se folosește `Inertia::optional()`.
Axios a fost scos; există client XHR propriu. `deferred props`, `polling`, `prefetching` și
`infinite scroll` sunt păstrate din v2 și sunt cerute de FR-PERF-01/02.
