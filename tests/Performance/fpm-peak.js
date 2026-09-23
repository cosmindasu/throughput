// k6 — OPS-07 (audit infra 2026-09-23, docs/reviews/2026-09-23_audit/07-infra-deploy.md):
// bugetul agregat PHP-FPM (`pm.max_children=4` × `memory_limit` implicit 128M = 512 MB
// TEORETIC) depășește `mem_limit`=`memswap_limit` (256m) al containerului `app`. Niciodată
// măsurat la vârf real. Întrebarea la care răspunde ACEST script: 4 (apoi 8) cereri
// simultane, susținute, pe cel mai greu endpoint SINCRON al aplicației — se atinge
// OOM-kill? Cât RSS, ce marjă?
//
// De ce exportul CSV sincron (Orders), NU un raport/PDF/pagina unei comenzi:
//   - PDF/XLSX (`export_pdf_max_rows`/`export_xlsx_max_rows`, config/throughput.php) sunt
//     MEREU în coadă (ADR-013 — DomPDF/PhpSpreadsheet materializează tot în memorie, cost
//     care nu are ce căuta în tranzacția cererii) — rulează pe `horizon` (384m), NU pe
//     `app`. Nu exercită deloc plafonul din OPS-07.
//   - Rapoartele built-in (`ReportController::builtInPreview()`) sunt agregări ieftine,
//     măsurate la 3-6ms (comentariul din cod), plafonate la 200 rânduri AFIȘATE — nu scalează.
//   - Dashboard-ul e patru `COUNT`/`SUM` — memorie constantă, indiferent de volum.
//   - Pagina unei comenzi (`OrderController::show()`) încarcă liniile UNEI comenzi — bounded
//     de câte linii are o comandă reală (zeci, nu mii), fără cap configurabil de scalat.
//   - Exportul CSV (`ListExport::respond()`) e SINGURUL loc din aplicație unde o cerere
//     HTTP sincronă, pe `app` (php-fpm, 4 copii, 128M implicit fiecare), procesează un
//     număr de rânduri CONFIGURABIL și SCALABIL — până la `export_sync_max_rows`
//     (implicit 5.000, `.env.example`). Peste prag trece în coadă; sub prag,
//     `CsvExporter::toString()` construiește tot fișierul în cererea curentă, pe worker-ul
//     FPM care a preluat-o. E exact scenariul teoretic din OPS-07: „un endpoint care ar
//     prelucra sincron un fișier mare".
//
// Cazul-limită cerut de audit („forțează maximul permis de rânduri pe exportul sincron")
// ESTE scenariul susținut: `setup()` caută, prin bisecție pe filtrul `to` (interval de
// dată, `OrderList::applyFilters()`), cel mai mare subset de comenzi Marlin care rămâne
// SUB `export_sync_max_rows` — deci fiecare cerere din cele 4 (sau 8) VU-uri lovește
// pragul dinspre interior, nu o mostră arbitrară. Comenzile nu se scriu în timpul
// testului (fără worker de coadă pornit în acest stack — vezi docker-compose.peak.yml),
// deci `total` rămâne stabil pe toată durata rulării.
//
// Măsurătoarea de memorie NU stă în acest script: k6 nu poate citi RSS-ul containerului.
// Rulează ÎN PARALEL, din shell, cât timp k6 rulează (`fpm-peak-monitor.sh` le face pe toate):
//   docker stats --no-stream --format '{{.Container}}\t{{.MemUsage}}\t{{.MemPerc}}' throughput-peak-app-1
//   watch -n1 -- cat /proc/$(docker exec throughput-peak-app-1 pgrep -o php-fpm)/status  # per-worker VmHWM
//   docker inspect -f '{{.State.OOMKilled}}' throughput-peak-app-1   # DUPĂ rulare
//
// Unde rulează: pe imaginea de PRODUCȚIE, stack izolat `tests/Performance/docker-compose.peak.yml`
// (`-p throughput-peak`), NU containerele `throughputdbgro-*` de dezvoltare.
//
//   BASE_URL=http://127.0.0.1:8099 k6 run -e VUS=4 tests/Performance/fpm-peak.js
//   BASE_URL=http://127.0.0.1:8099 k6 run -e VUS=8 tests/Performance/fpm-peak.js   # supra-saturare
//
// Pragurile din `options.thresholds` sunt tripwire-uri de sanitate (ceva e vizibil rupt),
// NU un SLO formal — acest endpoint, la volumul-limită, nu are un prag de latență în
// specs.md/§20.1 (e un caz-limită de infrastructură, nu o interacțiune de utilizator).

import http from 'k6/http';
import { check, fail } from 'k6';
import { Trend } from 'k6/metrics';

const BASE = __ENV.BASE_URL || 'http://127.0.0.1:8099';
const WORKSPACE = __ENV.WORKSPACE || 'marlin'; // tenantul vitrină, cel mai mare volum (§21.1)
const VUS = Number(__ENV.VUS || 4); // implicit = pm.max_children (docker/app/php-fpm-pool.conf)
const DURATION = __ENV.DURATION || '3m';

