{{-- Corpul emailului de invitație (specs.md §6.4, US-TEN-01). Text simplu, deliberat —
     aceeași alegere ca `reports/mail/delivery.blade.php`: fără CSS de client de mail de
     întreținut pentru un mesaj tranzacțional.

     NOTĂ pentru demo-ul public (BR-DEMO-02, §22.3): linkul de mai jos e REDACTAT în
     jurnalul „Sent Emails" (`App\Support\Mail\SentEmailRedactor`) — e un secret purtător
     de acces, exact ca linkul de resetare a parolei. Emailul REAL, către o adresă din lista
     albă, îl conține întreg. --}}
<p>Hi,</p>

<p>
    <strong>{{ $invitedByName }}</strong> invited you to join
    <strong>{{ $workspaceName }}</strong> on Throughput as
    <strong>{{ $roleName }}</strong>.
</p>

<p>
    <a href="{{ $acceptUrl }}">Accept the invitation</a>
</p>

<p>
    This link is valid for {{ $expiresInDays }} {{ Str::plural('day', $expiresInDays) }}.
    If it expires, ask {{ $invitedByName }} to send a new one.
</p>

<p>If you weren't expecting this invitation, you can ignore this email — nothing happens until you accept.</p>

<p>— Throughput</p>
