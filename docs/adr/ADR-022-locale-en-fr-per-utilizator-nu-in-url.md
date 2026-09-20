# ADR-022: The interface becomes bilingual (EN default + FR) — language is a per-user preference, not a URL segment

- **Status**: Accepted
- **Date**: 2026-09-20
- **Deciders**: project owner
- **Amends**: [[ADR-002]] — adds a note that explicitly excludes the language segment from the URL, so that the debate about URL shape is not reopened without context. [[ADR-002]] stays Accepted, not superseded.
- **Related**: [[ADR-013]], [[ADR-014]] (the rule for serializing context into jobs — scalar `tenantId` in the constructor, restored at the start of `handle()`; `locale` follows the same pattern)
- **Tags**: i18n, frontend, backend, locale, queues, e2e, portfolio

## Context and problem statement

`specs_si_design/README.md:7` states explicitly: "The market is exclusively international. The interface is in English." `specs_si_design/specs.md:9` repeated the same premise **up to v1.21**, justified by the target market: "built as a portfolio piece for technical buyers on international freelancing platforms... The application's interface is in English... The target market is exclusively international: there is no ANAF e-Factura, no Romanian VAT and no Netopia in this product, deliberately." (The line was rewritten in v1.22 as a result of this ADR — the quote above is the premise being replaced, not the current text.)

This ADR **changes** the premise above, it does not extend it. From now on, the application is bilingual: **English (default) + French.**

**The real reason, written as such rather than inferred:** a demonstration of i18n competence for technical buyers — a CLDR pluralization engine, correct language resolution under RLS and inside queued jobs, a complete translation catalog (UI, validation, email, PDF, export, help panel). It is **not** a targeted francophone market. The project remains exactly what it was: an international B2B demo, with no e-Factura, no Romanian VAT, no Netopia — nothing in the market premise from `specs_si_design/README.md:7`/`specs.md:9` changes except the number of interface languages. Inventing a francophone market justification would be a false claim inside a portfolio piece that sells precisely on the honesty of its documented decisions.

**Delivery timing:** a dedicated batch, **after Phase 5**, over a frozen screen surface. The reason: extracting strings from the UI, validation, email and PDF is done once — over a surface that already includes the Phase 5 screens (subscription, billing) — with a single owner for the translation catalog. Doing i18n in parallel with phases that are still adding screens would have meant either repeated extraction, or a catalog permanently behind the code.

## Decision drivers

- **Demonstrative value, not a market requirement.** A full-stack i18n competence signal for technical buyers (Upwork), not localization for a segment of real customers.
- **No new mechanism where one already works.** `users.theme` + `ThemePreference` (`app/Support/ThemePreference.php`) is already the verified pattern for resolving a per-user preference, with a cookie fallback and server-side rendering without flicker. Language reuses exactly this pattern; it does not invent a parallel one.
- **The URL is already decoration, by a prior decision.** [[ADR-002]] established that explicitly for the workspace; the natural extension is to keep the language out of the URL as well.
- **The rule for serializing context into jobs is already written** ([[ADR-013]], [[ADR-014]], `.ai/rules/tenancy.md:123-138`) — `locale` follows it, it does not reopen it.
- **A contained cost, not a recurring one.** A single batch, over a frozen surface, with the existing E2E suite untouched (pinned to `en`) and a new FR subset, run only on push to `main`.
- **The pluralization engine is not reinvented.** The project already has **three** independent hardcoded pluralization patterns, found while writing this ADR: `resources/js/Components/BulkSelectionBar.tsx:107` (`effectiveCount === 1 ? resourceNounSingular : resourceNounPlural`), `resources/js/Components/BulkSelectionBar.tsx:321` (`effectiveCount === 1 ? 'draft order' : 'draft orders'`) and `resources/js/Components/GlobalSearch.tsx:267` (`` `${flatResults.length} result${flatResults.length === 1 ? '' : 's'}` ``). Three independent implementations of the same `=== 1 ? singular : plural` are exactly the signal that an engine is missing, not a string library.

## Considered options

### 1. The language selection mechanism

**Option A (CHOSEN): a `users.locale` column, on the exact model of `users.theme`.**

