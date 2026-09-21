import { Head, router, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import Button from '@/Components/Button';
import GuestLayout from '@/Layouts/GuestLayout';

interface AccessBlockedOwner {
    name: string;
    email: string;
}

interface AccessBlockedPageProps {
    owners: AccessBlockedOwner[];
    [key: string]: unknown;
}

/**
 * `Settings/Billing/AccessBlocked` — P3 securitate (review-ul lotului, pct. 6). Randată
 * DIRECT din `App\Http\Middleware\EnsureSubscriptionAccess::respondBlocked()`, nu dintr-un
 * controller/rută proprie: apare pe ORICE cale dintr-un workspace `canceled`, pentru orice
 * utilizator FĂRĂ `billing.view` (adică oricine în afara Owner-ului — §7.4).
 *
 * Fără `AppLayout`: navigația lui ar duce înapoi la exact acest ecran pe orice link, ceea
 * ce n-ar spune nimic în plus față de mesajul de aici — `GuestLayout` (centrat, fără nav)
 * potrivește mai bine o pagină „nimic altceva nu funcționează".
 */
export default function AccessBlocked() {
    const { owners } = usePage<AccessBlockedPageProps>().props;
    const { t } = useTranslation('settings');

    const logout = () => {
        router.post('/logout');
    };

    return (
        <>
            <Head title={t('settings:accessBlocked.title')} />

            <div className="flex flex-col gap-4 text-center">
                <h1 className="text-lg font-semibold text-text">{t('settings:accessBlocked.heading')}</h1>

                <p className="text-sm text-text-2">{t('settings:accessBlocked.body')}</p>

                {owners.length > 0 && (
                    <div className="rounded-md border border-border bg-raised p-3 text-left text-sm">
                        <p className="font-medium text-text">{t('settings:accessBlocked.askOwner')}</p>
                        <ul className="mt-2 flex flex-col gap-1">
                            {owners.map((owner) => (
                                <li key={owner.email} className="text-text-2">
                                    {owner.name} — {owner.email}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                <div>
                    <Button variant="secondary" onClick={logout}>
                        {t('settings:accessBlocked.logout')}
                    </Button>
                </div>
            </div>
        </>
    );
}

AccessBlocked.layout = (page: ReactNode) => <GuestLayout>{page}</GuestLayout>;
