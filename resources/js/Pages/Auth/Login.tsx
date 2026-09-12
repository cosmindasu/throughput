import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent, type ReactNode } from 'react';
import GuestLayout from '@/Layouts/GuestLayout';
import type { DemoAccountRole, LoginPageProps } from '@/types/generated';

/**
 * Pagina de login (FR-PUB-02). Butoanele demo sunt vizibile doar cât timp
 * `demoMode` e true (prop comun) — autentificarea „un click” e o
 * particularitate a mediului demo public, nu a aplicației (BR-PUB-01).
 *
 * Fallback-ul cu email/parolă rămâne mereu disponibil (§4.2: „doar login cu
 * email/parolă pentru conturile existente, ca fallback la butoanele demo”) —
 * nu există formular de „Sign up” (§2.2, în afara domeniului acestui MVP).
 */
export default function Login() {
    const { demoMode, canResetPassword, status, demoAccounts } = usePage<LoginPageProps>().props;
    const [pendingRole, setPendingRole] = useState<DemoAccountRole | null>(null);

    const demoLogin = (role: DemoAccountRole) => {
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
        post('/login');
    };

    const showDemoAccounts = demoMode && demoAccounts.length > 0;

    return (
        <>
            <Head title="Log in" />

            <div className="flex flex-col gap-6">
                <div>
                    <h1 className="text-xl font-semibold text-text">Log in to Throughput</h1>
                    <p className="mt-1 text-sm text-text-2">
                        {showDemoAccounts
                            ? 'Pick a demo role to explore instantly, or use a real account below.'
                            : 'Sign in with your account email and password.'}
                    </p>
                </div>

                {status && (
                    <p role="status" className="rounded-md bg-success-tint px-3 py-2 text-sm text-success">
                        {status}
                    </p>
                )}

                {showDemoAccounts && (
                    <div className="flex flex-col gap-3">
                        <h2 className="text-sm font-medium text-text-2">Demo accounts</h2>
                        {demoAccounts.map((account) => (
                            <button
                                key={account.role}
                                type="button"
                                onClick={() => demoLogin(account.role)}
                                disabled={pendingRole !== null}
                                className="flex flex-col items-start gap-0.5 rounded-md border border-control px-4 py-2.5 text-left transition-colors hover:bg-row-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                <span className="text-sm font-medium text-text">
                                    Log in as {account.name}
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
                        <span>or sign in with email</span>
                        <span aria-hidden="true" className="h-px flex-1 bg-border" />
                    </div>
                )}

                <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
                    <div>
                        <label htmlFor="email" className="text-sm font-medium text-text">
                            Email
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
                                Password
                            </label>
                            {canResetPassword && (
                                <Link href="/forgot-password" className="text-xs text-accent-text hover:underline">
                                    Forgot password?
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
                        Remember me
                    </label>

                    <button
                        type="submit"
                        disabled={processing}
                        className="rounded-md bg-accent-fill px-4 py-2 text-sm font-medium text-accent-on transition-colors hover:bg-accent-fill-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {processing ? 'Logging in…' : 'Log in'}
                    </button>
                </form>
            </div>
        </>
    );
}

Login.layout = (page: ReactNode) => <GuestLayout>{page}</GuestLayout>;