A `LocalePreference` class, replicating the shape of `ThemePreference` (`app/Support/ThemePreference.php`): an explicit resolution order — the authenticated user's choice > the `locale` cookie > the `en` default — `App::setLocale()` called from an early middleware, a `locale` prop propagated through `HandleInertiaRequests::share()` (where `theme` is already propagated today, line 68), and `<html lang="...">` rendered server-side in `resources/views/app.blade.php` **before** any JS (today, on line 26, `lang` is hardcoded to `"en"` — it becomes dynamic, like `class="{{ $theme === 'dark' ? ... }}"` on the same line). The same technique avoids the same flicker that `ThemePreference` avoids today for the theme.

`users` is a global identity, with no `tenant_id`/RLS ([[ADR-014]]) — the language preference persists across workspace switches, just like the theme, with nothing extra to write.

- **Pro**: no new mechanism to design or verify — the existing one is already tested, already documented, already flicker-free. Zero onboarding for anyone reading the code: whoever understood `theme` has understood `locale`.
- **Con**: none specific to the mechanism; the real trade-offs are the ones discussed below, under consequences (jobs, E2E).

**Option B (REJECTED): a language segment in the URL (`/fr/{workspace}/...`).**

- **Con, decisive**: direct tension with [[ADR-002]], which argues explicitly that "the URL is decoration" — the demonstrative value comes from what the buyer sees when switching, not from the shape of the path. Putting the language in the URL contradicts that reasoning with no new justification.
- **Con**: `ResolveWorkspace` already strips `{workspace}` from the route parameters with `forgetParameter` (`.ai/rules/tenancy.md:35-38`), precisely because the Laravel dispatcher passes parameters positionally and an uncleaned typed parameter causes a 500. An additional `locale` segment would require the same `forgetParameter` duplicated everywhere `ResolveWorkspace` already applies it for the workspace — the same 500 risk on controllers with typed parameters, this time over two segments instead of one.
- **Con**: it would require extending `URL::defaults()` (already used for the workspace, [[ADR-002]]) across **all** server-side URL generation, so that `route()` does not drop the language segment on every link — a second place where the rule "one more path segment = one more place to propagate it" would apply identically to the one already documented for the workspace.

### 2. The frontend translation engine

**Option A (CHOSEN): `react-i18next`, JSON catalogs in `resources/js/locales/`.**

- **Pro**: a ready-made CLDR pluralization engine — see the three hardcoded patterns above, which disappear by adopting it. ~15-20 KB gzipped. The application has no SSR and is `noindex` — the hydration constraint that would make the choice sensitive on another project does not exist here.
- **Con**: one more JS dependency, one more catalog to maintain. Accepted — that is exactly the surface the i18n batch adds by definition. (The batch is a dedicated section **between** Phase 5 and Phase 6, not Phase 6 itself — that one stays "Presentation", with no new business code.)

**Option B (REJECTED): `@lingui/react`.**

- **Con, decisive**: a build step separate from Vite; macros that require JSX rewriting for every existing string. A larger migration cost for a gain equivalent to Option A.

**Option C (REJECTED): translations as an Inertia prop, served from `lang/`.**

- **Con, decisive**: it reinvents the CLDR pluralization engine in our own code, with no gain over a mature library — exactly the "three independent implementations of `=== 1 ? singular : plural`" pattern that Option A eliminates.

### 3. Backend

`lang/en/*.php` + `lang/fr/*.php`, native Laravel. No alternative was discussed — it is the idiomatic choice, with no new dependency, consistent with "Do Things the Laravel Way".

## Decision outcome

**Option A on each of the three points above.**

### What gets translated

UI, validation, email, PDF, exports, the help panel (30 topics, ~13,900 words) and the demo data (a pool of FR names in `database/seeders/Support/DemoNames.php`).

### What does NOT get translated

User-entered content (`saved_views.name`, `report_definitions.name`) — it stays in the language it was written in. There is no "automatic translation" of business data; that would be a false claim about what the application does.

### CSV import

Mapping stays on a stable key — `ImportRowMapper` (`app/Support/Imports/ImportRowMapper.php`) untouched. FR aliases are added on `ImportField` (`app/Support/Imports/ImportField.php`), otherwise column auto-suggestion breaks when re-importing an export the same application produced in French.

