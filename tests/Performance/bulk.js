// k6 — operațiile în masă din Faza 3, valul 2 (plan §9, „Livrabile"), praguri din
// specs.md §20.1/§1.3/§13.1-13.4/§22.2. Extinde `tests/Performance/reads.js` (§7.10), care
// rămâne neatins — script separat, pentru că mecanismul (dispecerizare → coadă → polling →
// anulare cooperativă) nu are nimic în comun cu citirile simple.
//
// Unde se rulează — LA FEL ca reads.js, dar cu o cerință în plus: operațiile în masă au
// nevoie de un WORKER REAL (`Bus::batch()` procesat de `queue:work`), altfel scriptul
// măsoară doar dispecerizarea, nu execuția. Stack-ul de producție (php-fpm+OPcache+nginx pe
// imaginea `production`, legat de baza de dev `throughput`) + worker-ul (coadă Redis reală,
// plafon 384m ca `horizon` în producție) pornesc din scratchpad-ul sesiunii:
//
//   .../scratchpad/k6-stack.sh build
//   .../scratchpad/k6-stack.sh envfile
//   .../scratchpad/k6-stack.sh up
//   .../scratchpad/k6-stack.sh worker
//   [opțional] .../scratchpad/k6-stack.sh reset   # demo:reset, ~1 minut, seed curat
//
// DEMO_MODE=true (ca în producție — vezi envfile-ul stack-ului). `BULK_MAX_ROWS_ABSOLUTE`
// rămâne implicit 60.000 (specs.md §22.2): 3× peste ținta KPI de 20.000 și 2× peste cel mai
// mare tenant semănat (~30.000 comenzi pe Marlin) — niciunul dintre scenariile de mai jos nu
// atinge plafonul, deci `DemoMode::exceedsBulkRowCap()` nu refuză nimic aici. Testat exact ce
// rulează în producție, nu un mediu relaxat artificial.
//
// FAZE, RULATE SEPARAT — NICIODATĂ ÎN ACELAȘI RUN. `k6-stack.sh worker` pornește un SINGUR
// proces `queue:work` (concurență 1, ca în producție — plafonul de memorie e per-proces).
// Dacă două operații mari ar rula concurent, joburile lor s-ar interfoliile pe ACELAȘI
// worker și fiecare operație ar părea mai lentă decât e — nu din cauza volumului ei, ci din
// cauza celeilalte. `PHASE` selectează un singur set de scenarii per invocare:
//
//   BASE_URL=http://127.0.0.1:8090 k6 run -e PHASE=500    tests/Performance/bulk.js
//   BASE_URL=http://127.0.0.1:8090 k6 run -e PHASE=20k    tests/Performance/bulk.js
//   BASE_URL=http://127.0.0.1:8090 k6 run -e PHASE=cancel tests/Performance/bulk.js
//   BASE_URL=http://127.0.0.1:8090 k6 run -e PHASE=agent  tests/Performance/bulk.js
//   BASE_URL=http://127.0.0.1:8090 k6 run -e PHASE=export tests/Performance/bulk.js   # opțional, §4 din task
//
// Ce face fiecare fază (plan §9 „Livrabile", specs.md §13/§20.1):
//   500    — Manager reasignează owner pe un filtru cu 500+ conturi (Accounts), urmărește
//            `Bulk/Show` exact ca UI-ul (polling la 2s — `usePoll(2000, {}, ...)` din
//            `resources/js/Pages/Bulk/Show.tsx`, deci NU un endpoint separat de status, ci
//            aceeași pagină, GET cu `X-Inertia: true`, fără antete de reload parțial).
//   20k    — Manager reasignează owner pe TOATE comenzile tenantului Marlin (~30.000,
//            peste ținta KPI de 20.000 din §1.3/§20.1). Concurent, 5 VU separate cer
//            `Orders/Index` (vederea implicită, exact pragul din §20.1 rândul 1) CÂT TIMP
//            operația rulează — „interfața rămâne interactivă", măsurat, nu presupus.
//   cancel — pornește aceeași reasignare pe toate comenzile, o anulează când trece de ~50%,
//            verifică prin API (nu prin DB — k6 n-are driver Postgres) că starea e coerentă:
//            operația `cancelled`, un număr plauzibil de rânduri atinse, restul neatinse.
//   agent  — contul demo Agent: plafonul dur de 500 (BR-BULK-02) și pragul de confirmare de
//            125 (25% din 500, FR-BULK-01), pe Accounts — singura resursă pe care Agentul
//            are `bulkReassignOwner` (`AccountPolicy`; pe Deals/Orders îi lipsește
//            `{resursă}.change_owner`, deci nici n-ajunge la validarea de plafon).
//   export — opțional (task §4): export CSV pe Orders sub pragul sincron (5.000 rânduri,
//            `EXPORT_SYNC_MAX_ROWS`) vs. peste el (job în coadă).
//
// Volumele nu sunt hardcodate: fiecare fază MĂSOARĂ contorul „Select all N" (prop-ul
// deferred `total`, aceeași funcție — `BulkMatchingRowCount` — pe care o citește dialogul de
// confirmare din UI) înainte de a alege un filtru, exact ca `reads.js` cu cursorul adânc.
// Seed-ul e probabilistic (owneri aleși cu `array_rand`), deci un filtru „conturile
// agentului X" nu are un N fix — scriptul îl descoperă, îl loghează, și doar apoi decide.
//
// Pragurile intră în `options.thresholds`, construite per fază (nu are sens un prag pe o
// metrică fără eșantioane în faza curentă). Dacă un prag nu trece, remediul se caută cu
// `EXPLAIN ANALYZE`/numărul de interogări, nu prin relaxarea pragului.

import http from 'k6/http';
import { check, fail, sleep } from 'k6';
import { Rate, Trend } from 'k6/metrics';

const BASE = __ENV.BASE_URL || 'http://127.0.0.1:8090';
const WORKSPACE = __ENV.WORKSPACE || 'marlin'; // tenantul vitrină, §21.1 — ~30.000 comenzi

const VALID_PHASES = ['500', '20k', 'cancel', 'agent', 'export'];
const PHASE = __ENV.PHASE;

if (!VALID_PHASES.includes(PHASE)) {
  throw new Error(
    `PHASE lipsă sau necunoscută: "${PHASE}". Folosește -e PHASE=<${VALID_PHASES.join('|')}> — vezi docblock-ul ` +
      'de la începutul fișierului. Fazele NU rulează concurent: un singur worker de coadă ' +
      'procesează secvențial, deci două operații mari pornite în același run s-ar încetini reciproc.',
  );
}

// KPI §1.3/§20.1: „operație pe 20.000+ rânduri". Chunk-ul din `config/throughput.php`
// (`bulk_chunk_size`, implicit 500) — folosit doar la afișarea unei estimări în consolă,
// nu la vreo asercțiune (scriptul nu presupune configul serverului, îl deduce din
// `totalJobs`/`total_rows` întorși de API).
const MAX_POLLS = Number(__ENV.MAX_POLLS || 300); // 300 × 2s = 10 minute, plasă de siguranță
const TERMINAL_STATUSES = ['completed', 'failed', 'cancelled'];

