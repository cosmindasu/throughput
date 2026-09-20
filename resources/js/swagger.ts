import SwaggerUIBundle from 'swagger-ui-dist/swagger-ui-es-bundle.js';
import 'swagger-ui-dist/swagger-ui.css';

/**
 * FR-API-04 — pagina de contract publicat (`GET /api/documentation`).
 *
 * Punct de intrare Vite SEPARAT de `app.tsx`: singura pagină din aplicație care nu e
 * Inertia + React, iar bundle-ul Swagger UI (~1 MB) n-are ce căuta în cel al consolei,
 * pe care îl încarcă fiecare utilizator la fiecare sesiune.
 *
 * DE CE self-hosted, și nu de pe un CDN, deși varianta CDN era gata scrisă: CSP-ul
 * proiectului e `script-src 'self'`, fără nicio excepție (`App\Http\Middleware\
 * SecurityHeaders`). Ruta veche trăia pe grupul `api`, unde acel middleware nu e aplicat,
 * deci era SINGURA pagină HTML din aplicație fără CSP — de asta scriptul străin se
 * încărca. Riscul nu era furtul cookie-ului (`http_only` îl apără de citire), ci faptul
 * că browserul îl TRIMITE: un script compromis pe originea noastră face `fetch` same-site,
 * citește tokenul CSRF dintr-o pagină a aplicației și acționează ca vizitatorul logat —
 * iar demo-ul e public, cu scriere reală, deci vizitatorul chiar e logat.
 *
 * URL-ul documentului nu e interpolat într-un `<script>` inline (CSP l-ar bloca, corect):
 * vine dintr-un atribut `data-` pus de Blade pe containerul de montare.
 */
const container = document.getElementById('swagger-ui');
const specUrl = container?.dataset.specUrl;

if (container && specUrl !== undefined) {
    SwaggerUIBundle({
        url: specUrl,
        domNode: container,
        deepLinking: true,
        // §22 — „Try it out" rămâne activ: e chiar demonstrația. Niciun jeton
        // preîncărcat; cititorul îl lipește pe al lui, dintr-un workspace al lui.
        tryItOutEnabled: true,
    });
}
