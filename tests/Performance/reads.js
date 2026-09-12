// k6 — măsurătoarea de citire din plan §7.10, cu pragurile din §20.1 din specs.md.
//
// Rulat LOCAL (`k6 run tests/Performance/reads.js`), NU în CI: cota de minute GitHub
// Actions e comună cu celelalte 11 proiecte din portofoliu (§5.1), iar pragurile nu au
// nevoie de un runner ca să fie adevărate.
//
// Pragurile stau în `options.thresholds`, deci scriptul iese singur cu cod de eroare —
// nu depinde de cine citește raportul. Dacă un prag nu trece, remediul se decide pe
// `EXPLAIN ANALYZE` (`php artisan db:explain-critical`), nu prin relaxarea pragului.

import http from 'k6/http';
import { check, group, fail } from 'k6';
import { Trend } from 'k6/metrics';

const BASE = __ENV.BASE_URL || 'http://localhost:8000';
const WORKSPACE = __ENV.WORKSPACE || 'marlin'; // tenantul vitrină, ~30.000 de comenzi (§21.1)
const ROLE = __ENV.ROLE || 'manager'; // acces operațional complet, fără îngustarea de Agent

// Cele patru liste din §20.1, fiecare cu vederea implicită și cu cel mai rău caz.
// `dashboard` e al cincilea grup, singurul care există deja în Faza 1: listele propriu-zise
// apar în Fazele 2-3, iar `setup()` de mai jos spune explicit când lipsesc.
const GROUPS = [
  { name: 'dashboard', default: '/dashboard', worst: '/dashboard' },
  { name: 'accounts', default: '/accounts', worst: '/accounts?sort=-created_at&cursor=deep&status=active' },
  { name: 'contacts', default: '/contacts', worst: '/contacts?sort=-created_at&cursor=deep' },
  { name: 'deals', default: '/deals', worst: '/deals?sort=-value&cursor=deep&status=open' },
  { name: 'orders', default: '/orders', worst: '/orders?sort=-created_at&cursor=deep&status=fulfilled' },
];

const defaultView = new Trend('view_default', true);
const worstCase = new Trend('view_worst_case', true);

export const options = {
  scenarios: {
    reads: { executor: 'constant-vus', vus: 5, duration: '30s' },
  },
  thresholds: {
    // Rândul 1 din §20.1: citiri simple, vedere implicită.
    'view_default{group:dashboard}': ['p(95)<200'],
    'view_default{group:accounts}': ['p(95)<200'],
    'view_default{group:contacts}': ['p(95)<200'],
    'view_default{group:deals}': ['p(95)<200'],
    'view_default{group:orders}': ['p(95)<200'],
    // Rândul 2: cel mai rău caz — cursor adânc + filtru pe cea mai mare tabelă a tenantului.
    'view_worst_case{group:dashboard}': ['p(95)<500'],
    'view_worst_case{group:accounts}': ['p(95)<500'],
    'view_worst_case{group:contacts}': ['p(95)<500'],
    'view_worst_case{group:deals}': ['p(95)<500'],
    'view_worst_case{group:orders}': ['p(95)<500'],
    // O măsurătoare cu 4xx/5xx în ea nu e o măsurătoare, e o iluzie optică.
    checks: ['rate==1.0'],
  },
};

function login() {
  // Pagina de login pune cookie-urile de sesiune și XSRF în borcanul lui k6.
  const page = http.get(`${BASE}/login`);
  check(page, { 'login page e 200': (r) => r.status === 200 });

  const jar = http.cookieJar();
  const xsrf = (jar.cookiesForURL(BASE)['XSRF-TOKEN'] || [])[0];

  if (!xsrf) {
    fail('Lipsește cookie-ul XSRF-TOKEN — aplicația rulează pe BASE_URL corect?');
  }

  // FR-PUB-02: autentificare instant prin contul demo, fără parolă (DEMO_MODE=true).
  const res = http.post(`${BASE}/login/demo/${ROLE}`, null, {
    headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf), Accept: 'text/html' },
    redirects: 0,
  });

  check(res, { 'login demo redirectează': (r) => r.status === 302 });
}

export function setup() {
  login();

  const missing = GROUPS.filter((g) => http.get(`${BASE}/${WORKSPACE}${g.default}`).status === 404).map((g) => g.name);

  if (missing.length > 0) {
    // Mesaj util, nu prăbușire criptică: în Faza 1 doar dashboard-ul există.
    fail(
      `Endpoint-uri inexistente încă: ${missing.join(', ')}. ` +
        'Listele apar în Fazele 2-3; rulează scriptul cu doar grupurile disponibile ' +
        '(ex. GROUPS filtrat) sau după ce ecranele există.'
    );
  }
}

export default function () {
  login();

  for (const g of GROUPS) {
    group(g.name, () => {
      const tags = { group: g.name };

      const first = http.get(`${BASE}/${WORKSPACE}${g.default}`, { tags });
      check(first, { 'vedere implicită 200': (r) => r.status === 200 });
      defaultView.add(first.timings.duration, tags);

      const deep = http.get(`${BASE}/${WORKSPACE}${g.worst}`, { tags });
      check(deep, { 'cel mai rău caz 200': (r) => r.status === 200 });
      worstCase.add(deep.timings.duration, tags);
    });
  }
}
