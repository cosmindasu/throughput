import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import Button from '@/Components/Button';
import GuestLayout from '@/Layouts/GuestLayout';
import { roleLabel } from '@/lib/roles';
import type { DemoAccountRole, LoginPageProps } from '@/types/generated';

/**
 * Pagina de login (FR-PUB-02). Butoanele demo sunt vizibile doar cât timp
 * `demoMode` e true (prop comun) — autentificarea „un click” e o
 * particularitate a mediului demo public, nu a aplicației (BR-PUB-01).
 *
 * Fallback-ul cu email/parolă rămâne mereu disponibil (§4.2: „doar login cu
 * email/parolă pentru conturile existente, ca fallback la butoanele demo”) —
 * nu există formular de „Sign up” (§2.2, în afara domeniului acestui MVP).
 *
 * **RISC CRITIC (Lot I18N, Val 3, ADR-022 consecința 2)** — `e2e/setup/auth.setup.ts`
 * caută butonul demo după textul literal `Log in as ${DEMO_ROLE_LABELS[role]}` și produce
 * `storageState`-ul citit de toate testele E2E. Cheia `auth:login.demoButton` are valoarea
 * ENGLEZĂ `"Log in as {{name}}"` — identică, literă cu literă, cu stringul dinainte de
 * extragere — iar `account.name` (server, `DemoAccountRole` → etichetă) rămâne interpolat
 * NETRADUS (FR-I18N-06: valoare de domeniu, nu etichetă de UI). Suita E2E rulează cu
 * `APP_LOCALE=en` (`playwright.config.ts`), deci catalogul citit la acel test e mereu cel
 * englez — nu atinge valoarea de-acolo fără să reverifici `e2e/setup/auth.setup.ts` și
 * `e2e/support/auth.ts:12-17`.
 */
