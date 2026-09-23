<!DOCTYPE html>
{{--
    HTTP-04 — layout comun pentru vederile 403/404/419/429/500/503 (§20.2, ADR-022).

    Deliberat FĂRĂ Vite (`@vite(...)`): o pagină de eroare trebuie să se randeze corect și
    când build-ul frontend LIPSEȘTE sau `npm run build` n-a rulat încă — exact genul de
    moment în care apare un 500. CSS inline, niciun asset extern.

    `<html lang>` folosește `app()->getLocale()`, nu un implicit fix: pe calea de excepție
    limba e fixată explicit în `bootstrap/app.php` (`withExceptions()->render()`), ÎNAINTE
    de randare, indiferent dacă cererea a trecut prin grupul `web` (unde rulează
    `App\Http\Middleware\SetLocale`) — vezi comentariul de-acolo. Nu depinde de sesiune/tenant:
    un 500 poate apărea pe orice cerere, autentificată sau nu.

    Accesibilitate: UN SINGUR `<h1>` (titlul erorii), un singur reper `<main>`, text simplu
    fără dependențe JS, contrast AA (text `#1a202c`/`#4a5568` pe fond alb — peste 7:1; varianta
    `prefers-color-scheme: dark` păstrează același raport).
--}}
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>@yield('title')</title>

    <style>
        :root {
            color-scheme: light dark;
        }

        *,
        *::before,
        *::after {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: #ffffff;
            color: #1a202c;
        }

        main {
            max-width: 32rem;
            width: 100%;
            text-align: center;
        }

        .status-code {
            margin: 0 0 0.5rem;
            font-size: 0.875rem;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #4a5568;
        }

        h1 {
            margin: 0 0 0.75rem;
            font-size: 1.5rem;
            line-height: 2rem;
            color: #1a202c;
        }

        p.message {
            margin: 0 0 1.75rem;
            font-size: 1rem;
            line-height: 1.5rem;
            color: #4a5568;
        }

        .home-link {
            display: inline-block;
            padding: 0.625rem 1.25rem;
            border-radius: 0.375rem;
            background: #1a202c;
            color: #ffffff;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9375rem;
        }

        .home-link:hover,
        .home-link:focus-visible {
            background: #2d3748;
        }

        .home-link:focus-visible {
            outline: 2px solid #1a202c;
            outline-offset: 3px;
        }

        @media (prefers-color-scheme: dark) {
            body {
                background: #1a202c;
                color: #edf2f7;
            }

            .status-code {
                color: #a0aec0;
            }

            h1 {
                color: #ffffff;
            }

            p.message {
                color: #cbd5e0;
            }

            .home-link {
                background: #edf2f7;
                color: #1a202c;
            }

            .home-link:hover,
            .home-link:focus-visible {
                background: #ffffff;
            }

            .home-link:focus-visible {
                outline-color: #edf2f7;
            }
        }
    </style>
</head>
<body>
    <main>
        <p class="status-code">@yield('code')</p>
        <h1>@yield('title')</h1>
        <p class="message">@yield('message')</p>
        <p><a class="home-link" href="{{ url('/') }}">{{ __('Back to home') }}</a></p>
    </main>
</body>
</html>
