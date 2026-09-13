// k6 — măsurătoarea de citire din plan §7.10, cu pragurile din §20.1 din specs.md.
//
// Rulat LOCAL, NU în CI: cota de minute GitHub Actions e comună cu celelalte 11 proiecte din
// portofoliu (§5.1), iar pragurile nu au nevoie de un runner ca să fie adevărate.
//
// Rescris în Faza 2, pe forma reală a listelor:
//  - Rândurile vin ca props DEFERRED (FR-PERF-01). GET-ul complet întoarce doar shell-ul
//    paginii, fără interogarea listei; rândurile vin din a doua cerere, parțială, pe care
//    clientul Inertia o face imediat după. Pragul de listă se aplică pe cererea parțială: un
//    GET simplu ar măsura o pagină care nu atinge tabela.
//  - Filtrele au forma `filter[status]=…` (`ListQuery`, specs.md §15.2).
//  - Cursorul adânc e unul REAL: `setup()` urmează `nextCursor` pagină cu pagină. Un șir
//    inventat e ignorat de `Cursor::fromEncoded()`, care întoarce atunci prima pagină.
//  - Comenzile (`orders`) apar în Faza 3 și intră atunci în script.
//
// Unde se rulează: pe imaginea de PRODUCȚIE (target `production` din docker/app/Dockerfile —
// php-fpm cu OPcache și `config:cache`/`route:cache`), în spatele nginx, pe seed-ul complet
// proaspăt (`demo:reset`) și fără alte procese grele pe mașină. NU pe `php artisan serve`:
// serverul de dezvoltare servește o cerere odată și, fără OPcache în CLI, recompilează
// framework-ul la fiecare cerere, deci p95 ar măsura coada și compilarea, nu interogările.
//
//   BASE_URL=http://127.0.0.1:8090 k6 run tests/Performance/reads.js
//
// Pragurile stau în `options.thresholds`, deci scriptul iese singur cu cod de eroare. Dacă un
// prag nu trece, remediul se decide pe `EXPLAIN ANALYZE` (`php artisan db:explain-critical`),
// nu prin relaxarea pragului.

import http from 'k6/http';
import { check, group, fail } from 'k6';
import { Trend } from 'k6/metrics';

const BASE = __ENV.BASE_URL || 'http://localhost:8000';
const WORKSPACE = __ENV.WORKSPACE || 'marlin'; // tenantul vitrină, cel mai mare volum (§21.1)
const ROLE = __ENV.ROLE || 'manager'; // acces operațional complet, fără îngustarea de Agent

// Plafonul de pagini urmate în `setup()`: 200 × 50 = 10.000 de rânduri, peste volumul oricărei
// liste Marlin din Faza 2, deci în practică lista se termină înainte și cursorul e chiar ultimul.
const MAX_DEPTH_PAGES = Number(__ENV.MAX_DEPTH_PAGES || 200);

// Cele trei liste din Faza 2. `worst` = filtrul + sortarea cele mai scumpe pe care le acceptă
// lista; cursorul adânc se adaugă în `setup()`. Query string-ul e deja codat (`%5B` = `[`).
const LISTS = [
  {
    name: 'accounts',
    component: 'Accounts/Index',
    prop: 'accounts',
    defaultPath: '/accounts',
    worstPath: '/accounts?filter%5Bstatus%5D=active&sort=-created_at',
  },
  {
    // Cea mai mare tabelă din cele trei. `filter[q]` e căutare `ILIKE` fără index trigram,
    // sub RLS (ADR-018), deci e cel mai scump filtru pe care îl acceptă lista.
    name: 'contacts',
    component: 'Contacts/Index',
    prop: 'contacts',
    defaultPath: '/contacts',
    worstPath: '/contacts?filter%5Bq%5D=an&sort=-created_at',
  },
  {
    // `value` e nullabil (§9.2) și nu are index de sortare.
    name: 'deals',
    component: 'Deals/Index',
    prop: 'deals',
    defaultPath: '/deals',
    worstPath: '/deals?filter%5Bstatus%5D=open&sort=-value',
  },
];

// Tag-ul se numește `list`, nu `group`: `group` e tag de sistem al k6 (`::accounts`) și s-ar
// suprapune peste sub-metricile din `thresholds`.
const pageShell = new Trend('page_shell', true);
const viewDefault = new Trend('view_default', true);
const worstCase = new Trend('view_worst_case', true);

