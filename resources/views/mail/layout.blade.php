{{--
    A11Y-07 — scheletul de document HTML comun celor 6 `Mailable`-uri din `app/Mail/`
    (`billing/mail/{canceled,unpaid,payment-failed}`, `members/mail/invitation`,
    `reports/mail/delivery`, `gdpr/mail/export-ready`). Până acum fiecare vedere randa doar
    fragmentul `<p>...</p>`, fără `<!DOCTYPE html>`/`<html lang>`/`<meta charset>` — un
    client de mail fără fallback e liber să ghicească limba (WCAG 3.1.1 „Language of Page").

    NU adaugă CSS de client de mail: convenția „text simplu" a celor 6 vederi (documentată în
    fiecare, ex. `reports/mail/delivery.blade.php`) rămâne neschimbată — layout-ul ăsta e
    STRICT scheletul de document, nu un șablon vizual.

    `app()->getLocale()` citește locale-ul deja activ la momentul randării acestui layout —
    pentru toate cele 6 `Mailable`-uri, `->locale($locale)` (explicit sau `null`, fallback
    ambiental) e setat prin `Illuminate\Mail\Mailable::render()`/`send()`, care înfășoară
    ÎNTREAGA randare (inclusiv acest layout, randat de Blade ca parte a aceleiași cereri de
    view) în `Illuminate\Support\Traits\Localizable::withLocale()`. Deci `app()->getLocale()`
    aici e mereu locale-ul mailable-ului, nu unul rezidual — verificat de
    `tests/Feature/I18n/MailLocalizationTest.php` (`<html lang="fr">` sub `->locale('fr')`,
    inclusiv cu un locale ambiental diferit setat separat, simulând worker-ul de coadă).

    `$subject` — fiecare `content()` din cele 6 `Mailable`-uri pasează explicit
    `'subject' => $this->subject`: la momentul în care `content()` rulează,
    `Illuminate\Mail\Mailable::prepareMailableForDelivery()` a hidratat DEJA `$this->subject`
    din `envelope()` (`ensureEnvelopeIsHydrated()` rulează înaintea lui
    `ensureContentIsHydrated()`) — nicio recalculare/retraducere manuală a subiectului aici,
    doar o referință la ce a calculat deja `envelope()`. `@isset` omite `<title>` complet
    dacă vreun apelant viitor nu-l pasează, în loc de un tag gol sau greșit.
--}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
@isset($subject)
<title>{{ $subject }}</title>
@endisset
</head>
<body>
@yield('content')
</body>
</html>