// ---------------------------------------------------------------------------------------
// Metrici — nume distincte per fel de cerere, tag `scenario` pentru pragurile per fază.
// ---------------------------------------------------------------------------------------
const dispatchLatency = new Trend('bulk_dispatch_latency', true);
const statusPollLatency = new Trend('bulk_status_poll_latency', true);
const cancelLatency = new Trend('bulk_cancel_latency', true);
const firstProgressMs = new Trend('bulk_first_progress_ms', true);
const totalDurationMs = new Trend('bulk_total_duration_ms', true);
const listDuringBulkLatency = new Trend('orders_view_default_during_bulk20k', true);
const cancelCoherence = new Rate('bulk_cancel_coherence');
const agentLimitCoherence = new Rate('bulk_agent_limit_coherence');
const exportSyncLatency = new Trend('export_sync_latency', true);
const exportAsyncDispatchLatency = new Trend('export_async_dispatch_latency', true);

// ---------------------------------------------------------------------------------------
// Helpere Inertia — mirror-ul celor din reads.js, extinse cu POST (dispecerizare/anulare)
// și cu citirea directă JSON a răspunsurilor `X-Inertia: true` (nu doar embed-ul din HTML).
// ---------------------------------------------------------------------------------------

const PAGE_OBJECT = /<script data-page="app" type="application\/json">([\s\S]*?)<\/script>/;

/** Obiectul de pagină Inertia dintr-un răspuns HTML complet (prima vizită, fără X-Inertia). */
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

/** GET complet (fără antete Inertia) — folosit o dată per flux, ca să obținem `version`. */
function fetchShell(url, tags) {
  const res = http.get(url, { tags });
  const page = readPage(res);

  return { res, page };
}

function inertiaHeaders(version) {
  return {
    'X-Inertia': 'true',
    'X-Inertia-Version': version || '',
    'X-Requested-With': 'XMLHttpRequest',
    Accept: 'text/html, application/xhtml+xml',
  };
}

/** Cererea parțială pentru un prop deferred (`total`, `accounts`/`deals`/`orders`…). */
function partialReload(url, page, prop, tags) {
  return http.get(url, {
    headers: {
      ...inertiaHeaders(page.version),
      'X-Inertia-Partial-Component': page.component,
      'X-Inertia-Partial-Data': prop,
    },
    tags,
    redirects: 0,
  });
}

/** GET „ca `usePoll`": reload complet al paginii curente, FĂRĂ antete de reload parțial —
 * `Bulk/Show.tsx`/`Exports/Show.tsx` apelează `usePoll(2000, {}, …)`, adică
 * `router.reload({})`, fără `only`/`except` (verificat în `node_modules/@inertiajs/core`,
 * `doReload()` → `visit(window.location.href, {...options})`). E ACEEAȘI pagină, nu un
 * endpoint separat de status. */
function inertiaGet(url, version, tags) {
  return http.get(url, { headers: inertiaHeaders(version), tags, redirects: 0 });
}

/** `router.post(url, {...})` din `useBulkActionDispatch.ts`/`Bulk/Show.tsx::cancel()` —
 * fără fișiere, deci JSON (`transformUrlAndData` din `@inertiajs/core`), nu form-urlencoded. */
function inertiaPostJson(url, version, body, tags) {
  const headers = { ...inertiaHeaders(version), ...csrfHeader(), 'Content-Type': 'application/json' };

  return http.post(url, JSON.stringify(body), { headers, tags, redirects: 0 });
}

function csrfHeader() {
  const jar = http.cookieJar();
  const xsrf = (jar.cookiesForURL(BASE)['XSRF-TOKEN'] || [])[0];

  if (!xsrf) {
    fail('Lipsește cookie-ul XSRF-TOKEN — sesiunea nu e autentificată încă (a rulat login()?).');
  }

  return { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) };
}

/** Prop-ul unei liste (`{data, nextCursor}`) dintr-un răspuns parțial — ca în reads.js. */
function listPayload(res, prop) {
  try {
    const props = res.json().props;
    const payload = props ? props[prop] : null;

    return payload && Array.isArray(payload.data) ? payload : null;
  } catch (error) {
    return null;
  }
}

/** Contorul „Select all N" — `BulkMatchingRowCount`, deferred sub prop-ul `total`. */
function fetchTotal(url, page, tags) {
  const res = partialReload(url, page, 'total', tags);

  if (res.status !== 200) {
    return null;
  }

  try {
    const total = res.json().props.total;

    return typeof total === 'number' ? total : null;
  } catch (error) {
    return null;
  }
}

/** `operation` dintr-un răspuns JSON Inertia (`BulkOperationResource`, camelCase). */
function operationFromRes(res) {
  if (res.status !== 200) {
    return null;
  }

  try {
    const body = res.json();

    return body && body.props ? body.props.operation : null;
  } catch (error) {
    return null;
  }
}

/** `export` dintr-un răspuns JSON Inertia (`ExportResource`) — fază `export`. */
function exportFromRes(res) {
  if (res.status !== 200) {
    return null;
  }

  try {
    const body = res.json();

    return body && body.props ? body.props.export : null;
  } catch (error) {
    return null;
  }
}

function isRedirect(res) {
  return [301, 302, 303, 307, 308].includes(res.status);
}

/**
 * O dispecerizare REUȘITĂ redirecționează spre `bulk.show` (`/bulk/{id}`) — o dispecerizare
 * RESPINSĂ (`ValidationException`) redirecționează tot cu 302/303, dar ÎNAPOI la pagina de
 * listă (`redirect()->back()`, ținta luată din `_previous.url`, pusă în sesiune de
 * `StartSession::storeCurrentUrl()` la ultimul GET non-ajax — verificat direct pe stack:
 * Laravel/Inertia NU întorc 422 JSON aici, `Request::expectsJson()` e fals pentru un
 * `Accept: text/html, application/xhtml+xml` explicit — `acceptsAnyContentType()` cere
 * wildcard-ul „orice tip" ca prim tip acceptabil, absent aici). Verificarea trebuie deci pe
 * DESTINAȚIA redirect-ului, nu doar pe statusul lui.
 */
function isBulkOperationRedirect(res) {
  return isRedirect(res) && typeof res.headers.Location === 'string' && res.headers.Location.includes('/bulk/');
}

/**
 * Urmează un redirect de validare eșuată și citește `props.errors.selection` — mesajul pe
 * care `useBulkActionDispatch.ts::onError` l-ar afișa în dialog. Folosit DOAR ca să
 * deosebim „peste plafon" de „sub pragul de confirmare" în faza `agent`; nu schimbă
 * verdictul (deja stabilit de `isBulkOperationRedirect`), doar îl explică.
 */