export const options = {
  setupTimeout: '5m',
  // Implicit, k6 golește cookie-urile la fiecare iterație: doar prima iterație a unui VU ar fi
  // autentificată, iar restul ar măsura pagina de login, tot cu 200. Găsit la prima rulare.
  noCookiesReset: true,
  scenarios: {
    reads: { executor: 'constant-vus', vus: 5, duration: '30s' },
  },
  summaryTrendStats: ['avg', 'med', 'p(95)', 'p(99)', 'max'],
  thresholds: {
    // Rândul 1 din §20.1: citiri simple, vedere implicită. Pe liste, `view_default` e cererea
    // parțială cu rândurile; `page_shell` e primul GET, tot o citire simplă, cu același prag.
    'view_default{list:dashboard}': ['p(95)<200'],
    'page_shell{list:accounts}': ['p(95)<200'],
    'page_shell{list:contacts}': ['p(95)<200'],
    'page_shell{list:deals}': ['p(95)<200'],
    'view_default{list:accounts}': ['p(95)<200'],
    'view_default{list:contacts}': ['p(95)<200'],
    'view_default{list:deals}': ['p(95)<200'],
    // Rândul 2: cel mai rău caz — cursor adânc + cel mai scump filtru al listei.
    'view_worst_case{list:accounts}': ['p(95)<500'],
    'view_worst_case{list:contacts}': ['p(95)<500'],
    'view_worst_case{list:deals}': ['p(95)<500'],
    // O măsurătoare cu 4xx/5xx în ea nu e o măsurătoare, e o iluzie optică.
    checks: ['rate==1.0'],
  },
};

const PAGE_OBJECT = /<script data-page="app" type="application\/json">([\s\S]*?)<\/script>/;

/** Obiectul de pagină Inertia din răspunsul HTML (`@inertia` îl pune într-un `<script>` JSON). */
function readPage(res) {
  const match = typeof res.body === 'string' ? res.body.match(PAGE_OBJECT) : null;

  if (!match) {
    return null;
  }

  try {
    return JSON.parse(match[1]);
  } catch (error) {
    return null;
  }
}

/** Cererea pe care o face clientul Inertia pentru un prop deferred, cu aceleași headere. */
function partialReload(url, page, prop, tags) {
  return http.get(url, {
    headers: {
      'X-Inertia': 'true',
      'X-Inertia-Version': page.version || '',
      'X-Inertia-Partial-Component': page.component,
      'X-Inertia-Partial-Data': prop,
      'X-Requested-With': 'XMLHttpRequest',
      Accept: 'text/html, application/xhtml+xml',
    },
    tags,
    // O versiune de assets depășită dă 409, iar o sesiune pierdută dă 302: amândouă trebuie să
    // pice verificarea, nu să fie urmate tăcut până la o pagină de 200.
    redirects: 0,
  });
}

/** Prop-ul listei din răspunsul parțial, sau `null` dacă lipsește (altă componentă, 409, login). */
function listPayload(res, prop) {
  try {
    const props = res.json().props;
    const payload = props ? props[prop] : null;

    return payload && Array.isArray(payload.data) ? payload : null;
  } catch (error) {
    return null;
  }
}

function withCursor(url, cursor) {
  if (!cursor) {
    return url;
  }

  return `${url}${url.includes('?') ? '&' : '?'}cursor=${encodeURIComponent(cursor)}`;
}

function login() {
  // Pagina de login pune cookie-urile de sesiune și XSRF în borcanul lui k6.
  const page = http.get(`${BASE}/login`);
  check(page, { 'login page e 200': (r) => r.status === 200 });

  const jar = http.cookieJar();
  const xsrf = (jar.cookiesForURL(BASE)['XSRF-TOKEN'] || [])[0];

  if (!xsrf) {
    fail('Lipsește cookie-ul XSRF-TOKEN — aplicația rulează pe BASE_URL corect? (pe HTTP, SESSION_SECURE_COOKIE=false)');
  }

  // FR-PUB-02: autentificare instant prin contul demo, fără parolă (DEMO_MODE=true).
  const res = http.post(`${BASE}/login/demo/${ROLE}`, null, {
    headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf), Accept: 'text/html' },
    redirects: 0,
  });

  check(res, { 'login demo redirectează': (r) => r.status === 302 });
}

/**
 * Urmează `nextCursor` până la ultima pagină (sau până la plafon) și întoarce cursorul care
 * aduce cea mai adâncă pagină cu rânduri.
 */
