import { Head, router, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
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

    const logout = () => {
        router.post('/logout');
    };

    return (
        <>
            <Head title="Workspace unavailable" />

            <div className="flex flex-col gap-4 text-center">
                <h1 className="text-lg font-semibold text-text">This workspace is unavailable</h1>

                <p className="text-sm text-text-2">
                    This workspace&apos;s Throughput subscription was canceled. Only an Owner can reactivate it —
                    everything else, including your access, stays paused until then.
                </p>

                {owners.length > 0 && (
                    <div className="rounded-md border border-border bg-raised p-3 text-left text-sm">
                        <p className="font-medium text-text">Ask an Owner to reactivate it:</p>
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
                        Log out
                    </Button>
                </div>
            </div>
        </>
    );
}

AccessBlocked.layout = (page: ReactNode) => <GuestLayout>{page}</GuestLayout>;
