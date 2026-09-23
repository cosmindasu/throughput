{{-- Corpul emailului de dunning (specs.md §12.2, FR-BILL-04).

     I18N-02 (ADR-022, specs.md §15.8 FR-I18N-04) — corpul ÎNTREG trece prin catalog
     (`lang/{en,fr}/mail.php`, grupul `dunning_payment_failed`), aceeași tehnică ca
     `billing/mail/canceled.blade.php`: `<strong>` din jurul numelui de tenant se compune
     manual, cu `e()`, ÎNAINTE de interpolarea în `__()`, iar rezultatul se randează RAW
     (`{!! !!}`). `:attempt` e un număr simplu (nu substantiv pluralizabil în frază — „attempt
     3", nu „3 attempts"), deci placeholder direct, fără `trans_choice()`.

     A11Y-07 — `@extends('mail.layout')`: scheletul `<html lang>`/`<meta charset>`/`<title>`
     trăiește în `resources/views/mail/layout.blade.php`, comun celor 6 Mailable-uri. --}}
@extends('mail.layout')

@section('content')
<p>{{ __('mail.dunning_payment_failed.greeting') }}</p>

<p>
    {!! __('mail.dunning_payment_failed.body', [
        'tenant' => '<strong>'.e($tenantName).'</strong>',
        'attempt' => $attemptCount,
    ]) !!}
</p>

<p>
    {{ __('mail.dunning_payment_failed.billing_cta') }}
    {{ url("/{$workspaceSlug}/settings/billing") }}
</p>

<p>{{ __('mail.dunning_payment_failed.signature') }}</p>
@endsection