function readSelectionError(location, version, tags) {
  const res = inertiaGet(location, version, tags);

  try {
    return res.json().props.errors.selection || null;
  } catch (error) {
    return null;
  }
}

/** `?filter[a]=x&filter[b]=y`, codat ca în reads.js (`%5B`/`%5D`) — ignoră valorile goale. */
function filterQuery(filters) {
  const pairs = Object.keys(filters)
    .filter((key) => filters[key] !== null && filters[key] !== undefined && filters[key] !== '')
    .map((key) => `filter%5B${key}%5D=${encodeURIComponent(filters[key])}`);

  return pairs.length > 0 ? `?${pairs.join('&')}` : '';
}

/**
 * FR-PUB-02 — login demo, ca în reads.js. Fiecare VU își face propriul login: `setup()`
 * și VU-urile de scenariu au borcane de cookie-uri SEPARATE (verificat — `setup()` nu-și
 * transmite sesiunea către VU-uri), și fiecare din cele 5 VU-uri ale `listDuringBulk` are,
 * la rândul lui, propriul borcan.
 */
function login(role) {
  const page = http.get(`${BASE}/login`);

  check(page, { 'login page e 200': (r) => r.status === 200 });

  const jar = http.cookieJar();
  const xsrf = (jar.cookiesForURL(BASE)['XSRF-TOKEN'] || [])[0];

  if (!xsrf) {
    fail('Lipsește cookie-ul XSRF-TOKEN — aplicația rulează pe BASE_URL corect? (pe HTTP, SESSION_SECURE_COOKIE=false)');
  }

  const res = http.post(`${BASE}/login/demo/${role}`, null, {
    headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf), Accept: 'text/html' },
    redirects: 0,
  });

  check(res, { [`login demo ${role} redirectează`]: (r) => r.status === 302 });
}

/**
 * Polling-ul de status exact ca `Bulk/Show.tsx` (interval 2s), până la o stare terminală.
 * `startedAt`, dacă e dat, ancorează „timpul total"/„primul progres" la momentul REAL al
 * dispecerizării (utile din `setup()`, unde POST-ul de dispecerizare rulează înaintea
 * scenariului de polling) — altfel T0 e chiar începutul acestei bucle.
 */
function pollBulkOperation(url, version, tags, startedAt) {
  const t0 = startedAt || Date.now();
  let firstProgressAt = null;
  let finalOp = null;

  for (let i = 0; i < MAX_POLLS; i++) {
    sleep(2);

    const res = inertiaGet(url, version, tags);
    statusPollLatency.add(res.timings.duration, tags);
    const op = operationFromRes(res);

    check(res, { [`${tags.scenario}: sondaj de status 200 cu operation`]: () => res.status === 200 && op !== null });

    if (op === null) {
      continue;
    }

    if (firstProgressAt === null && (op.processedJobs > 0 || op.processedRowsEstimate > 0)) {
      firstProgressAt = Date.now();
    }

    if (TERMINAL_STATUSES.includes(op.status)) {
      finalOp = op;
      break;
    }
  }

  if (finalOp === null) {
    fail(`pollBulkOperation: nicio stare terminală după ${MAX_POLLS} sondaje pe ${url} — operația e blocată sau workerul nu rulează (k6-stack.sh worker)?`);
  }

  return {
    finalOp,
    firstProgressMs: firstProgressAt !== null ? firstProgressAt - t0 : null,
    totalMs: Date.now() - t0,
  };
}

// ---------------------------------------------------------------------------------------
// options — scenarii și praguri construite per PHASE.
// ---------------------------------------------------------------------------------------

function buildScenarios() {
  if (PHASE === '500') {
    return { bulk500: { executor: 'shared-iterations', vus: 1, iterations: 1, exec: 'bulk500' } };
  }

  if (PHASE === '20k') {
    return {
      // Poll-erul operației — 1 VU, exact fluxul `Bulk/Show.tsx`.
      bulk20kPoll: { executor: 'shared-iterations', vus: 1, iterations: 1, exec: 'bulk20kPoll', startTime: '0s' },
      // §20.1 „interfața rămâne interactivă" — 5 VU separate, cerând `Orders/Index` CÂT
      // TIMP operația rulează, oprindu-se singure când observă starea terminală.
      listDuringBulk: {
        executor: 'per-vu-iterations',
        vus: Number(__ENV.LIST_PROBE_VUS || 5),
        iterations: 1,
        exec: 'listDuringBulk',
        startTime: '0s',
        maxDuration: '15m',
      },
    };
  }

  if (PHASE === 'cancel') {
    return { cancelHalfway: { executor: 'shared-iterations', vus: 1, iterations: 1, exec: 'cancelHalfway' } };
  }

  if (PHASE === 'agent') {
    return { agentLimits: { executor: 'shared-iterations', vus: 1, iterations: 1, exec: 'agentLimits' } };
  }

  return { exportCsv: { executor: 'shared-iterations', vus: 1, iterations: 1, exec: 'exportCsv' } };
}

function buildThresholds() {
  const checksThreshold = { checks: ['rate==1.0'] };

  if (PHASE === '500') {
    return {
      ...checksThreshold,
      // Dispecerizarea face un COUNT + un INSERT + un dispatch de job — tier „agregate",
      // §20.1 rândul 3 (p95<500ms), nu tier „citire simplă": are un COUNT în plus.
      'bulk_dispatch_latency{scenario:bulk500}': ['p(95)<500'],
      // Sondajul de status citește UN rând (`BulkOperation` + `job_batches` pe cheie
      // primară) — cost independent de câte rânduri atinge operația, deci tier „citire
      // simplă", §20.1 rândul 1 (p95<200ms), la fel pentru toate fazele de mai jos.
      'bulk_status_poll_latency{scenario:bulk500}': ['p(95)<200'],
    };
  }

  if (PHASE === '20k') {
    return {
      ...checksThreshold,
      'bulk_dispatch_latency{scenario:bulk20k}': ['p(95)<500'],
      'bulk_status_poll_latency{scenario:bulk20k}': ['p(95)<200'],
      // KPI §1.3/§20.1: „indicatorul actualizat la ≤2s" — operaționalizat ca sondajul
      // însuși fiind rapid (de mai sus) ȘI lista rămânând pe pragul ei normal de „vedere
      // implicită" CÂT TIMP workerul toacă cele 30.000 de rânduri — „interfața rămâne
      // interactivă", măsurat pe server, nu pe Total Blocking Time (k6 n-are Performance
      // panel; vezi raportul pentru ce rămâne de verificat manual).
      orders_view_default_during_bulk20k: ['p(95)<200'],
    };
  }

  if (PHASE === 'cancel') {
    return {
      ...checksThreshold,
      'bulk_dispatch_latency{scenario:cancel}': ['p(95)<500'],
      'bulk_status_poll_latency{scenario:cancel}': ['p(95)<200'],
      'bulk_cancel_latency{scenario:cancel}': ['p(95)<500'],
      // Livrabilul §9: „0 rânduri corupte" — verificat prin API (vezi `cancelHalfway`),
      // un singur prag binar: fie coerent, fie nu.
      bulk_cancel_coherence: ['rate==1.0'],
    };
  }

  if (PHASE === 'agent') {
    return {
      ...checksThreshold,
      'bulk_status_poll_latency{scenario:agent}': ['p(95)<200'],
      // BR-BULK-02 (plafon 500) și FR-BULK-01 (prag de confirmare 125) — corectitudinea
      // validării, nu o latență.
      bulk_agent_limit_coherence: ['rate==1.0'],
    };
  }

  // export — fără prag de latență impus de specs.md (nu există un rând §20.1 dedicat
  // exportului sincron); scriptul RAPORTEAZĂ cifra, nu inventează un SLO nesusținut.
  return checksThreshold;
}