function deepestCursor(list, shellUrl, page) {
  let cursor = null;
  let pages = 0;
  let rows = 0;

  for (;;) {
    const res = partialReload(withCursor(shellUrl, cursor), page, list.prop);
    const payload = listPayload(res, list.prop);

    if (res.status !== 200 || !payload || !Array.isArray(payload.data)) {
      fail(`${list.name}: pagina ${pages + 1} a răspuns ${res.status}, fără lista "${list.prop}" în props.`);
    }

    pages += 1;
    rows += payload.data.length;

    if (payload.data.length === 0 && pages > 1) {
      // Un `nextCursor` ne-nul care duce la o pagină goală înseamnă paginare ruptă (de ex. o
      // valoare NULL pe coloana de sortare, pe care comparația din cursor n-o mai găsește).
      fail(`${list.name}: pagina ${pages} e goală deși pagina anterioară avea nextCursor — paginarea pe cursor e ruptă.`);
    }

    if (!payload.nextCursor || pages >= MAX_DEPTH_PAGES) {
      break;
    }

    cursor = payload.nextCursor;
  }

  console.log(`${list.name}: cursorul adânc aduce pagina ${pages} (${rows} rânduri parcurse, ${shellUrl.replace(BASE, '')})`);

  if (pages === 1) {
    console.warn(`${list.name}: lista încape pe o pagină — „cel mai rău caz" se măsoară pe prima pagină.`);
  }

  return { cursor, pages, rows };
}

export function setup() {
  login();

  const dashboard = http.get(`${BASE}/${WORKSPACE}/dashboard`);

  if (dashboard.status !== 200 || readPage(dashboard) === null) {
    fail(`Dashboard-ul răspunde ${dashboard.status} fără obiect de pagină Inertia (${dashboard.url}) — login eșuat sau WORKSPACE greșit?`);
  }

  const lists = LISTS.map((list) => {
    const shell = http.get(`${BASE}/${WORKSPACE}${list.worstPath}`);
    const page = readPage(shell);

    if (shell.status !== 200 || page === null) {
      fail(`${list.name}: ${shell.status} fără obiect de pagină Inertia (${shell.url}).`);
    }

    if (page.component !== list.component) {
      fail(`${list.name}: componenta e "${page.component}", nu "${list.component}".`);
    }

    const deferred = Object.values(page.deferredProps || {}).reduce((all, props) => all.concat(props), []);

    if (!deferred.includes(list.prop)) {
      // Dacă prop-ul nu mai e deferred, rândurile vin deja în shell, iar cererea parțială de
      // mai jos ar măsura altceva decât ce vede utilizatorul: scriptul trebuie rescris.
      fail(`${list.name}: prop-ul "${list.prop}" nu mai e deferred (deferredProps = ${JSON.stringify(page.deferredProps)}).`);
    }

    const deep = deepestCursor(list, shell.url, page);

    return { ...list, version: page.version, worstUrl: shell.url, deepCursor: deep.cursor };
  });

  return { lists };
}

let loggedIn = false;

export default function (data) {
  // O dată per VU (cu `noCookiesReset`, borcanul supraviețuiește între iterații): un login per
  // iterație ar măsura și scrierea sesiunii, plus plafonul de login per IP (§22.5).
  if (!loggedIn) {
    login();
    loggedIn = true;
  }

  group('dashboard', () => {
    const tags = { list: 'dashboard' };
    const res = http.get(`${BASE}/${WORKSPACE}/dashboard`, { tags });
    const page = readPage(res);

    // Componenta, nu doar statusul: o sesiune pierdută redirectează la login, care e tot 200.
    check(res, { 'dashboard 200': (r) => r.status === 200 && page !== null && page.component === 'Dashboard' });
    viewDefault.add(res.timings.duration, tags);
  });

  for (const list of data.lists) {
    group(list.name, () => {
      const tags = { list: list.name };

      const shell = http.get(`${BASE}/${WORKSPACE}${list.defaultPath}`, { tags: { ...tags, part: 'shell' } });
      const page = readPage(shell);

      const isListPage = page !== null && page.component === list.component;

      check(shell, { 'shell 200 pe componenta listei': (r) => r.status === 200 && isListPage });
      pageShell.add(shell.timings.duration, tags);

      if (!isListPage) {
        return;
      }

      // `shell.url`, nu calea inițială: o vizualizare salvată setată ca implicit redirectează
      // prima vizită (FR-VIEW-02), iar clientul cere rândurile pe URL-ul final.
      const first = partialReload(shell.url, page, list.prop, { ...tags, part: 'data' });
      const firstPayload = listPayload(first, list.prop);

      check(first, { 'vedere implicită 200 cu rânduri': (r) => r.status === 200 && firstPayload !== null && firstPayload.data.length > 0 });
      viewDefault.add(first.timings.duration, tags);

      const deep = partialReload(
        withCursor(list.worstUrl, list.deepCursor),
        { version: list.version, component: list.component },
        list.prop,
        { ...tags, part: 'deep' },
      );
      const deepPayload = listPayload(deep, list.prop);

      check(deep, { 'cel mai rău caz 200 cu rânduri': (r) => r.status === 200 && deepPayload !== null && deepPayload.data.length > 0 });
      worstCase.add(deep.timings.duration, tags);
    });
  }
}
