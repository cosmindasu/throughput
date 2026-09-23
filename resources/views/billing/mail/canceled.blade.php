{{-- specs.md §12.2/§20.5 — trimis la tranziția spre canceled.

     I18N-02 (ADR-022, specs.md §15.8 FR-I18N-04) — corpul ÎNTREG trece prin catalog
     (`lang/{en,fr}/mail.php`, grupul `subscription_canceled`), pe modelul
     `reports/mail/delivery.blade.php` / `members/mail/invitation.blade.php`: `<strong>`
     din jurul numelui de tenant se compune manual, cu `e()`, ÎNAINTE de interpolarea în
     `__()` — `$tenantName` e conținut introdus de UTILIZATOR (numele tenantului) — iar
     rezultatul se randează apoi RAW (`{!! !!}`), ca traducerea să poată păstra tagul fără
     să redeschidă o gaură XSS.

     Linkul de reactivare rămâne text simplu (nu `<a href>`), neschimbat față de dinainte —
     doar eticheta „Reactivate from the billing page:" trece prin `__()`; URL-ul nu e
     conținut traductibil.

     A11Y-07 — `@extends('mail.layout')`: scheletul `<html lang>`/`<meta charset>`/`<title>`
     trăiește în `resources/views/mail/layout.blade.php`, comun celor 6 Mailable-uri. --}}
@extends('mail.layout')

@section('content')
<p>{{ __('mail.subscription_canceled.greeting') }}</p>

<p>
    {!! __('mail.subscription_canceled.body', [
        'tenant' => '<strong>'.e($tenantName).'</strong>',
    ]) !!}
</p>

<p>
    {{ __('mail.subscription_canceled.billing_cta') }}
    {{ url("/{$workspaceSlug}/settings/billing") }}
</p>

<p>{{ __('mail.subscription_canceled.signature') }}</p>
@endsection