## Consequences

### Positive

- It reuses an already-verified mechanism (`ThemePreference`) instead of inventing a new one — design risk reduced to a minimum.
- The CLDR engine eliminates the three hardcoded pluralization patterns found while writing this ADR.
- A single batch, over a frozen surface — string extraction does not repeat with every later phase.
- The CI cost stays contained: the existing E2E suite (65 tests) stays pinned to `en`; a new FR subset runs only on push to `main`, reusing the `@smoke` mechanism that already exists (`.github/workflows/ci.yml:308`), with no extra cost per PR.
- A native Laravel backend — zero new dependency on the server side.

### Negative / trade-offs, explicitly accepted

1. **Queued jobs receive `locale` as a scalar in the constructor**, exactly as [[ADR-013]]/[[ADR-014]] require for `tenantId`, plus `App::setLocale($this->locale)` at the start of `handle()`. The concrete reason: the queue worker is a long-lived process; `App::setLocale()` writes on the `Translator` singleton in the container, and Laravel only resets `scoped()` instances between jobs — an FR job followed by an EN one, on the same worker, leaks the first one's language into the second. It is the same class of bug that `.ai/rules/tenancy.md:123-138` already documents for per-request memoization under a long-lived worker, applied now to language instead of the tenant. **For the billing/shipping jobs** (Phase 5, per the scope already planned in `plan-implementare.md` §11), this lands as a **targeted fix** on top of them when the i18n batch arrives — not as a rewrite — provided that whoever writes them knows the pattern above in advance, whenever they are written.
2. **`e2e/setup/auth.setup.ts:24`** looks for the demo login button by literal text (`` `Log in as ${DEMO_ROLE_LABELS[role]}` ``) and produces the `storageState` read by all 65 tests in the suite. It is a single point of failure: if that button ever becomes translated conditionally on language, the whole suite fails at the authentication step, not on some business assertion. The literal-text ↔ setup coupling is **deliberate** (see the comment in `e2e/support/auth.ts:12-17`: "Literal text, not derived: if the backend label changes, the setup test must fail, not stay silent.") — so the consequence is written down here rather than assumed. Mitigation: the existing `en` suite keeps logging in in English; the new FR subset gets its own `storageState`, with its own button text, and does not borrow the `en` file.
3. **`User` has to implement `Illuminate\Notifications\HasLocalePreference`**, otherwise `users.locale` has no automatic effect on notifications (emails sent through `Notifiable` ignore the preference without that explicit contract).

### Cost, stated honestly

The order of magnitude is **a few hundred hours**, broken down by wave in `plan-implementare.md`, the "Lot I18N" section — that is the single source for effort figures, so that there are never two totals contradicting each other. The French translation is written by the assistant and reviewed by the owner — a quality risk accepted on a portfolio piece, mitigated by targeted review of the flagged passages plus an automated coverage test that fails CI on a missing translation key (an incomplete EN/FR catalog = a red build, not an unnoticed string in production).

## Links

- [[ADR-002]] — amended by this ADR: a note is added that explicitly excludes the language segment from the URL, so that the "the URL is decoration" argument is not reopened without context every time.
- [[ADR-013]] — external calls leave the HTTP request, into queues; the base rule for context serialized onto a job.
- [[ADR-014]] — the job families (tenant / system) and the "context serialized, restored at the start of `handle()`" rule, which `locale` follows identically.
- `app/Support/ThemePreference.php` — the mechanism replicated for `LocalePreference`.
- `.ai/rules/tenancy.md:35-38` (the `forgetParameter` rule on `ResolveWorkspace`), `.ai/rules/tenancy.md:123-138` (memoization on a long-lived worker) — both cited as a reason for rejection/caution, untouched.
- `specs_si_design/README.md:7`, `specs_si_design/specs.md:9` — the "the interface is in English" premise, changed by this ADR.
- `e2e/setup/auth.setup.ts:24`, `e2e/support/auth.ts:12-17` — the literal-text coupling in E2E authentication.
- `.github/workflows/ci.yml:308` — the `@smoke` mechanism reused for the FR subset.
