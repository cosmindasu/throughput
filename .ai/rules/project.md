# Constrângeri de proiect

## Versiunea de PHP: runtime-ul e 8.3, nu 8.4

Îndrumările generate de Boost spun „running on PHP 8.4" — a citit **PHP-ul local al mașinii de
dezvoltare**. Runtime-ul real, în container și în producție, e **PHP 8.3**, iar
`composer.json` are `config.platform.php = 8.3` exact ca rezolvarea de dependențe să nu aleagă
pachete care cer 8.4.

**Nu folosi sintaxă sau funcții introduse în 8.4** (property hooks, `new` fără paranteze în
înlănțuire, clase abstracte cu proprietăți asimetrice etc.). Ar trece local și ar cădea la
build-ul imaginii.

Capcană deja plătită: pinul de platformă a fost pus **după** scaffold, iar `composer.lock`
conținea deja `symfony/console` cu `php >=8.4.1`. Primul `composer require` a eșuat. Ordinea e
`composer config platform.php 8.3` → `composer update` → abia apoi pachete.

## Buget de memorie: 250-400 MB la vârf

Proiectul intră pe un VPS partajat cu alte 11 proiecte, care avea 11 ucideri OOM măsurate.
`mem_limit` = `memswap_limit` pe fiecare serviciu, din `docker-compose.coolify.yml`.

Consecințe practice: fără proces permanent care ține heap, fără Meilisearch, fără Reverb în
MVP, un singur worker de coadă, `traces_sample_rate` mic la Sentry. Dacă o soluție cere „încă
un serviciu", răspunsul implicit e nu — argumentează în ADR, nu în cod.

## Minutele de GitHub Actions sunt cotă comună

Cota e împărțită cu celelalte 11 proiecte. **Nu se face push ca să vezi dacă trece CI.**
Verifică local exact ce rulează `.github/workflows/ci.yml`: `pint --test`, `pest`, Spectral pe
`openapi/throughput-v1.yaml`, `composer audit --no-dev --locked`, `tsc --noEmit`,
`eslint resources/js`, `npm run build`.

## Niciun apel extern în cererea HTTP

Regulă absolută din [ADR-013](../../docs/adr/ADR-013-apeluri-externe-in-cozi.md): middleware-ul
de context ține o tranzacție deschisă pe toată durata cererii, deci un apel de curierat sau un
Chromium de câteva secunde ține o tranzacție Postgres deschisă pe un container cu
`max_connections=30`. Dacă o acțiune atinge altceva decât baza proprie, acțiunea aparține unei
cozi. Încălcarea **nu dă eroare** — dă tranzacții lungi care se văd abia sub concurență.

Corolar: fiecare `dispatch()` se întâmplă într-o tranzacție deschisă, deci
`'after_commit' => true` pe conexiunea Redis e obligatoriu.

## Documentație

Nu crea fișiere de documentație în acest repo fără să ți se ceară. Cerințele se modifică în
`specs_si_design/`, un nivel mai sus, cu intrare în Change Log. O decizie arhitecturală nouă
se scrie ca ADR în `docs/adr/`; **un ADR acceptat nu se rescrie** — se scrie unul care îl
supersedează.
