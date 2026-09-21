import { Head, Link, useForm, usePage } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { Trans, useTranslation } from 'react-i18next';
import Button from '@/Components/Button';
import Field, { controlClass } from '@/Components/Form/Field';
import GuestLayout from '@/Layouts/GuestLayout';
import { roleLabel } from '@/lib/roles';
import type { AcceptInvitationPageProps } from '@/types/generated';

/**
 * US-TEN-01, capătul public — „colegul primește un email cu link de acceptare valid 7 zile".
 *
 * Pagină PUBLICĂ (`routes/web/invitations.php`), pe `GuestLayout` ca restul fluxurilor fără
 * workspace (login, resetare parolă): nu există încă niciun workspace de așezat în jurul ei
 * — chiar asta e ce urmează să obțină vizitatorul.
 *
 * Două forme, decise SERVER-SIDE prin `needsProfile`: invitatul care nu are încă un cont
 * Throughput își alege nume și parolă aici; cel care e deja utilizator (membru în altă
 * organizație) doar confirmă. Decizia nu se ia în React — ar fi însemnat să deducem din
 * props dacă un cont există, adică un oracol de enumerare a adreselor pentru oricine ar
 * ghici un link.
 */
export default function AcceptInvitation() {
    const page = usePage<AcceptInvitationPageProps>();
    const { workspaceName, email, roleName, expired, needsProfile, acceptUrl } = page.props;
    const { t } = useTranslation('auth');

    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        password: '',
        password_confirmation: '',
    });

    // `errors.token` NU e o eroare de CÂMP (nu există niciun input „token" în formular) —
    // e starea invitației, respinsă de server după ce pagina era deja deschisă. `useForm`
    // tipizează `errors` strict pe cheile lui `data`, deci se citește din props-ul comun.
    const stateError = page.props.errors?.token;

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        post(acceptUrl, {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    if (expired) {
        return (
            <>
                <Head title={t('auth:invitations.expiredTitle')} />

                <div className="flex flex-col gap-6">
                    <div>
                        <h1 className="text-xl font-semibold text-text">{t('auth:invitations.expiredHeading')}</h1>
                        <p className="mt-1 text-sm text-text-2">
                            <Trans
                                t={t}
                                i18nKey="auth:invitations.expiredBody"
                                values={{ workspaceName }}
                                components={{ strong: <strong /> }}
                            />
                        </p>
                    </div>

                    <Link href="/login" className="text-sm text-accent-text hover:underline">
                        {t('auth:invitations.backToLogin')}
                    </Link>
                </div>
            </>
        );
    }

    return (
        <>
            <Head title={t('auth:invitations.joinHeading', { workspaceName })} />

            <div className="flex flex-col gap-6">
                <div>
                    <h1 className="text-xl font-semibold text-text">{t('auth:invitations.joinHeading', { workspaceName })}</h1>
                    <p className="mt-1 text-sm text-text-2">
                        <Trans
                            t={t}
                            i18nKey="auth:invitations.invitedAs"
                            values={{ roleName: roleLabel(t, roleName) ?? roleName, email }}
                            components={{ strong: <strong /> }}
                        />
                    </p>
                </div>

                <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
                    {/* Eroare FĂRĂ câmp — invitația revocată, retrimisă sau expirată între
                        afișarea paginii și trimiterea formularului
                        (`AcceptInvitationController::accept()`, cheia `token`). `role="alert"`,
                        nu un banner global: e despre exact acest formular. */}
                    {stateError && (
                        <p role="alert" className="rounded-md bg-danger-tint px-3 py-2 text-sm text-danger">
                            {stateError}
                        </p>
                    )}

                    {needsProfile && (
                        <>
                            <Field label={t('auth:invitations.nameLabel')} error={errors.name} required>
                                {(control) => (
                                    <input
                                        {...control}
                                        type="text"
                                        autoComplete="name"
                                        className={controlClass}
                                        value={data.name}
                                        onChange={(event) => setData('name', event.target.value)}
                                    />
                                )}
                            </Field>

                            <Field label={t('auth:invitations.passwordLabel')} error={errors.password} required>
                                {(control) => (
                                    <input
                                        {...control}
                                        type="password"
                                        autoComplete="new-password"
                                        className={controlClass}
                                        value={data.password}
                                        onChange={(event) => setData('password', event.target.value)}
                                    />
                                )}
                            </Field>

                            <Field label={t('auth:invitations.confirmPasswordLabel')} error={errors.password_confirmation} required>
                                {(control) => (
                                    <input
                                        {...control}
                                        type="password"
                                        autoComplete="new-password"
                                        className={controlClass}
                                        value={data.password_confirmation}
                                        onChange={(event) => setData('password_confirmation', event.target.value)}
                                    />
                                )}
                            </Field>
                        </>
                    )}

                    {!needsProfile && (
                        <p className="text-sm text-text-2">
                            {t('auth:invitations.existingAccountBody', { workspaceName })}
                        </p>
                    )}

                    <div>
                        <Button type="submit" variant="primary" aria-disabled={processing ? true : undefined}>
                            {processing ? t('auth:invitations.submitting') : t('auth:invitations.submit', { workspaceName })}
                        </Button>
                    </div>
                </form>
            </div>
        </>
    );
}

AcceptInvitation.layout = (page: ReactNode) => <GuestLayout>{page}</GuestLayout>;