export const options = {
  setupTimeout: '5m',
  noCookiesReset: true,
  scenarios: buildScenarios(),
  summaryTrendStats: ['avg', 'med', 'p(95)', 'p(99)', 'max'],
  thresholds: buildThresholds(),
};

// ---------------------------------------------------------------------------------------
// setup() — doar pentru faza `20k`: dispecerizarea trebuie să se întâmple O SINGURĂ DATĂ,
// înainte ca cele două scenarii concurente (poll-erul și cele 5 VU de listă) să pornească,
// altfel fiecare VU ar dispeceriza propria lui operație de 30.000 de rânduri.
// ---------------------------------------------------------------------------------------
export function setup() {
  if (PHASE !== '20k') {
    return {};
  }

  login('manager');

  const shell = fetchShell(`${BASE}/${WORKSPACE}/orders`, { phase: '20k', part: 'setup_shell' });

  if (shell.res.status !== 200 || shell.page === null || shell.page.component !== 'Orders/Index') {
    fail(`setup(20k): ${shell.res.status} fără pagina "Orders/Index" (${shell.res.url}) — login eșuat sau WORKSPACE greșit?`);
  }

  const version = shell.page.version;
  const authUserId = shell.page.props.auth.user.id;
  const owners = shell.page.props.owners;

  if (!Array.isArray(owners) || owners.length < 2) {
    fail('setup(20k): props.owners are mai puțin de 2 membri activi — nu am cui reasigna.');
  }

  const totalRows = fetchTotal(`${BASE}/${WORKSPACE}/orders`, shell.page, { phase: '20k', probe: 'total' });
  console.log(`setup(20k): Marlin are ${totalRows} comenzi (filtrul implicit Manager, fără restricție de owner).`);

  if (totalRows === null || totalRows < 20000) {
    fail(`setup(20k): total_rows=${totalRows}, sub ținta KPI de 20.000 (§1.3/§20.1) — seed-ul nu (mai) are volumul așteptat pe Marlin.`);
  }

  const target = owners.find((o) => o.id !== authUserId) || owners[0];

  const dispatchRes = inertiaPostJson(
    `${BASE}/${WORKSPACE}/orders/bulk/reassign-owner`,
    version,
    { selectAllMatching: true, ids: [], owner_user_id: target.id, confirmed: true },
    { phase: '20k', name: 'dispatch' },
  );
  dispatchLatency.add(dispatchRes.timings.duration, { scenario: 'bulk20k' });

  const dispatchedAt = Date.now();
  const dispatchOk = check(dispatchRes, {
    'setup(20k): dispecerizarea redirecționează spre bulk.show': (r) => isBulkOperationRedirect(r),
  });

  if (!dispatchOk) {
    fail(`setup(20k): dispecerizarea a eșuat: ${dispatchRes.status} ${dispatchRes.body}`);
  }

  const operationUrl = dispatchRes.headers.Location;
  console.log(`setup(20k): operație pornită la ${operationUrl} — țintă reasignare: ${target.name} (${target.id}), total_rows=${totalRows}.`);

  return { operationUrl, version, totalRows, dispatchedAt, targetId: target.id, targetName: target.name };
}

// ---------------------------------------------------------------------------------------
// Fază 500 — Manager, Accounts, filtru cu 500+ rânduri (US-BULK-01: „reasignez conturile
// unui coleg plecat"). Măsoară: latența dispecerizării, timpul până la primul progres
// nenul, timpul total, p95 al sondajului de status.
// ---------------------------------------------------------------------------------------
export function bulk500() {
  login('manager');

  const shell = fetchShell(`${BASE}/${WORKSPACE}/accounts`, { phase: '500', part: 'shell' });

  if (shell.res.status !== 200 || shell.page === null || shell.page.component !== 'Accounts/Index') {
    fail(`bulk500: ${shell.res.status} fără pagina "Accounts/Index" (${shell.res.url}).`);
  }

  const version = shell.page.version;
  const authUserId = shell.page.props.auth.user.id;
  const owners = shell.page.props.owners;

  if (!Array.isArray(owners) || owners.length < 2) {
    fail('bulk500: props.owners are mai puțin de 2 membri activi.');
  }

  // Caut, printre colegi, un owner cu 500+ conturi proprii — nu presupun cifra, o măsor
  // (`total`, aceeași funcție care alimentează „Select all N accounts matching this
  // filter" din UI). Mă opresc la primul găsit, ca să nu bat serverul degeaba.
  console.log('bulk500: caut un owner cu 500+ conturi proprii…');
  let source = null;
  let sourceTotal = null;

  for (const owner of owners) {
    if (owner.id === authUserId) {
      continue;
    }

    const total = fetchTotal(`${BASE}/${WORKSPACE}/accounts${filterQuery({ owner: owner.id })}`, shell.page, {
      phase: '500',
      probe: 'owner_total',
    });
    console.log(`  ${owner.name} (${owner.id}): ${total === null ? 'eroare la măsurare' : `${total} conturi`}`);

    if (total !== null && total >= 500) {
      source = owner;
      sourceTotal = total;
      break;
    }
  }

  let filterQ = '';

  if (source !== null) {
    filterQ = filterQuery({ owner: source.id });
  } else {
    // Fallback — niciun owner individual n-a atins 500 (seed neobișnuit de plat): las
    // filtrul de status implicit „active" (§21.2: 60% din conturi), care cu ~4.000 de
    // conturi pe Marlin ar trebui oricum să treacă de 500.
    console.warn('bulk500: niciun owner individual nu are 500+ conturi — cad pe filter[status]=active.');
    sourceTotal = fetchTotal(`${BASE}/${WORKSPACE}/accounts${filterQuery({ status: 'active' })}`, shell.page, {
      phase: '500',
      probe: 'fallback_status_active',
    });
    filterQ = filterQuery({ status: 'active' });

    if (sourceTotal === null || sourceTotal < 500) {
      fail(`bulk500: nici filter[status]=active nu are 500+ rânduri (${sourceTotal}) — seed-ul de pe Marlin e mult sub volumul din §21.1.`);
    }
  }

  const dest = owners.find((o) => o.id !== (source ? source.id : authUserId)) || owners[0];
  // BulkConfirmationThreshold::for(Manager) = 1.000 (ABSOLUTE_CAP, fără plafon de rol).
  const confirmed = sourceTotal > 1000;

  console.log(`bulk500: dispecerizez reasignarea a ${sourceTotal} conturi către ${dest.name} (${dest.id}), confirmed=${confirmed}.`);

  const dispatchRes = inertiaPostJson(
    `${BASE}/${WORKSPACE}/accounts/bulk/reassign-owner${filterQ}`,
    version,
    { selectAllMatching: true, ids: [], owner_user_id: dest.id, confirmed },
    { phase: '500', name: 'dispatch' },
  );
  dispatchLatency.add(dispatchRes.timings.duration, { scenario: 'bulk500' });
  const dispatchedAt = Date.now();

  const dispatchOk = check(dispatchRes, {
    'bulk500: dispecerizarea redirecționează spre bulk.show': (r) => isBulkOperationRedirect(r),
  });

  if (!dispatchOk) {
    fail(`bulk500: dispecerizarea a eșuat: ${dispatchRes.status} ${dispatchRes.body}`);
  }

  const operationUrl = dispatchRes.headers.Location;
  const result = pollBulkOperation(operationUrl, version, { scenario: 'bulk500' }, dispatchedAt);

  check(result, { 'bulk500: stare finală "completed"': (r) => r.finalOp && r.finalOp.status === 'completed' });

  totalDurationMs.add(result.totalMs, { scenario: 'bulk500' });

  if (result.firstProgressMs !== null) {
    firstProgressMs.add(result.firstProgressMs, { scenario: 'bulk500' });
  }

  console.log(
    `bulk500: total_rows=${sourceTotal} primul_progres=${result.firstProgressMs}ms timp_total=${result.totalMs}ms ` +
      `stare_finală=${result.finalOp.status} processedJobs=${result.finalOp.processedJobs}/${result.finalOp.totalJobs} failedJobs=${result.finalOp.failedJobs}`,
  );
}

