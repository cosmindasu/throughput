{{-- Corpul emailului de invitație (specs.md §6.4, US-TEN-01). Text simplu, deliberat —
     aceeași alegere ca `reports/mail/delivery.blade.php`: fără CSS de client de mail de
     întreținut pentru un mesaj tranzacțional.

     NOTĂ pentru demo-ul public (BR-DEMO-02, §22.3): linkul de mai jos e REDACTAT în
     jurnalul „Sent Emails" (`App\Support\Mail\SentEmailRedactor`) — e un secret purtător
     de acces, exact ca linkul de resetare a parolei. Emailul REAL, către o adresă din lista
     albă, îl conține întreg.

     FR-I18N-04, Lotul I18N Val 5 — corpul ÎNTREG trece acum prin catalog
     (`lang/{en,fr}/mail.php`, grupul `member_invitation`), în oglindă cu
     `reports/mail/delivery.blade.php`. Prima trecere a Valului 5 mutase doar butonul
     (`accept_cta`) și lăsase restul literal, în engleză, cu o notă care presupunea greșit
     că „restul emailului trece deja prin catalog"; nu era adevărat — golul a fost
     semnalat, nu reparat, la acea trecere, și reparat abia acum, la a doua.

     `<strong>` din jurul numelui invitatorului, al workspace-ului și al rolului se compune
     manual, cu `e()`, ÎNAINTE de interpolarea în `__()` — sunt conținut introdus de
     UTILIZATOR (numele contului, numele workspace-ului), iar rezultatul se randează apoi
     RAW (`{!! !!}`), ca traducerea să poată păstra tagul fără să redeschidă o gaură XSS —
     exact tehnica din `reports/mail/delivery.blade.php`/`gdpr/mail/export-ready.blade.php`.
     A doua apariție a numelui invitatorului (în propoziția despre valabilitatea linkului)
     NU e înconjurată de `<strong>` — la fel ca înainte de acest lot — deci trece prin
     `__()` normal, randat cu `{{ }}` (escapare simplă, ca înainte).

     `unsolicited` e randat RAW (`{!! !!}`), nu cu `{{ }}` — string STATIC, fără nicio
     interpolare, deci nicio gaură XSS de evitat. Bug găsit la verificarea acestui val:
     `{{ }}` ar fi escapat apostroful din „weren't" (`&#039;`), deși literalul dinainte nu
     trecea prin nicio escapare — o diferență de bit față de engleza dinainte de mutare,
     invizibilă la ochi (browserul randează identic), dar exact ce interzice cerința de
     regresie zero.

     A11Y-07 — `@extends('mail.layout')`: scheletul `<html lang>`/`<meta charset>`/`<title>`
     trăiește în `resources/views/mail/layout.blade.php`, comun celor 6 Mailable-uri. --}}
@extends('mail.layout')

@section('content')
<p>{{ __('mail.member_invitation.greeting') }}</p>

<p>
    {!! __('mail.member_invitation.body', [
        'inviter' => '<strong>'.e($invitedByName).'</strong>',
        'workspace' => '<strong>'.e($workspaceName).'</strong>',
        'role' => '<strong>'.e($roleName).'</strong>',
    ]) !!}
</p>

<p>
    <a href="{{ $acceptUrl }}">{{ __('mail.member_invitation.accept_cta') }}</a>
</p>

<p>
    {{ __('mail.member_invitation.expiry', [
        'days' => trans_choice('mail.member_invitation.days', $expiresInDays, ['count' => $expiresInDays]),
        'inviter' => $invitedByName,
    ]) }}
</p>

<p>{!! __('mail.member_invitation.unsolicited') !!}</p>

<p>{{ __('mail.member_invitation.signature') }}</p>
@endsection
