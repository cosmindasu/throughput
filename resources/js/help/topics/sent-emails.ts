import { adrUrl } from '@/help/adr';
import type { HelpTopic } from '@/help/types';

/**
 * `Settings/SentEmails/Index` — BR-DEMO-02, specs.md §22.3 (interceptarea email-ului de
 * ieșire), §7.4 (permisiune proprie `sent_emails.view`, Owner/Manager), §20.5 (retenție —
 * jurnalul păstrează conținutul complet, deci sunt date personale), ADR-020.
 *
 * Reconciliat cu codul la 2026-09-19 (lotul N, Faza 4): `Pages/Settings/SentEmails/Index.tsx`
 * (filtrul „Status" cu „Any status"/„Delivered"/„Intercepted"/„Partially delivered"/
 * „Failed", coloanele When/To/Subject/Mailer/Status, butonul „View"/„Hide" care extinde
 * rândul INLINE, marcajul „Link redacted", previzualizarea corpului ca text simplu sau, în
 * lipsa lui, un `<iframe sandbox="">`), `DemoInterceptingTransport` (decizie PER DESTINATAR,
 * pe domeniu ȘI pe adresă exactă; mesajul mixt pleacă doar către adresele permise, restul
 * scoase din To/Cc/Bcc; `failed` = eșec REAL al transportului, distinct de decizia de
 * interceptare; `DEMO_MODE=false` → transport transparent, fără jurnal),
 * `DemoEmailAllowlist` (listă goală = interceptează tot), `SentEmailRedactor`
 * (`/reset-password/{token}`, `token=`, `signature=`, `expires=`), `SentEmailPolicy`,
 * `PruneSentEmailsJob` (`sent_email_retention_days` = 7).
 *
 * DOUĂ NECONCORDANȚE SEMNALATE (nu sunt fișierele acestui lot):
 *  1. BR-DEMO-02 enumeră explicit resetarea parolei printre email-urile „vizibile în
 *     Settings", dar acele rânduri n-au tenant (rute `guest`, în afara contextului) iar
 *     politica RLS din ADR-020 le face vizibile DOAR fără context de tenant — ecranul
 *     rulează mereu într-un workspace, deci nu le arată niciodată. Textul de mai jos spune
 *     asta pe față, în loc să promită ce nu se întâmplă.
 *  2. Secțiunea „Sent Emails" din `Settings/Index.tsx` apare pentru Owner/Manager
 *     INDIFERENT de `DEMO_MODE` (`SettingsController` verifică doar permisiunea), dar cu
 *     `DEMO_MODE=false` tabela nu mai primește niciun rând — ecranul rămâne permanent gol.
 */
const sentEmails: HelpTopic = {
    id: 'sent-emails',
    title: 'Sent emails',
    whatIsThis:
        "Every transactional email this workspace has tried to send while the public demo guardrails are on — report deliveries today, member invitations later — with the full message and whether it actually left the building.",
    whatCanYouDo: [
        'Narrow the log with "Status": "Delivered", "Intercepted", "Partially delivered", "Failed", or leave it on "Any status".',
        'Press "View" on a row to open it in place: who it came from, every to/cc/bcc address with its own "delivered" or "intercepted" badge, and the message body. "Hide" closes it again.',
        'Read the body exactly as it was composed, so you can check what a recipient would have seen without having to be that recipient.',
        'Page back and forward through older entries at the bottom of the table.',
    ],
    rules: [
        'This log only exists while the public demo guardrails are on. Switch them off and mail goes straight out with nothing recorded — a log holding the complete text of every message would be a new risk of its own once the recipients are real people, not a feature.',
        "Only Owner and Manager can open it, and that is a permission in its own right: being able to open Settings isn't enough, because Agent and Viewer can do that too and this screen holds whole message bodies rather than a summary of who changed what.",
        '"Delivered" means every recipient was on the configured allowlist and the send succeeded. "Intercepted" means none of them were, so nothing was sent at all. "Partially delivered" means the message went out to the allowed addresses only — the others are stripped from To, Cc and Bcc before sending, so a mixed message never leaks its contents to an address that was not cleared. "Failed" is different in kind: the allowed part really was attempted and the mail provider refused it.',
        'An empty allowlist intercepts everything. It never means "deliver to everyone" — the check looks for a match, and with nothing to match, nothing is allowed.',
        'Password-reset links are stripped before the row is written: the token is replaced with "[redacted-token]" and the row is marked "Link redacted". The same goes for any token, signature or expiry parameter in any link. The token you see here cannot be used to take over an account — which matters, because the one in a real email would have been identical.',
        'Rows are kept for 7 days and then purged, the same retention as export files and for the same reason. In the public demo the nightly reset empties the table anyway; the scheduled purge is the safety net for a night when that reset fails.',
        "A password reset is sent before anyone has picked a workspace, so its row belongs to no workspace — it is recorded and redacted, but it does not appear in this list, in this workspace or any other.",
    ],
    howItsBuilt: {
        summary:
            "The interception is a wrapper around whatever mail transport is configured, registered once at the mail manager, so every message is covered without a single Mailable or Notification knowing it exists — including the ones that have not been written yet. The decision is made per recipient, on the exact address and on the domain, ignoring case; the allowed subset is sent as a copy of the message whose recipient lists contain only those addresses. Writing the log entry is isolated in its own savepoint and its failure is swallowed: a broken log write must never turn a password reset into a 500, because the response to a reset request is supposed to look identical whether or not the account exists. Attributing a row to a workspace is harder than it looks, since a queued email is actually delivered from an internal framework job carrying no tenant context, so the sender stamps an internal header at construction time, while the context still exists, and the transport removes it before the real send. The table itself carries a hand-written row-level-security policy rather than the standard one, with a second branch for the rows that have no workspace at all — without it, those rows could not even be inserted, let alone deleted at retention time.",
        adr: {
            id: 'ADR-020',
            title: 'A dedicated row-level-security policy for the email log, with an optional tenant',
            url: adrUrl('ADR-020', 'politica-rls-proprie-pentru-jurnalul-de-email'),
        },
    },
};

export default sentEmails;