// ---------------------------------------------------------------------------------------
// Fază 20k — poll-erul operației dispecerizate în setup().
// ---------------------------------------------------------------------------------------
export function bulk20kPoll(data) {
  if (!data.operationUrl) {
    fail('bulk20kPoll: setup() nu a produs operationUrl — rulează cu -e PHASE=20k?');
  }

  // `setup()` are propriul borcan de cookie-uri, separat de acest VU (verificat — vezi
  // docblock-ul `login()`) — fără login aici, fiecare sondaj ar lovi un 302 spre /login,
  // `operationFromRes()` ar întoarce mereu `null`, iar bucla ar epuiza `MAX_POLLS` degeaba.
  // Găsit direct la prima rulare: `listDuringBulk` (care SE loghează) a văzut „completed"
  // în 6s; acest scenariu, fără login, tot aștepta la 5 minute.
  login('manager');

  const result = pollBulkOperation(data.operationUrl, data.version, { scenario: 'bulk20k' }, data.dispatchedAt);

  check(result, { 'bulk20k: stare finală "completed"': (r) => r.finalOp && r.finalOp.status === 'completed' });

  totalDurationMs.add(result.totalMs, { scenario: 'bulk20k' });

  if (result.firstProgressMs !== null) {
    firstProgressMs.add(result.firstProgressMs, { scenario: 'bulk20k' });
  }

  console.log(
    `bulk20k: total_rows=${data.totalRows} primul_progres=${result.firstProgressMs}ms timp_total=${result.totalMs}ms ` +
      `stare_finală=${result.finalOp.status} processedJobs=${result.finalOp.processedJobs}/${result.finalOp.totalJobs} failedJobs=${result.finalOp.failedJobs}`,
  );
}

/**
 * §20.1 „interfața rămâne interactivă" — 5 VU separate, fiecare cu login propriu, cerând
 * `Orders/Index` (vederea implicită) cât timp operația din `setup()` rulează. Fiecare VU
 * decide singur când să se oprească, sondând ACELAȘI endpoint de status ca poll-erul
 * principal — nicio stare JS distribuită între VU-uri (k6 le izolează), sursa de adevăr e
 * mereu serverul.
 */
export function listDuringBulk(data) {
  if (!data.operationUrl) {
    fail('listDuringBulk: setup() nu a produs operationUrl — rulează cu -e PHASE=20k?');
  }

  login('manager');

  const shell = fetchShell(`${BASE}/${WORKSPACE}/orders`, { phase: '20k', part: 'probe_shell' });

  if (shell.res.status !== 200 || shell.page === null) {
    fail(`listDuringBulk (VU ${__VU}): shell invalid (${shell.res.status}).`);
  }

  const page = { version: shell.page.version, component: shell.page.component };
  let requests = 0;

  for (let i = 0; i < 2000; i++) {
    const res = partialReload(`${BASE}/${WORKSPACE}/orders`, page, 'orders', { list: 'orders', phase: 'during_bulk20k' });
    const payload = listPayload(res, 'orders');

    listDuringBulkLatency.add(res.timings.duration);
    check(res, { 'listDuringBulk: vedere implicită 200 cu rânduri': (r) => r.status === 200 && payload !== null });
    requests++;

    sleep(0.5); // mai frecvent decât UI-ul (2s) — mai multe eșantioane într-o fereastră scurtă

    const statusRes = inertiaGet(data.operationUrl, data.version, { phase: '20k', name: 'probe_status_check' });
    const op = operationFromRes(statusRes);

    if (op === null || TERMINAL_STATUSES.includes(op.status)) {
      console.log(`listDuringBulk (VU ${__VU}): operația e ${op ? op.status : 'necunoscută'} — opresc sondarea după ${requests} cereri de listă.`);
      break;
    }
  }
}

