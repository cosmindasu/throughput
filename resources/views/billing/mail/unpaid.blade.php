{{-- specs.md §12.2 — o singură dată, la tranziția past_due → unpaid.

     I18N-02 (ADR-022, specs.md §15.8 FR-I18N-04) — corpul ÎNTREG trece prin catalog
     (`lang/{en,fr}/mail.php`, grupul `subscription_unpaid`), aceeași tehnică ca
     `billing/mail/canceled.blade.php`: `<strong>` din jurul numelui de tenant se compune
     manual, cu `e()`, ÎNAINTE de interpolarea în `__()`, iar rezultatul se randează RAW
     (`{!! !!}`). „unpaid" din propoziție e STATIC (starea Stripe, nu conținut de
     utilizator), deci rămâne literal `<strong>unpaid</strong>` direct în catalog.

     A11Y-07 — `@extends('mail.layout')`: scheletul `<html lang>`/`<meta charset>`/`<title>`
     trăiește în `resources/views/mail/layout.blade.php`, comun celor 6 Mailable-uri. --}}
@extends('mail.layout')

@section('content')
<p>{{ __('mail.subscription_unpaid.greeting') }}</p>

<p>
    {!! __('mail.subscription_unpaid.body', [
        'tenant' => '<strong>'.e($tenantName).'</strong>',
    ]) !!}
</p>

<p>
    {{ __('mail.subscription_unpaid.billing_cta') }}
    {{ url("/{$workspaceSlug}/settings/billing") }}
</p>

<p>{{ __('mail.subscription_unpaid.signature') }}</p>
@endsection
