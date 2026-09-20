<?php

namespace App\Support\Mail;

/**
 * Risc de securitate semnalat explicit în raportul pachetului: jurnalul „Sent Emails"
 * păstrează CONȚINUTUL COMPLET al fiecărui email (BR-DEMO-02) — inclusiv, pentru
 * recuperarea parolei (FR-PUB-05), un link cu un TOKEN VALID de preluare a contului. Un
 * rând de genul ăsta, vizibil oricui poate deschide ecranul (Owner/Manager, §7.4), e un
 * cont oferit pe tavă: token-ul din jurnal e IDENTIC cu cel din emailul care ar fi plecat
 * cu adevărat.
 *
 * Apărarea aleasă: NU excludem emailul din jurnal (BR-DEMO-02 îl cere explicit — „orice
 * email tranzacțional (…) resetare parolă" — vezi migrația `sent_emails`), ci redactăm
 * token-ul din corp ÎNAINTE de scriere, necondiționat de tenant/rol. Redactarea nu trebuie
 * să depindă de RLS/permisiuni ca ultim strat de apărare: chiar dacă politica RLS a tabelei
 * face oricum rândurile fără tenant invizibile din orice workspace (vezi migrația), token-ul
 * redactat rămâne o apărare în profunzime pentru orice acces direct la bază (backup,
 * migrare, un viitor ecran de super-admin, un export GDPR de tenant care ar include din
 * greșeală acest jurnal).
 *
 * Potrivirea e pe FORMA link-ului (`/reset-password/{token}` — `routes/web.php`,
 * `password.reset`), pe orice parametru `token=` generic, ȘI pe `signature=`/`expires=`
 * (URL-urile semnate Laravel, `URL::signedRoute()`/`temporarySignedRoute()` — forma pe care
 * specs.md §16.2 „link de descărcare cu expirare" și FR-GDPR-01 o anticipează; neactivă azi
 * — rapoartele atașează fișierul, exportul GDPR nu trimite încă link — dar redactorul
 * trebuie să fie gata ÎNAINTE ca cineva să construiască primul astfel de link, nu după),
 * NU pe conținutul unui `Notification`/`Mailable` anume: transportul nu știe și nu trebuie
 * să știe ce a construit mesajul (plan §10 — „reutilizat neschimbat").
 *
 * REGULĂ DE PROCES: orice tip NOU de link cu putere de autentificare/acces (o viitoare
 * invitație de membru cu token în URL, un alt export cu link semnat) își adaugă tiparul
 * AICI înainte de a fi construit în cod — nu după ce cineva observă că a scăpat în jurnal.
 */
final class SentEmailRedactor
{
    private const PLACEHOLDER = '[redacted-token]';

    public static function redact(?string $content): ?string
    {
        if ($content === null || $content === '') {
            return $content;
        }

        // `/reset-password/{token}` (necodat) și `%2F` (codat, cum ajunge într-un `href`
        // HTML-encodat sau într-un antet MIME quoted-printable/base64... acesta din urmă nu
        // e text simplu, deci nu se potrivește oricum — acoperă doar formele text-simplu).
        $redacted = preg_replace(
            '/(reset-password(?:\/|%2F))[A-Za-z0-9\-_.]{20,}/i',
            '$1'.self::PLACEHOLDER,
            $content,
        ) ?? $content;

        // `/invitations/{workspace}/{token}` (US-TEN-01, §6.4, Faza 5) — tiparul cerut
        // explicit de REGULA DE PROCES din docblock-ul clasei („o viitoare invitație de
        // membru cu token în URL își adaugă tiparul AICI înainte de a fi construit în
        // cod"). Tokenul e 64 de caractere hex (`App\Actions\Members\InvitationToken`),
        // iar acceptarea lui creează o SESIUNE în workspace-ul invitatorului — deci are
        // exact aceeași putere ca linkul de resetare a parolei. Consecință asumată,
        // semnalată în raportul lotului: linkul nu e CLICABIL din ecranul „Sent Emails" al
        // demo-ului public; fluxul de acceptare se demonstrează cu o adresă din
        // `DEMO_EMAIL_ALLOWLIST`, care primește emailul real, întreg.
        $redacted = preg_replace(
            '/(invitations(?:\/|%2F)[A-Za-z0-9\-_]+(?:\/|%2F))[A-Za-z0-9\-_.]{20,}/i',
            '$1'.self::PLACEHOLDER,
            $redacted,
        ) ?? $redacted;

        $redacted = preg_replace(
            '/([?&]token=)[^&\s"\'<]+/i',
            '$1'.self::PLACEHOLDER,
            $redacted,
        ) ?? $redacted;

        // URL-uri semnate Laravel — `signature=` e cea care contează (validează accesul),
        // `expires=` e redactat alături fiindcă face parte din ACELAȘI link cu putere de
        // acces și nu are valoare informativă fără `signature`.
        $redacted = preg_replace(
            '/([?&]signature=)[^&\s"\'<]+/i',
            '$1'.self::PLACEHOLDER,
            $redacted,
        ) ?? $redacted;

        return preg_replace(
            '/([?&]expires=)[^&\s"\'<]+/i',
            '$1'.self::PLACEHOLDER,
            $redacted,
        ) ?? $redacted;
    }

    public static function wasRedacted(?string $original, ?string $redacted): bool
    {
        return $original !== $redacted;
    }
}
