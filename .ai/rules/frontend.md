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

## Inertia 3, nu 2

`Inertia::lazy()` / `LazyProp` **au fost eliminate** — se folosește `Inertia::optional()`.
Axios a fost scos; există client XHR propriu. `deferred props`, `polling`, `prefetching` și
`infinite scroll` sunt păstrate din v2 și sunt cerute de FR-PERF-01/02.
