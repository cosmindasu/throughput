import { adrUrl } from '@/help/adr';
import type { HelpTopicDefinition } from '@/help/types';

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
const sentEmails: HelpTopicDefinition = {
    id: 'sent-emails',
    adr: {
        id: 'ADR-020',
        title: 'A dedicated RLS policy for the email log, with an optional tenant',
        url: adrUrl('ADR-020', 'politica-rls-proprie-pentru-jurnalul-de-email'),
    },
};

export default sentEmails;
