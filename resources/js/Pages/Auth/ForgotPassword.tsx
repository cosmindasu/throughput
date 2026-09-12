import { Head, Link, useForm, usePage } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import GuestLayout from '@/Layouts/GuestLayout';
import type { ForgotPasswordPageProps } from '@/types/generated';

/**
 * Flux „Forgot password?” (FR-PUB-05). Mesajul de succes e identic indiferent
 * dacă adresa există sau nu în baza de date — decizia se ia server-side
 * (specs.md §4.5); pagina doar afișează `status`-ul primit, fără să
 * distingă cele două cazuri.
 */
export default function ForgotPassword() {
    const { status } = usePage<ForgotPasswordPageProps>().props;
    const { data, setData, post, processing, errors } = useForm({ email: '' });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        post('/forgot-password');
    };

    return (
        <>
            <Head title="Forgot password" />

            <div className="flex flex-col gap-6">
                <div>
                    <h1 className="text-xl font-semibold text-text">Forgot your password?</h1>
                    <p className="mt-1 text-sm text-text-2">
                        Enter your email and we will send you a link to reset it, if an account exists for that
                        address.
                    </p>
                </div>

                {status && (
                    <p role="status" className="rounded-md bg-success-tint px-3 py-2 text-sm text-success">
                        {status}
                    </p>
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

                    <button
                        type="submit"
                        disabled={processing}
                        className="rounded-md bg-accent-fill px-4 py-2 text-sm font-medium text-accent-on transition-colors hover:bg-accent-fill-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {processing ? 'Sending…' : 'Send reset link'}
                    </button>
                </form>

                <Link href="/login" className="text-sm text-accent-text hover:underline">
                    Back to log in
                </Link>
            </div>
        </>
    );
}

ForgotPassword.layout = (page: ReactNode) => <GuestLayout>{page}</GuestLayout>;
