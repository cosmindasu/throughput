{{-- Corpul notificării de export GDPR (FR-GDPR-01, specs.md §20.5). Text simplu, ca la
     `reports/mail/delivery.blade.php` — fără CSS de client de mail de întreținut.

     ADR-022, specs.md §15.8 FR-I18N-04/05 — randat în limba destinatarului REAL (Owner-ul
     autentificat care a cerut exportul, `users.locale`): `App\Jobs\Gdpr\FinalizeDataExportJob`
     cheamă `->locale(...)` pe `App\Mail\DataExportReadyMail` înainte de trimitere, la fel
     ca la `reports/mail/delivery.blade.php` — vezi nota de acolo despre
     `Illuminate\Support\Traits\Localizable::withLocale()`.

     `<strong>` din jurul numelui de workspace se compune manual, cu `e()`, la fel ca la
     numele raportului în `reports/mail/delivery.blade.php` — `$workspaceName` e
     configurat de tenant, nu text de sistem, deci trece prin aceeași precauție XSS
     înainte de randarea RAW (`{!! !!}`). --}}
<p>{{ __('mail.export_ready.greeting', ['name' => $requestedByName]) }}</p>

<p>
    {!! __('mail.export_ready.body', ['workspace' => '<strong>'.e($workspaceName).'</strong>']) !!}
</p>

<p>
    <a href="{{ $downloadUrl }}">{{ __('mail.export_ready.download') }}</a>
</p>

<p>
    {{ __('mail.export_ready.retention', [
        'days' => trans_choice('mail.export_ready.days', $retentionDays, ['count' => $retentionDays]),
        'expires' => $expiresOn,
    ]) }}
</p>

<p>{{ __('mail.export_ready.contents') }}</p>

<p>{{ __('mail.export_ready.signature') }}</p>