// Implicitul din `.env.example`/`config/throughput.php` — NU citit din server (props-ul
// paginii nu expune `export_sync_max_rows`, doar plafoanele de bulk); dacă stack-ul de test
// suprascrie `EXPORT_SYNC_MAX_ROWS`, pasează aceeași valoare aici cu `-e EXPORT_SYNC_CAP=`.
const EXPORT_SYNC_CAP = Number(__ENV.EXPORT_SYNC_CAP || 5000);

// Plafonul bisecției pe zile — istoricul semănat e de 24 de luni (~730 zile); 1500 lasă
// marjă fără să crească nejustificat numărul de runde (bisecția e O(log N) oricum).
const MAX_HISTORY_DAYS = Number(__ENV.MAX_HISTORY_DAYS || 1500);

const exportSyncDuration = new Trend('export_sync_duration', true);
const exportSyncBytes = new Trend('export_sync_bytes');

export const options = {
  setupTimeout: '5m',
  noCookiesReset: true,
  scenarios: {
    peak: { executor: 'constant-vus', vus: VUS, duration: DURATION },
  },
  summaryTrendStats: ['avg', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
  thresholds: {
    // Tripwire de sanitate, nu SLO (vezi docblock-ul de sus): la 8 VU peste
    // `pm.max_children=4`, cererile se pun la coadă în FPM — mai lente, nu eșuate. Un
    // eșec real (500/timeout) înseamnă că OPS-07 chiar s-a materializat.
    checks: ['rate>0.99'],
    http_req_failed: ['rate<0.02'],
    export_sync_duration: ['p(95)<30000'],
  },
};

const PAGE_OBJECT = /<script data-page="app" type="application\/json">([\s\S]*?)<\/script>/;

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
    redirects: 0,
  });
}

/** Contorul „Select all N" (`BulkMatchingRowCount`) — pentru Manager (rol nerestricționat),
 * `App\Support\Bulk\BulkMatchingRowCount::for()` face `(clone $query)->toBase()
 * ->getCountForPagination()` FĂRĂ nicio scopare suplimentară (`Permissions::
 * restrictedToOwnRecords()` e fals) — EXACT ce face `ListExport::respond()` pentru decizia
 * sincron/coadă. Verificat citind ambele clase — nu presupus. Deci `total` de aici == N-ul
 * pe care exportul îl vede, atât timp cât rulăm ca Manager (nu Agent). */
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

function login() {
  const page = http.get(`${BASE}/login`);
  check(page, { 'login page e 200': (r) => r.status === 200 });

  const jar = http.cookieJar();
  const xsrf = (jar.cookiesForURL(BASE)['XSRF-TOKEN'] || [])[0];

  if (!xsrf) {
    fail('Lipsește cookie-ul XSRF-TOKEN — aplicația rulează pe BASE_URL corect? (pe HTTP, SESSION_SECURE_COOKIE=false)');
  }

  // FR-PUB-02: autentificare instant prin contul demo (DEMO_MODE=true în stack-ul de test).
  const res = http.post(`${BASE}/login/demo/manager`, null, {
    headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf), Accept: 'text/html' },
    redirects: 0,
  });

  check(res, { 'login demo redirectează': (r) => r.status === 302 });
}

function toDateString(daysAgo) {
  const date = new Date(Date.now() - daysAgo * 86400000);

  return date.toISOString().slice(0, 10);
}

/**
 * Bisecție pe `filter[to]` (interval de dată, `OrderList::applyFilters()`) — găsește cea
 * mai RECENTĂ dată-limită (deci cel mai mare subset de comenzi) pentru care `total` rămâne
 * SUB `EXPORT_SYNC_CAP`. `total` e monoton DESCRESCĂTOR pe măsură ce `daysAgo` crește
 * (fereastra se îngustează spre trecut) — bisecție standard pe predicat monoton, ~11 runde
 * pentru `MAX_HISTORY_DAYS=1500` (log2(1500) ≈ 10.6).
 *
 * De ce `to`, nu `owner`/`status`: sunt filtre cu O SINGURĂ valoare exactă
 * (`OrderList::filterKeys()` — niciun filtru nu acceptă listă/IN), deci subseturile lor
 * sunt fixe (proprietatea/statusul unui owner anume) — nu pot fi ajustate fin spre prag.
 * Un interval de dată se poate îngusta/lărgi CONTINUU, deci converge exact sub plafon,
 * indiferent de cum a distribuit seed-ul owner/status.
 */