// ---------------------------------------------------------------------------------------
// Fază cancel — operație mare (toate comenzile Marlin), anulată la ~50%, verificată prin
// API: stare `cancelled`, un număr plauzibil de rânduri atinse, restul intact.
// ---------------------------------------------------------------------------------------
export function cancelHalfway() {
  login('manager');

  const shell = fetchShell(`${BASE}/${WORKSPACE}/orders`, { phase: 'cancel', part: 'shell' });

  if (shell.res.status !== 200 || shell.page === null || shell.page.component !== 'Orders/Index') {
    fail(`cancelHalfway: ${shell.res.status} fără pagina "Orders/Index" (${shell.res.url}).`);
  }

  const version = shell.page.version;
  const owners = shell.page.props.owners;

  if (!Array.isArray(owners) || owners.length < 2) {
    fail('cancelHalfway: props.owners are mai puțin de 2 membri activi.');
  }

  // Aleg ținta cu CELE MAI PUȚINE comenzi proprii deja — minimizează zgomotul din
  // verificarea de coerență de mai jos (rânduri deja pe ținta, dinaintea operației, pe
  // care reasignarea idempotentă le-ar „sări" fără să le numere ca „nou atinse").
  console.log('cancelHalfway: caut ținta cu cele mai puține comenzi proprii…');
  let target = null;
  let targetBefore = null;

  for (const owner of owners) {
    const count = fetchTotal(`${BASE}/${WORKSPACE}/orders${filterQuery({ owner: owner.id })}`, shell.page, {
      phase: 'cancel',
      probe: 'owner_total',
    });
    console.log(`  ${owner.name} (${owner.id}): ${count === null ? 'eroare la măsurare' : `${count} comenzi`}`);

    if (count !== null && (targetBefore === null || count < targetBefore)) {
      target = owner;
      targetBefore = count;
    }
  }

  if (target === null) {
    fail('cancelHalfway: nu am putut măsura comenzile niciunui owner activ.');
  }

  const totalRows = fetchTotal(`${BASE}/${WORKSPACE}/orders`, shell.page, { phase: 'cancel', probe: 'total' });

  if (totalRows === null || totalRows < 1000) {
    fail(`cancelHalfway: total_rows=${totalRows} — prea mic ca să observăm fiabil o trecere de ~50% cu sondaj la 2s.`);
  }

  console.log(`cancelHalfway: total_rows=${totalRows}, țintă=${target.name} (${target.id}), deține deja ${targetBefore}.`);

  const dispatchRes = inertiaPostJson(
    `${BASE}/${WORKSPACE}/orders/bulk/reassign-owner`,
    version,
    { selectAllMatching: true, ids: [], owner_user_id: target.id, confirmed: true },
    { phase: 'cancel', name: 'dispatch' },
  );
  dispatchLatency.add(dispatchRes.timings.duration, { scenario: 'cancel' });

  const dispatchOk = check(dispatchRes, {
    'cancelHalfway: dispecerizarea redirecționează spre bulk.show': (r) => isBulkOperationRedirect(r),
  });

  if (!dispatchOk) {
    fail(`cancelHalfway: dispecerizarea a eșuat: ${dispatchRes.status} ${dispatchRes.body}`);
  }

  const operationUrl = dispatchRes.headers.Location;

  // Sondaj propriu, DELIBERAT mai frecvent decât cele 2s ale UI-ului (`CANCEL_POLL_SECONDS`,
  // implicit 100ms), doar până trecem de ~50% — NU `pollBulkOperation()` (acela merge până
  // la starea terminală, la 2s, ca UI-ul). Măsurat pe acest stack: workerul, deja cald
  // (OPcache, conexiuni ținute), toacă un chunk în ~20-100ms — 30.000 de rânduri/60 chunk-uri
  // se termină în ~5-6s. La 2s/sondaj (ca UI-ul), primul sondaj ar prinde adesea operația
  // deja terminată, nu la jumătate — sondajul de DETECTARE trebuie să fie mai fin decât
  // fereastra pe care vrea s-o nimerească, distinct de sondajul de AFIȘARE din UI.
  const CANCEL_POLL_SECONDS = Number(__ENV.CANCEL_POLL_SECONDS || 0.1);
  const CANCEL_DETECT_MAX_SECONDS = 120;
  const maxDetectIterations = Math.ceil(CANCEL_DETECT_MAX_SECONDS / CANCEL_POLL_SECONDS);
  let op = null;
  let crossedHalfway = false;

  for (let i = 0; i < maxDetectIterations && !crossedHalfway; i++) {
    sleep(CANCEL_POLL_SECONDS);

    const res = inertiaGet(operationUrl, version, { phase: 'cancel', name: 'status_poll_before_cancel', scenario: 'cancel' });
    op = operationFromRes(res);

    if (op === null) {
      continue;
    }

    if (op.totalJobs > 0 && op.processedJobs / op.totalJobs >= 0.5) {
      crossedHalfway = true;
    }

    if (TERMINAL_STATUSES.includes(op.status)) {
      console.warn(`cancelHalfway: operația s-a terminat singură (după ${i + 1} sondaje la ${CANCEL_POLL_SECONDS}s) înainte să apuc s-o anulez la ~50%.`);
      crossedHalfway = true;
    }
  }

  if (op === null || op.totalJobs === 0) {
    fail('cancelHalfway: n-am reușit să citesc progresul operației înainte de a decide anularea.');
  }

  console.log(`cancelHalfway: trimit "Cancel" la processedJobs=${op.processedJobs}/${op.totalJobs} (${Math.round((100 * op.processedJobs) / op.totalJobs)}%).`);

  const cancelRes = inertiaPostJson(`${operationUrl}/cancel`, version, {}, { phase: 'cancel', name: 'cancel', scenario: 'cancel' });
  cancelLatency.add(cancelRes.timings.duration, { scenario: 'cancel' });
  check(cancelRes, { 'cancelHalfway: "Cancel" redirecționează': (r) => isRedirect(r) });

  const result = pollBulkOperation(operationUrl, version, { scenario: 'cancel' });
  console.log(
    `cancelHalfway: stare finală=${result.finalOp.status}, timp_de_la_cancel_la_terminal=${result.totalMs}ms, ` +
      `processedJobs=${result.finalOp.processedJobs}/${result.finalOp.totalJobs} (drenarea joburilor deja în coadă continuă după Cancel — §13.2 pct. 7).`,
  );

  const targetAfter = fetchTotal(`${BASE}/${WORKSPACE}/orders${filterQuery({ owner: target.id })}`, shell.page, {
    phase: 'cancel',
    probe: 'owner_total_after',
  });
  const totalAfter = fetchTotal(`${BASE}/${WORKSPACE}/orders`, shell.page, { phase: 'cancel', probe: 'total_after' });
  const reassigned = targetAfter !== null && targetBefore !== null ? targetAfter - targetBefore : null;
  const ratio = reassigned !== null ? reassigned / totalRows : null;

  console.log(
    `cancelHalfway: reasignate≈${reassigned} din total_rows=${totalRows} (${ratio !== null ? (ratio * 100).toFixed(1) : '?'}%), ` +
      `total comenzi înainte=${totalRows} după=${totalAfter}.`,
  );

  // Coerență, verificată prin API (nu prin DB): operația s-a oprit efectiv la jumătate
  // (nici 0, nici tot), starea e „cancelled", iar numărul total de comenzi al tenantului
  // n-a variat (reasignarea schimbă owner_user_id, nu creează/șterge rânduri) — livrabilul
  // „0 rânduri corupte" din §9. Banda 20%-80%, nu exact 50%: `targetBefore` conținea deja
  // comenzi ale țintei ÎNAINTE de operație, distribuite aleator printre chunk-uri (seed-ul
  // alege owner-ul per comandă cu `array_rand`), deci `reassigned` subnumără ușor chunk-urile
  // real aplicate — o bandă largă absoarbe acest zgomot fără să ascundă o anulare care n-a
  // oprit nimic (ratio≈100%) sau care n-a pornit nimic (ratio≈0%).
  const coherent = check(null, {
    'cancelHalfway: stare finală "cancelled"': () => result.finalOp.status === 'cancelled',
    'cancelHalfway: cel puțin un rând reasignat': () => reassigned !== null && reassigned > 0,
    'cancelHalfway: nu s-a reasignat tot (anularea a oprit ceva)': () => reassigned !== null && reassigned < totalRows,
    'cancelHalfway: reasignarea e în banda plauzibilă 20%-80%': () => ratio !== null && ratio > 0.2 && ratio < 0.8,
    'cancelHalfway: numărul total de comenzi al tenantului e neschimbat': () => totalAfter === totalRows,
  });
  cancelCoherence.add(coherent);
}

