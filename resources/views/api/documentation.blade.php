<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- FR-PUB-04 — jumătatea de meta tag; antetul `X-Robots-Tag` vine din
         `NoIndexHeaders`, aplicat acum și pe grupul `api` (`bootstrap/app.php`). --}}
    <meta name="robots" content="noindex, nofollow">
    <title>Throughput API — v1</title>
    @vite(['resources/js/swagger.ts'])
</head>
<body>
    {{-- URL-ul documentului ajunge în JS printr-un atribut `data-`, nu printr-un
         `<script>` inline: CSP-ul e `script-src 'self'`, fără `'unsafe-inline'`. --}}
    <div id="swagger-ui" data-spec-url="{{ route('api.documentation.spec') }}"></div>
</body>
</html>
