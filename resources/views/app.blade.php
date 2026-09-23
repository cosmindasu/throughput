@php
    // FR-PREF-03 — fără licărire de temă: clasa se randează direct pe <html>
    // la primul răspuns, ÎNAINTE de orice JS. Rezoluția (cookie → users.theme
    // explicit → implicit închis) e în App\Support\ThemePreference, sursă unică
    // cu propul `theme` din HandleInertiaRequests::share() — un useEffect care
    // ar aplica tema după hidratare ar produce exact licărirea interzisă aici.
    $theme = \App\Support\ThemePreference::resolveForRequest(request());

    // ADR-022, specs.md §15.8 FR-I18N-01 — aceeași sursă unică apelată din
    // App\Http\Middleware\SetLocale (App::setLocale()) și din
    // HandleInertiaRequests::share() (propul `locale`), ca să nu poată diverge silențios.
    // Randat server-side, ÎNAINTE de orice JS — la fel ca `$theme` mai sus (FR-PREF-03):
    // un `<html lang>` corectat abia după hidratare ar anunța limba greșită unui
    // screen reader pentru fereastra dintre primul paint și acea corecție.
    $locale = \App\Support\LocalePreference::resolveForRequest(request());

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
<html lang="{{ $locale }}" class="{{ $theme === 'dark' ? 'dark' : '' }}" style="color-scheme: {{ $theme }};">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        {{-- FR-PUB-04 — producția e demo public (§0): niciodată indexat, pe nicio pagină. --}}
        <meta name="robots" content="noindex, nofollow">

        <title inertia>{{ config('app.name', 'Throughput') }}</title>

        @foreach ($preloadedFonts as $font)
            <link rel="preload" as="font" type="font/woff2" href="{{ $font }}" crossorigin>
        @endforeach

        {{-- Preambulul Fast Refresh cerut de @vitejs/plugin-react în `npm run dev`; fără el, orice
             pagină pică cu „can't detect preamble". În afara modului hot nu randează nimic, iar
             CSP-ul strict (care ar bloca scriptul inline) e oricum oprit în modul hot. --}}
        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx'])
        @inertiaHead
    </head>
    <body class="bg-bg text-text antialiased">
        @inertia
    </body>
</html>