// ---------------------------------------------------------------------------------------
// Fază agent — BR-BULK-02 (plafon dur 500) și FR-BULK-01 (prag de confirmare 125), pe
// Accounts (singura resursă pe care Agentul are `bulkReassignOwner` — vezi docblock-ul de
// sus). Verifică AMBELE praguri prin API, nu doar prin citirea codului.
// ---------------------------------------------------------------------------------------
export function agentLimits() {
  login('agent');

  const shell = fetchShell(`${BASE}/${WORKSPACE}/accounts`, { phase: 'agent', part: 'shell' });

  if (shell.res.status !== 200 || shell.page === null || shell.page.component !== 'Accounts/Index') {
    fail(`agentLimits: ${shell.res.status} fără pagina "Accounts/Index" (${shell.res.url}).`);
  }

  const version = shell.page.version;
  const authUserId = shell.page.props.auth.user.id;
  const owners = shell.page.props.owners;
  const dest = owners.find((o) => o.id !== authUserId) || owners[0];

  // NU hardcodate: `bulkRowCap`/`bulkConfirmationThreshold` vin direct în props-ul paginii
  // (`AccountController::index()`), aceleași valori pe care dialogul de confirmare din UI
  // le citește — `BulkConfirmationThreshold::rowCapForRole()`/`::for()`.
  const roleCap = shell.page.props.bulkRowCap;
  const confirmThreshold = shell.page.props.bulkConfirmationThreshold;
  console.log(`agentLimits: bulkRowCap=${roleCap} bulkConfirmationThreshold=${confirmThreshold} (din props, nu presupuse).`);

  // Filtrul implicit al Agentului e `owner=me` (server-side, `AccountList::defaultFilters`) —
  // fără niciun `filter[owner]` explicit, `total` e deja „conturile mele".
  const ownTotal = fetchTotal(`${BASE}/${WORKSPACE}/accounts`, shell.page, { phase: 'agent', probe: 'own_total' });
  console.log(`agentLimits: agentul deține ${ownTotal} conturi proprii (fără alt filtru).`);

  let capChecked = false;
  let capCoherent = true;

  if (ownTotal !== null && roleCap !== null && ownTotal > roleCap) {
    // BR-BULK-02 — plafonul e verificat ÎNAINTEA pragului de confirmare (§13.1), deci
    // `confirmed:true` NU trebuie să-l ocolească. O dispecerizare RESPINSĂ redirecționează
    // tot cu 302, dar ÎNAPOI spre lista de conturi (`isBulkOperationRedirect` deosebește
    // cele două — vezi docblock-ul ei; `expectsJson()` e fals pentru un `Accept` Inertia
    // explicit, deci nu există 422 JSON aici, contrar formei „API" obișnuite).
    const capRes = inertiaPostJson(
      `${BASE}/${WORKSPACE}/accounts/bulk/reassign-owner`,
      version,
      { selectAllMatching: true, ids: [], owner_user_id: dest.id, confirmed: true },
      { phase: 'agent', name: 'cap_attempt', scenario: 'agent' },
    );

    capChecked = true;
    const capRejectedCorrectly = !isBulkOperationRedirect(capRes) && isRedirect(capRes);
    const capMessage = capRejectedCorrectly ? readSelectionError(capRes.headers.Location, version, { phase: 'agent', name: 'cap_attempt_errors' }) : null;

    capCoherent = check(capRes, {
      'agentLimits: peste plafonul de 500 → respins, NU spre bulk.show': () => capRejectedCorrectly,
      'agentLimits: mesajul de refuz citează plafonul de rol': () => capMessage !== null && capMessage.includes("role's limit"),
    });

    console.log(`agentLimits: plafon=${roleCap} pe ${ownTotal} conturi — status=${capRes.status}, mesaj="${capMessage}", coerent=${capCoherent}.`);
  } else {
    console.warn(`agentLimits: agentul are doar ${ownTotal} conturi proprii — sub plafonul de ${roleCap}, nu pot demonstra BR-BULK-02 în această rulare (seed probabilistic).`);
  }

  // FR-BULK-01 — pragul de confirmare. Caut un filtru care aduce „conturile mele" într-o
  // bandă (confirmThreshold, roleCap] — sub plafon, dar peste prag.
  const candidates = [
    { label: 'implicit (fără alt filtru)', total: ownTotal, query: '' },
    { label: 'status=active', query: filterQuery({ status: 'active' }) },
    { label: 'status=prospect', query: filterQuery({ status: 'prospect' }) },
    { label: 'status=inactive', query: filterQuery({ status: 'inactive' }) },
  ];

  let chosen = null;

  for (const candidate of candidates) {
    const total = candidate.total !== undefined ? candidate.total : fetchTotal(`${BASE}/${WORKSPACE}/accounts${candidate.query}`, shell.page, { phase: 'agent', probe: candidate.label });
    console.log(`agentLimits: candidat "${candidate.label}": ${total === null ? 'eroare' : total} conturi.`);

    if (total !== null && total > confirmThreshold && total <= roleCap) {
      chosen = { ...candidate, total };
      break;
    }
  }

  let thresholdChecked = false;
  let thresholdCoherent = true;

  if (chosen !== null) {
    thresholdChecked = true;
    console.log(`agentLimits: folosesc "${chosen.label}" (${chosen.total} conturi) pentru pragul de confirmare.`);

    const unconfirmedRes = inertiaPostJson(
      `${BASE}/${WORKSPACE}/accounts/bulk/reassign-owner${chosen.query}`,
      version,
      { selectAllMatching: true, ids: [], owner_user_id: dest.id, confirmed: false },
      { phase: 'agent', name: 'threshold_unconfirmed', scenario: 'agent' },
    );

    const unconfirmedRejectedCorrectly = !isBulkOperationRedirect(unconfirmedRes) && isRedirect(unconfirmedRes);
    const unconfirmedMessage = unconfirmedRejectedCorrectly
      ? readSelectionError(unconfirmedRes.headers.Location, version, { phase: 'agent', name: 'threshold_unconfirmed_errors' })
      : null;

    const unconfirmedOk = check(unconfirmedRes, {
      'agentLimits: peste pragul de confirmare fără confirmed → respins, NU spre bulk.show': () => unconfirmedRejectedCorrectly,
      'agentLimits: mesajul de refuz citează nevoia de confirmare': () => unconfirmedMessage !== null && unconfirmedMessage.includes('needs confirmation'),
    });

    console.log(`agentLimits: prag=${confirmThreshold} pe ${chosen.total} conturi, fără confirmed — status=${unconfirmedRes.status}, mesaj="${unconfirmedMessage}".`);

    const confirmedRes = inertiaPostJson(
      `${BASE}/${WORKSPACE}/accounts/bulk/reassign-owner${chosen.query}`,
      version,
      { selectAllMatching: true, ids: [], owner_user_id: dest.id, confirmed: true },
      { phase: 'agent', name: 'threshold_confirmed', scenario: 'agent' },
    );
    dispatchLatency.add(confirmedRes.timings.duration, { scenario: 'agent' });
    const dispatchedAt = Date.now();

    const confirmedOk = check(confirmedRes, {
      'agentLimits: cu confirmed → dispecerizare reușită (spre bulk.show)': (r) => isBulkOperationRedirect(r),
    });

    thresholdCoherent = unconfirmedOk && confirmedOk;

    if (confirmedOk) {
      const operationUrl = confirmedRes.headers.Location;
      const result = pollBulkOperation(operationUrl, version, { scenario: 'agent' }, dispatchedAt);

      thresholdCoherent = thresholdCoherent && result.finalOp.status === 'completed';
      console.log(
        `agentLimits: operația confirmată a ajuns la "${result.finalOp.status}" (${result.finalOp.processedJobs}/${result.finalOp.totalJobs} joburi) în ${result.totalMs}ms.`,
      );
    } else {
      console.warn(`agentLimits: dispecerizarea confirmată a eșuat: ${confirmedRes.status} ${confirmedRes.body}`);
    }
  } else {
    console.warn(`agentLimits: niciun filtru n-a adus „conturile mele" în banda (${confirmThreshold}, ${roleCap}] — pragul de confirmare nu poate fi demonstrat în această rulare (seed probabilistic).`);
  }

  if (!capChecked && !thresholdChecked) {
    fail('agentLimits: nici plafonul, nici pragul de confirmare n-au putut fi exercitate — distribuția seed-ului pentru contul demo Agent e neașteptată; verifică manual.');
  }

  agentLimitCoherence.add(capCoherent && thresholdCoherent);
}

