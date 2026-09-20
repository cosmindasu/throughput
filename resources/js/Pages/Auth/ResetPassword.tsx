import { Head, Link, useForm, usePage } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import Button from '@/Components/Button';
import GuestLayout from '@/Layouts/GuestLayout';
import type { ResetPasswordPageProps } from '@/types/generated';

/**
 * Formular de resetare a parolei (FR-PUB-05). `token`/`email` vin din props
 * (link-ul din email conține tokenul), sunt incluse în payload-ul `useForm`
 * fără a mai avea nevoie de input-uri ascunse — Inertia trimite `data` ca
 * atare, nu serializează DOM-ul formularului.
 *
 * La succes, serverul apelează `Auth::logoutOtherDevices()` necondiționat
 * (specs.md §4.5) — nimic de făcut aici pe partea de React.
 */
export default function ResetPassword() {
    const { token, email } = usePage<ResetPasswordPageProps>().props;
    const { data, setData, post, processing, errors, reset } = useForm({
        token,
        email,
        password: '',
        password_confirmation: '',
    });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (processing) {
            return;
        }

        post('/reset-password', {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <>
            <Head title="Reset password" />

            <div className="flex flex-col gap-6">
                <div>
                    <h1 className="text-xl font-semibold text-text">Choose a new password</h1>
                    <p className="mt-1 text-sm text-text-2">
                        Other devices signed into this account will be logged out automatically once this is saved.
                    </p>
                </div>

                <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
                    <div>
                        <label htmlFor="email" className="text-sm font-medium text-text">
                            Email
                        </label>
                        <input
                            id="email"
                            type="email"
                            readOnly
                            value={data.email}
                            className="mt-1 w-full cursor-not-allowed rounded-md border border-control bg-raised px-3 py-2 text-sm text-text-2"
                        />
                    </div>

                    <div>
                        <label htmlFor="password" className="text-sm font-medium text-text">
                            New password
                        </label>
                        <input
                            id="password"
                            type="password"
                            autoComplete="new-password"
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

                    <div>
                        <label htmlFor="password_confirmation" className="text-sm font-medium text-text">
                            Confirm new password
                        </label>
                        <input
                            id="password_confirmation"
                            type="password"
                            autoComplete="new-password"
                            value={data.password_confirmation}
                            onChange={(event) => setData('password_confirmation', event.target.value)}
                            aria-invalid={Boolean(errors.password_confirmation)}
                            aria-describedby={
                                errors.password_confirmation ? 'password-confirmation-error' : undefined
                            }
                            className="mt-1 w-full rounded-md border border-control bg-surface px-3 py-2 text-sm text-text focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                        />
                        {errors.password_confirmation && (
                            <p id="password-confirmation-error" role="alert" className="mt-1 text-sm text-danger">
                                {errors.password_confirmation}
                            </p>
                        )}
                    </div>

                    {/* `pending`, nu `disabled` nativ: `disabled` pe butonul care ARE focusul
                        (exact cel tocmai apăsat) îl blurează, iar focusul cade pe `<body>` —
                        pe un ecran de autentificare asta înseamnă că utilizatorul de tastatură
                        își pierde locul chiar cât serverul lucrează. `Button` face acum și
                        `preventDefault` pe clic, deci al doilea submit rămâne blocat. */}
                    <Button type="submit" variant="primary" className="px-4 py-2" pending={processing} pendingLabel="Saving…">
                        Reset password
                    </Button>
                </form>

                <Link href="/login" className="text-sm text-accent-text hover:underline">
                    Back to log in
                </Link>
            </div>
        </>
    );
}

ResetPassword.layout = (page: ReactNode) => <GuestLayout>{page}</GuestLayout>;
