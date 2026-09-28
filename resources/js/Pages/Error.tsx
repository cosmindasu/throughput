import { Head, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { ButtonLink } from '@/Components/Button';
import AppLayout from '@/Layouts/AppLayout';

interface ErrorPageProps {
    status: number;
    title: string;
    message: string;
}

/**
 * ADR-024 — pagina de eroare pentru cererile Inertia. Vederile Blade din
 * `resources/views/errors/` rămân neatinse și servesc orice încărcare completă de pagină; ele
 * sunt singurele care se randează când build-ul frontend lipsește, fiindcă nu depind de Vite.
 * Pagina asta acoperă exact cazul pe care ele nu-l pot acoperi: o cerere Inertia, unde un
 * răspuns fără antetul `X-Inertia` ajunge într-un modal, nu într-o navigare.
 *
 * `title` și `message` vin ca prop-uri, rezolvate server-side din aceleași chei
 * `lang/{en,fr}.json` pe care le folosesc vederile Blade (`App\Support\ErrorPageStatus`) —
 * pagina nu are catalog i18n propriu, tocmai ca cele două randări să nu poată diverge.
 * `useTranslation` rămâne folosit doar pentru eticheta butonului, comună shell-ului.
 */
export default function Error() {
    const { t } = useTranslation('common');
    const page = usePage<{
        workspace: { slug: string } | null;
    }>();

    const { status, title, message } = page.props as unknown as ErrorPageProps;

    // Un vizitator autentificat, cu workspace rezolvat, are unde să se întoarcă în aplicație.
    // Unul anonim — sau unul căruia i-a expirat sesiunea — n-are: pentru el singura
    // destinație reală e pagina publică.
    const slug = page.props.workspace?.slug;
    const backHref = slug ? `/${slug}/dashboard` : '/';

    return (
        <>
            <Head title={title} />

            <div className="flex flex-col items-start gap-6 py-12">
                <p className="numeric text-5xl font-semibold text-text-2">{status}</p>

                <div className="flex flex-col gap-3">
                    <h1 className="text-2xl font-semibold text-text">{title}</h1>
                    <p className="max-w-prose text-text-2">{message}</p>
                </div>

                <ButtonLink href={backHref} variant="primary">
                    {slug ? t('common:errorPage.backToDashboard') : t('common:errorPage.backToHome')}
                </ButtonLink>
            </div>
        </>
    );
}

Error.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