// ---------------------------------------------------------------------------------------
// Fază export (opțional, task §4) — CSV pe Orders, sub pragul sincron (5.000 rânduri,
// `export_sync_max_rows`) vs. peste el (job în coadă, `ExportListJob`).
// ---------------------------------------------------------------------------------------
export function exportCsv() {
  login('manager');

  const shell = fetchShell(`${BASE}/${WORKSPACE}/orders`, { phase: 'export', part: 'shell' });

  if (shell.res.status !== 200 || shell.page === null || shell.page.component !== 'Orders/Index') {
    fail(`exportCsv: ${shell.res.status} fără pagina "Orders/Index" (${shell.res.url}).`);
  }

  const version = shell.page.version;

  // Caut un filtru sub pragul sincron (5.000) — statusurile terminale („cancelled",
  // „draft") sunt de regulă subseturi mici pe orice tenant.
  const smallCandidates = ['cancelled', 'draft'];
  let smallStatus = null;
  let smallTotal = null;

  for (const status of smallCandidates) {
    const total = fetchTotal(`${BASE}/${WORKSPACE}/orders${filterQuery({ status })}`, shell.page, { phase: 'export', probe: `status_${status}` });
    console.log(`exportCsv: filter[status]=${status} → ${total === null ? 'eroare' : total} comenzi.`);

    if (total !== null && total > 0 && total <= 5000) {
      smallStatus = status;
      smallTotal = total;
      break;
    }
  }

  if (smallStatus !== null) {
    const syncRes = http.get(`${BASE}/${WORKSPACE}/orders/export${filterQuery({ status: smallStatus })}`, {
      headers: inertiaHeaders(version),
      tags: { phase: 'export', name: 'sync_csv' },
      redirects: 0,
    });
    exportSyncLatency.add(syncRes.timings.duration);
    check(syncRes, {
      'exportCsv: export sincron 200 text/csv': (r) => r.status === 200 && (r.headers['Content-Type'] || '').includes('text/csv'),
    });
    console.log(`exportCsv: export sincron (${smallTotal} rânduri, filter[status]=${smallStatus}) — ${syncRes.timings.duration.toFixed(0)}ms.`);
  } else {
    console.warn('exportCsv: niciun status n-a adus un subset (0, 5000] — sar peste testul sincron.');
  }

  // Peste prag: fără filtru, tot tenantul (~30.000 pe Marlin) — trebuie să treacă în coadă
  // (`ExportListJob`, `App\Support\Exports\ListExport::startQueuedExport()`).
  const asyncRes = http.get(`${BASE}/${WORKSPACE}/orders/export`, {
    headers: inertiaHeaders(version),
    tags: { phase: 'export', name: 'async_dispatch' },
    redirects: 0,
  });
  exportAsyncDispatchLatency.add(asyncRes.timings.duration);

  const asyncOk = check(asyncRes, {
    'exportCsv: peste prag → redirect spre exports.show': (r) => isRedirect(r) && typeof r.headers.Location === 'string' && r.headers.Location.includes('/exports/'),
  });

  if (!asyncOk) {
    fail(`exportCsv: exportul peste prag n-a redirecționat: ${asyncRes.status} ${asyncRes.body}`);
  }

  const exportUrl = asyncRes.headers.Location;
  console.log(`exportCsv: export în coadă pornit la ${exportUrl}.`);

  // `Exports/Show.tsx` face `usePoll(2000, {}, …)` la fel ca `Bulk/Show.tsx` — același
  // interval, resursă diferită (`export`, nu `operation`).
  let finalExport = null;

  for (let i = 0; i < MAX_POLLS && finalExport === null; i++) {
    sleep(2);

    const res = inertiaGet(exportUrl, version, { phase: 'export', name: 'status_poll' });
    statusPollLatency.add(res.timings.duration, { scenario: 'export' });
    const exp = exportFromRes(res);

    check(res, { 'exportCsv: sondaj de status 200 cu export': () => res.status === 200 && exp !== null });

    if (exp !== null && TERMINAL_STATUSES.includes(exp.status)) {
      finalExport = exp;
    }
  }

  if (finalExport === null) {
    fail(`exportCsv: exportul în coadă n-a ajuns la o stare terminală după ${MAX_POLLS} sondaje — workerul rulează (k6-stack.sh worker)?`);
  }

  check(finalExport, {
    'exportCsv: exportul în coadă s-a terminat "completed" și e descărcabil': (e) => e.status === 'completed' && e.canDownload === true,
  });

  console.log(`exportCsv: export în coadă (${finalExport.totalRows} rânduri) → stare finală "${finalExport.status}", canDownload=${finalExport.canDownload}.`);
}