export default function Login() {
    const { demoMode, canResetPassword, status, demoAccounts } = usePage<LoginPageProps>().props;
    const { t } = useTranslation(['auth', 'common']);
    const [pendingRole, setPendingRole] = useState<DemoAccountRole | null>(null);

    const demoLogin = (role: DemoAccountRole) => {
        if (pendingRole !== null) {
            return;
        }

        setPendingRole(role);
        router.post(
            `/login/demo/${role}`,
            {},
            {
                onFinish: () => setPendingRole(null),
            },
        );
    };

    const { data, setData, post, processing, errors } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (processing) {
            return;
        }

        post('/login');
    };

    const showDemoAccounts = demoMode && demoAccounts.length > 0;

    return (
        <>
            <Head title={t('auth:login.title')} />

            <div className="flex flex-col gap-6">
                <div>
                    <h1 className="text-xl font-semibold text-text">{t('auth:login.heading')}</h1>
                    <p className="mt-1 text-sm text-text-2">
                        {showDemoAccounts ? t('auth:login.subtitleDemo') : t('auth:login.subtitleNoDemo')}
                    </p>
                </div>

                {status && (
                    <p role="status" className="rounded-md bg-success-tint px-3 py-2 text-sm text-success">
                        {status}
                    </p>
                )}

                {showDemoAccounts && (
                    <div className="flex flex-col gap-3">
                        <h2 className="text-sm font-medium text-text-2">{t('auth:login.demoAccountsHeading')}</h2>
                        {demoAccounts.map((account) => (
                            /* `aria-disabled`, nu `disabled`: un clic dezactiva TOATE butoanele
                               de rol, inclusiv chiar pe cel apăsat — browserul îl blurează și
                               focusul cade pe `<body>`, adică exact pe ecranul de intrare în
                               aplicație. Rămâne focusabil, iar clicul e neutralizat în handler. */
                            <button
                                key={account.role}
                                type="button"
                                onClick={() => demoLogin(account.role)}
                                aria-disabled={pendingRole !== null || undefined}
                                className={`flex flex-col items-start gap-0.5 rounded-md border border-control px-4 py-2.5 text-left transition-colors hover:bg-row-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus ${
                                    pendingRole !== null ? 'cursor-not-allowed opacity-60' : ''
                                }`}
                            >
                                <span className="text-sm font-medium text-text">
                                    {t('auth:login.demoButton', { name: roleLabel(t, account.role) ?? account.name })}
                                    {pendingRole === account.role ? '…' : ''}
                                </span>
                                <span className="text-xs text-text-2">{account.description}</span>
                            </button>
                        ))}
                    </div>
                )}

                {showDemoAccounts && (
                    <div className="flex items-center gap-3 text-xs text-text-3">
                        <span aria-hidden="true" className="h-px flex-1 bg-border" />
                        <span>{t('auth:login.orSignInWithEmail')}</span>
                        <span aria-hidden="true" className="h-px flex-1 bg-border" />
                    </div>
                )}

                <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
                    <div>
                        <label htmlFor="email" className="text-sm font-medium text-text">
                            {t('auth:login.emailLabel')}
                        </label>
                        <input
                            id="email"
                            type="email"
                            autoComplete="username"
                            value={data.email}
                            onChange={(event) => setData('email', event.target.value)}
                            aria-invalid={Boolean(errors.email)}
                            aria-describedby={errors.email ? 'email-error' : undefined}
                            className="mt-1 w-full rounded-md border border-control bg-surface px-3 py-2 text-sm text-text focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                        />
                        {errors.email && (
                            <p id="email-error" role="alert" className="mt-1 text-sm text-danger">
                                {errors.email}
                            </p>
                        )}
                    </div>

                    <div>
                        <div className="flex items-center justify-between">
                            <label htmlFor="password" className="text-sm font-medium text-text">
                                {t('auth:login.passwordLabel')}
                            </label>
                            {canResetPassword && (
                                <Link href="/forgot-password" className="text-xs text-accent-text hover:underline">
                                    {t('auth:login.forgotPassword')}
                                </Link>
                            )}
                        </div>
                        <input
                            id="password"
                            type="password"
                            autoComplete="current-password"
                            value={data.password}
                            onChange={(event) => setData('password', event.target.value)}
                            aria-invalid={Boolean(errors.password)}
                            aria-describedby={errors.password ? 'password-error' : undefined}
                            className="mt-1 w-full rounded-md border border-control bg-surface px-3 py-2 text-sm text-text focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                        />
                        {errors.password && (
                            <p id="password-error" role="alert" className="mt-1 text-sm text-danger">
                                {errors.password}
                            </p>
                        )}
                    </div>

                    <label className="flex items-center gap-2 text-sm text-text-2">
                        <input
                            type="checkbox"
                            checked={data.remember}
                            onChange={(event) => setData('remember', event.target.checked)}
                            className="rounded border-control focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                        />
                        {t('auth:login.rememberMe')}
                    </label>

                    <Button
                        type="submit"
                        variant="primary"
                        className="px-4 py-2"
                        pending={processing}
                        pendingLabel={t('auth:login.submitting')}
                    >
                        {t('auth:login.submit')}
                    </Button>
                </form>

                {/* GDPR-06 — link către Politica de confidențialitate/Termeni, direct sub
                    formular (în afara oricărui `<footer>`: pagina asta n-are unul propriu,
                    cel din `GuestLayout` acoperă restul ecranelor pe acest shell). */}
                <p className="text-center text-xs text-text-3">
                    <Link href="/privacy" className="hover:text-text hover:underline">
                        {t('common:footer.privacyLink')}
                    </Link>
                    <span aria-hidden="true"> · </span>
                    <Link href="/terms" className="hover:text-text hover:underline">
                        {t('common:footer.termsLink')}
                    </Link>
                </p>
            </div>
        </>
    );
}

Login.layout = (page: ReactNode) => <GuestLayout>{page}</GuestLayout>;
