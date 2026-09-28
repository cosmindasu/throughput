# ADR-024: Errors on Inertia requests render an in-app page; the Blade views stay as the non-Inertia fallback

- **Status**: Accepted
- **Date**: 2026-09-28
- **Deciders**: project owner
- **Related**: [[ADR-022]] (locale on the exception path, the callback this one sits beside), [[ADR-014]] (the middleware whose failures surface here), [[ADR-013]] (why a request can fail in ways the user must be told about), [[ADR-021]] (precedent for naming an accepted trade-off instead of hiding it)
- **Tags**: frontend, inertia, error-handling, demo, accessibility, phase-5

## Context and problem statement

Found in the external audit of 2026-09-28, on the live deployment. Clicking "Log in as Owner" on `/login` produced, in the browser, **a blank white box over the page and nothing else** — no message, no way forward. The network tab showed `POST /login/demo/owner → 404`.

The 404 itself is correct and deliberate: `DemoLoginController::store()` (`app/Http/Controllers/Web/Auth/DemoLoginController.php:31`) aborts with 404 when the demo user is missing, with the comment "Cont demo lipsă (seed-ul încă nu a rulat) — tot 404, nu 500." The trigger was environmental — `APP_RUN_SEEDERS=false` on the deployment, so no demo user had ever been created. That cause is fixed separately and is not what this ADR is about.

**What this ADR is about is the presentation of every error on an Inertia request**, which the incident exposed and which is not specific to demo login.

Verified, not assumed — an Inertia XHR request to a route that 404s, issued from the live app:

```
status        404
content-type  text/html; charset=utf-8
x-inertia     (absent)
body          2914 bytes, <title>Not Found</title> — resources/views/errors/404.blade.php
```

Because the response carries no `X-Inertia` header, `@inertiajs/react` cannot treat it as a page visit. Its documented behaviour for a non-Inertia response is to display the response body in a **modal overlay**, not to navigate. The Blade view is a *full page* — white ground, `color-scheme: light dark` — so inside that overlay, over a dark application, it reads as an empty white rectangle.

So the project does **not** lack error pages. `resources/views/errors/` holds 403, 404, 419, 429, 500, 503 and a shared `layout.blade.php`, and they are carefully built: single `<h1>`, one `<main>`, AA contrast in both schemes, correct `lang` from the locale step in `bootstrap/app.php`. The gap is narrower: **they are only reachable in a form the user can read when the browser performs a full page load.** On the SPA path — every button, every form, every link inside the app — they arrive as modal content.

**Why this is worth an ADR rather than a patch.** The most visible window is not demo login at all. `demo:reset` (FR-DEMO-03) runs `migrate:fresh` plus a full reseed of 3 tenants / 8.000 accounts / 50.000 orders every night at 03:00 UTC. That is minutes, not seconds, during which **every** request from a visitor already inside the app fails, and every failure is a blank rectangle. For a portfolio deployment whose entire purpose is to be opened by a stranger, "looks broken" and "is briefly unavailable" are not the same outcome.

## Decision drivers

- **The Blade views must not be replaced.** `resources/views/errors/layout.blade.php:5-8` states the reason in the file: deliberately no `@vite(...)`, because "o pagină de eroare trebuie să se randeze corect și când build-ul frontend LIPSEȘTE" — exactly the situation in which a 500 is most likely. An Inertia page cannot render without the build. Whatever is added has to keep that guarantee.
- **The user must be told what happened, in the application's own surface**, not in a chrome-less overlay whose theme does not match.
- **No second source of truth for the copy.** The message on an Inertia error and the message on the Blade page for the same status must not be able to drift.
- **The exception path already has an owner.** [[ADR-022]] put a `render()` callback in `bootstrap/app.php` whose only job is fixing the locale and which returns `null` deliberately. Anything added must sit beside it without turning it into a general-purpose error handler.

## Considered options

### Option 1: Inertia page for Inertia requests, Blade for everything else (CHOSEN)

A `respond()` callback returns an Inertia response **only when the request is an Inertia visit**; every other request keeps the existing Blade view untouched.

- **Pro**: the SPA navigates to a real page in the app's own theme, with the app's own layout, focus handling and locale. A full page load — including the case where the Vite build is missing or broken — still gets the dependency-free Blade page, so the guarantee in `layout.blade.php` is preserved verbatim.
- **Pro**: the "no build" risk is nil by construction, not by luck: an Inertia request can only be issued by an application that already loaded, which means the build exists.
- **Con**: two renderers for the same set of statuses. Mitigated by sourcing both from the same translation keys, so the copy cannot diverge silently.

### Option 2: Replace the Blade views with Inertia pages entirely (REJECTED)

- Would remove the duplication, and would also remove the one property the Blade views were built for. A 500 caused by a broken or missing frontend build would then have nothing to render — the failure mode the existing comment names explicitly. Rejected on that single ground.

### Option 3: Fix only `DemoLoginController` — redirect back with a flash message instead of 404 (REJECTED)

- Narrow and cheap, and it does address the symptom that was reported. Rejected for two reasons: it contradicts a documented decision (the 404 is argued in the controller, symmetric with the workspace 404 in `ResolveWorkspace`), and it leaves every other error on every other screen showing the same blank rectangle. The audit found demo login; demo login is not the defect.

### Option 4: Put the application into maintenance mode for the duration of `demo:reset` (REJECTED as a substitute, kept as a possible complement)

- Narrows the nightly window to a clear "be right back" instead of a sequence of failures, which is genuinely better for that window. It does nothing for errors outside it — a 403 from a Policy, a 419 after a session expires, a 404 on a record another visitor deleted — which on a shared demo are the ordinary case, not the exception. Not a substitute; may be added later on its own merits.

## Decision

On a request Inertia issued (`$request->header('X-Inertia')`), render `Pages/Error` with the status code and a human message, and keep the original status on the response. On every other request, return the response unchanged, so `resources/views/errors/*` continues to serve it.

Scope is limited to the statuses that already have a Blade view — 403, 404, 419, 429, 500, 503. Anything else falls through untouched rather than being swallowed by a generic page.

API requests are unaffected: `shouldRenderJsonWhen()` (`bootstrap/app.php`) already routes `api/*` and any `expectsJson()` request to the JSON renderer, and that check runs first.

## Consequences

- A visitor who hits an error inside the app sees a page that belongs to the app, in the theme and language they were already using, with a link back — instead of a blank overlay.
- **The Inertia render is wrapped in `try/catch`, and the Blade response is the fallback.** This is not defensive decoration. `HandleInertiaRequests::share()` exposes `auth.user`, `workspaces`, `navigation` and `subscription` as closures that query the database, and a full (non-partial) render resolves all of them. On the most likely cause of a 500 — the database being unreachable — rendering this page would throw a second time, inside the exception handler. When that happens the request falls back to the dependency-free Blade view. A correct 404 shown inside a modal beats an exception raised while handling an exception.
- `resources/views/errors/*` keeps its exact current role and its no-build guarantee. Nothing in those files changes.
- The copy lives in the i18n catalogues (`errors` namespace, `en` + `fr`), used by the Inertia page. `i18n:coverage` is a blocking CI gate, so a key added in one language and not the other fails the build.
- One more thing to remember when adding a status: if it gets a Blade view, it belongs in the list here too, or the two paths diverge in coverage. `ErrorPageTest` asserts the list, so the reminder is mechanical rather than cultural.
