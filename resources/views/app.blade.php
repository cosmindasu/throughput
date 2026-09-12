@php
    // FR-PREF-03 — fără licărire de temă: clasa se randează direct pe <html>
    // la primul răspuns, citită din cookie-ul `theme` server-side. Implicit:
    // tema închisă. Un useEffect care aplică tema după hidratare ar produce
    // exact licărirea pe care cerința o interzice — de asta nu se face acolo.
    $theme = request()->cookie('theme') === 'light' ? 'light' : 'dark';

    // Preload pe cele două fețe din primul paint (urls.md, „Tipografie"): Sans 400
    // pentru tot textul, Mono 400 pentru cifre. Fără el, fonturile se descoperă abia
    // după ce CSS-ul e parsat, iar `font-display: swap` face exact ce promite — un
    // schimb vizibil de font pe conținut deja randat.
    //
    // Calea e cea din manifestul Vite, care include și fișierele de font referite din
    // CSS, deci `Vite::asset()` întoarce URL-ul cu hash fără să-l hardcodăm — un bump
    // de @fontsource nu lasă un hash vechi în pagină. Dacă un bump schimbă chiar calea
    // fișierului, `rescue()` sare peste preload și RAPORTEAZĂ (Sentry, §25.2): pagina
    // rămâne corectă, dar pierderea nu e silențioasă.
    $preloadedFonts = collect([
        'node_modules/@fontsource/ibm-plex-sans/files/ibm-plex-sans-latin-400-normal.woff2',
        'node_modules/@fontsource/ibm-plex-mono/files/ibm-plex-mono-latin-400-normal.woff2',
    ])->map(fn (string $font) => rescue(fn () => Vite::asset($font), null, report: true))
        ->filter();
@endphp
<!DOCTYPE html>
<html lang="en" class="{{ $theme === 'dark' ? 'dark' : '' }}" style="color-scheme: {{ $theme }};">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        {{-- FR-PUB-04 — producția e demo public (§0): niciodată indexat, pe nicio pagină. --}}
        <meta name="robots" content="noindex, nofollow">

        <title inertia>{{ config('app.name', 'Throughput') }}</title>

        @foreach ($preloadedFonts as $font)
            <link rel="preload" as="font" type="font/woff2" href="{{ $font }}" crossorigin>
        @endforeach

        @vite(['resources/css/app.css', 'resources/js/app.tsx'])
        @inertiaHead
    </head>
    <body class="bg-bg text-text antialiased">
        @inertia
    </body>
</html>
