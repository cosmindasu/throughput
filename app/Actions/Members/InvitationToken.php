<?php

namespace App\Actions\Members;

use Illuminate\Support\Str;

/**
 * US-TEN-01, §6.4 — tokenul de acceptare al unei invitații („un token de acceptare",
 * Gherkin).
 *
 * În `memberships.invitation_token` se scrie DOAR hash-ul; valoarea în clar există o
 * singură dată, în linkul din email. Aceeași regulă ca `password_reset_tokens`
 * (`Illuminate\Auth\Passwords\DatabaseTokenRepository` hash-uiește înainte de INSERT):
 * jurnalul „Sent Emails" (BR-DEMO-02, §22.3) păstrează CONȚINUTUL COMPLET al mesajelor,
 * deci o copie în clar ar fi stat în DOUĂ tabele, nu una — iar `SentEmailRedactor` scoate
 * linkul din corpul jurnalizat tocmai pentru că e un secret purtător de acces.
 *
 * `sha256` fără salt, nu `Hash::make()`, deliberat: tokenul e 32 de octeți aleatori
 * (256 de biți de entropie), nu o parolă ghicibilă — un hash lent ar apăra împotriva unui
 * atac de dicționar care nu există aici, iar căutarea după token trebuie să fie o
 * EGALITATE indexabilă, nu o parcurgere cu `password_verify` peste toate rândurile.
 */
final class InvitationToken
{
    /** Valoarea în clar, cea care ajunge în link. 64 de caractere hex = 32 de octeți. */
    public static function generate(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** Forma stocată în `memberships.invitation_token`. */
    public static function hash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    /**
     * Un token care nu are forma generată de `generate()` nu ajunge niciodată într-o
     * interogare: `/invitations/{workspace}/{token}` e o rută publică, iar un parametru
     * arbitrar ar produce oricum 0 rânduri — dar mai bine respins înainte, fără a atinge
     * baza.
     */
    public static function looksValid(string $plainToken): bool
    {
        return (bool) preg_match('/^[0-9a-f]{64}$/', $plainToken);
    }

    /** Fereastra din Gherkin-ul US-TEN-01: „un email cu link de acceptare valid 7 zile". */
    public const VALID_FOR_DAYS = 7;

    /**
     * Numele afișat implicit al unui invitat care nu are încă un cont: partea dinaintea
     * lui `@`, titularizată. Se înlocuiește cu numele REAL la acceptare — dar până atunci
     * lista de membri trebuie să arate ceva lizibil, nu o adresă goală (`users.name` e
     * `NOT NULL`).
     */
    public static function placeholderNameFor(string $email): string
    {
        return Str::of($email)->before('@')->replace(['.', '_', '-'], ' ')->headline()->value();
    }
}