function findCutoffNearCap(shellUrl, page, cap) {
  const totalAt = (daysAgo) => {
    const url = `${shellUrl}&filter%5Bto%5D=${toDateString(daysAgo)}`;

    return fetchTotal(url, page, { probe: 'bisection_to' });
  };

  const grandTotal = totalAt(0);

  if (grandTotal === null) {
    fail('findCutoffNearCap: nu pot citi "total" pe Orders/Index — sesiune pierdută sau prop-ul nu mai e deferred?');
  }

  console.log(`findCutoffNearCap: total NEFILTRAT pe ${WORKSPACE} = ${grandTotal} comenzi (cap=${cap}).`);

  if (grandTotal <= cap) {
    // Tenant mai mic decât pragul sincron — nu are sens niciun filtru, exportul complet
    // e deja cazul-limită cel mai apropiat de cap pe care seed-ul îl poate produce.
    console.warn(`findCutoffNearCap: total nefiltrat (${grandTotal}) e deja sub cap — folosesc exportul NEFILTRAT.`);

    return { filterQuery: '', total: grandTotal };
  }

  let lo = 0; // total(lo) > cap (verificat mai jos)
  let hi = MAX_HISTORY_DAYS; // presupus total(hi) <= cap — verificat, nu presupus orb

  const totalAtHi = totalAt(hi);

  if (totalAtHi === null || totalAtHi > cap) {
    fail(
      `findCutoffNearCap: chiar la ${hi} zile în urmă, total=${totalAtHi} — tot peste cap=${cap}. ` +
        'Istoricul seed-ului e mai lung decât MAX_HISTORY_DAYS sau tenantul are un volum neașteptat; ' +
        'rulează cu -e MAX_HISTORY_DAYS mai mare.',
    );
  }

  let iterations = 0;

  while (hi - lo > 1) {
    iterations += 1;
    const mid = Math.floor((lo + hi) / 2);
    const total = totalAt(mid);

    if (total === null) {
      fail(`findCutoffNearCap: sondaj eșuat la ${mid} zile în urmă (iterația ${iterations}).`);
    }

    if (total <= cap) {
      hi = mid;
    } else {
      lo = mid;
    }
  }

  const finalTotal = totalAt(hi);
  console.log(
    `findCutoffNearCap: convergență în ${iterations} runde — filter[to]=${toDateString(hi)} ` +
      `(${hi} zile în urmă) → ${finalTotal} comenzi (${((finalTotal / cap) * 100).toFixed(1)}% din cap=${cap}).`,
  );

  return { filterQuery: `&filter%5Bto%5D=${toDateString(hi)}`, total: finalTotal };
}

export function setup() {
  login();

  const shell = http.get(`${BASE}/${WORKSPACE}/orders`);
  const page = readPage(shell);

  if (shell.status !== 200 || page === null || page.component !== 'Orders/Index') {
    fail(`setup: ${shell.status} fără pagina "Orders/Index" (${shell.url}) — login eșuat sau WORKSPACE greșit?`);
  }

  const shellUrl = `${BASE}/${WORKSPACE}/orders?_probe=1`; // `?` propriu, ca bisecția să poată adăuga `&filter[to]=`
  const { filterQuery, total } = findCutoffNearCap(shellUrl, page, EXPORT_SYNC_CAP);
  const exportUrl = `${BASE}/${WORKSPACE}/orders/export?${filterQuery.replace(/^&/, '')}`;

  // Verificare de adevăr ÎNAINTE de a porni VU-urile: dacă asta nu e un CSV 200, tot
  // scenariul de mai jos ar măsura altceva (o coadă goală, un refuz) — mai bine `fail()`
  // aici decât 4 (sau 8) VU-uri raportând „succes" pe calea greșită timp de 3-5 minute.
  const verify = http.get(exportUrl, { tags: { name: 'export_sync_orders_near_cap', part: 'setup_verify' } });
  const contentType = verify.headers['Content-Type'] || '';
  const rows = typeof verify.body === 'string' ? verify.body.split('\n').filter((l) => l.length > 0).length - 1 : null;

  if (verify.status !== 200 || !contentType.includes('text/csv')) {
    fail(
      `setup: verificarea exportului n-a luat calea SINCRONĂ — status=${verify.status}, ` +
        `Content-Type="${contentType}", Location="${verify.headers.Location || ''}". ` +
        `total=${total}, cap=${EXPORT_SYNC_CAP} — filtrul găsit prin bisecție ar trebui să fie SUB cap.`,
    );
  }

  console.log(
    `setup: export sincron confirmat — ${rows} rânduri CSV, ${verify.body.length} B, ` +
      `${verify.timings.duration.toFixed(0)}ms (o singură cerere, cald/rece necunoscut încă).`,
  );

  return { exportUrl, total, rows, cap: EXPORT_SYNC_CAP };
}

let loggedIn = false;

export default function (data) {
  if (!loggedIn) {
    login();
    loggedIn = true;
  }

  const res = http.get(data.exportUrl, { tags: { name: 'export_sync_orders_near_cap' } });

  const ok = check(res, {
    'export sincron 200 text/csv': (r) => r.status === 200 && (r.headers['Content-Type'] || '').includes('text/csv'),
  });

  exportSyncDuration.add(res.timings.duration);

  if (ok) {
    exportSyncBytes.add(res.body.length);
  }
}

export function teardown(data) {
  console.log(
    `teardown: scenariul a lovit exportul sincron la ${data.rows} rânduri (${data.total} măsurate, ` +
      `cap=${data.cap}) — vezi \`docker stats\`/\`VmHWM\` rulate în paralel pentru RSS-ul containerului \`app\`.`,
  );
}
