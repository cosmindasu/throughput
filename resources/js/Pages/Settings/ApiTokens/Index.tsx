import { Head, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import Button from '@/Components/Button';
import ConfirmDialog from '@/Components/ConfirmDialog';
import EmptyState from '@/Components/EmptyState';
import Field, { controlClass } from '@/Components/Form/Field';
import PageHeader from '@/Components/PageHeader';
import StatusBadge, { type BadgeTone } from '@/Components/StatusBadge';
import AppLayout from '@/Layouts/AppLayout';
import type { ApiTokenAbilityOption, ApiTokenRow, ApiTokensIndexPageProps, ApiTokenStatus } from '@/types/generated';

const statusTones: Record<ApiTokenStatus, BadgeTone> = {
    active: 'success',
    expired: 'warning',
    revoked: 'neutral',
};

function formatWhen(value: string | null): string {
    if (value === null) {
        return '—';
    }

    return new Date(value).toLocaleString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}

/**
 * Jetonul în clar, arătat exact o dată.
 *
 * Focusul se mută EXPLICIT pe această regiune după creare: declanșatorul („Create token")
 * rămâne în pagină, dar un utilizator de tastatură care tocmai a generat un secret trebuie
 * dus la el, nu lăsat la începutul formularului — a treia formă a capcanei din
 * `.ai/rules/frontend.md` („declanșatorul dispare după succes"), aplicată preventiv.
 */
function NewTokenNotice({ token }: { token: string }) {
    const ref = useRef<HTMLDivElement>(null);
    const [copied, setCopied] = useState(false);

    useEffect(() => {
        ref.current?.focus();
    }, []);

    return (
        <div
            ref={ref}
            tabIndex={-1}
            role="status"
            className="flex flex-col gap-2 rounded-lg border border-accent-text bg-accent-tint p-4"
        >
            <p className="text-sm font-medium text-accent-text">
                Copy this token now — it is never shown again.
            </p>
            <div className="flex flex-wrap items-center gap-2">
                <code className="numeric min-w-0 flex-1 overflow-x-auto rounded-md border border-border bg-surface px-3 py-2 text-xs text-text">
                    {token}
                </code>
                <Button
                    onClick={() => {
                        void navigator.clipboard?.writeText(token);
                        setCopied(true);
                    }}
                >
                    {copied ? 'Copied' : 'Copy'}
                </Button>
            </div>
        </div>
    );
}

function CreateTokenForm({ abilities, baseUrl }: { abilities: ApiTokenAbilityOption[]; baseUrl: string }) {
    const form = useForm<{ name: string; abilities: string[]; expires_at: string }>({
        name: '',
        abilities: [],
        expires_at: '',
    });

    const toggleAbility = (ability: string) => {
        form.setData(
            'abilities',
            form.data.abilities.includes(ability)
                ? form.data.abilities.filter((value) => value !== ability)
                : [...form.data.abilities, ability],
        );
    };

    return (
        <form
            className="flex flex-col gap-4 rounded-lg border border-border p-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(baseUrl, {
                    preserveScroll: true,
                    onSuccess: () => form.reset(),
                });
            }}
        >
            <h2 className="text-base font-semibold text-text">Create a token</h2>

            <Field
                label="Name"
                required
                error={form.errors.name}
                hint='What the token is for, e.g. "ERP sync".'
            >
                {(control) => (
                    <input
                        {...control}
                        type="text"
                        className={controlClass}
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                    />
                )}
            </Field>

            <fieldset className="flex flex-col gap-2">
                <legend className="text-sm font-medium text-text">
                    Scopes
                    <span aria-hidden="true" className="text-danger">
                        {' '}
                        *
                    </span>
                </legend>
                <p className="text-xs text-text-3">
                    A token can only do what its scopes allow — and never more than the person who issued it.
                </p>
                <div className="grid gap-1 sm:grid-cols-2">
                    {abilities.map((ability) => (
                        <label key={ability.value} className="flex items-start gap-2 text-sm text-text">
                            <input
                                type="checkbox"
                                className="mt-0.5 rounded border-control focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus"
                                checked={form.data.abilities.includes(ability.value)}
                                onChange={() => toggleAbility(ability.value)}
                            />
                            <span>
                                <code className="text-xs text-accent-text">{ability.value}</code>
                                <span className="block text-xs text-text-2">{ability.description}</span>
                            </span>
                        </label>
                    ))}
                </div>
                {form.errors.abilities && (
                    <p className="text-xs text-danger" role="alert">
                        {form.errors.abilities}
                    </p>
                )}
            </fieldset>

            <Field
                label="Expires at"
                error={form.errors.expires_at}
                hint="Optional. Leave empty for a token that never expires on its own."
            >
                {(control) => (
                    <input
                        {...control}
                        type="datetime-local"
                        className={controlClass}
                        value={form.data.expires_at}
                        onChange={(event) => form.setData('expires_at', event.target.value)}
                    />
                )}
            </Field>

            <div>
                <Button
                    type="submit"
                    variant="primary"
                    aria-disabled={form.processing || undefined}
                    onClick={form.processing ? (event) => event.preventDefault() : undefined}
                >
                    {form.processing ? 'Creating…' : 'Create token'}
                </Button>
            </div>
        </form>
    );
}

/**
 * Settings → API tokens (specs.md §18.1, FR-API-01/02, US-API-01).
 *
 * Tabelul e singurul de pe pagină și n-are heading propriu, deci intră pe cazul implicit
 * din `.ai/rules/frontend.md`: `<caption className="sr-only">`.
 */
export default function ApiTokensIndex() {
    const { tokens, abilities, plainTextToken, can, workspace } = usePage<ApiTokensIndexPageProps>().props;
    const [pendingRevoke, setPendingRevoke] = useState<ApiTokenRow | null>(null);
    const revokeForm = useForm({});
    // Segmentul de workspace se pune explicit în URL, ca pe toate ecranele cu
    // `{workspace}` în cale (ADR-002) — `URL::defaults` îl propagă doar server-side.
    const baseUrl = `/${workspace?.slug ?? ''}/settings/api-tokens`;

    return (
        <>
            <Head title="API tokens" />

            <div className="flex flex-col gap-6">
                <PageHeader
                    title="API tokens"
                    description="Tokens let an outside system read and write this workspace through the public API. The workspace comes from the token itself — never from the URL."
                />

                {plainTextToken && <NewTokenNotice token={plainTextToken} />}

                {can.create && <CreateTokenForm abilities={abilities} baseUrl={baseUrl} />}

                {tokens.length > 0 ? (
                    <div className="overflow-x-auto rounded-lg border border-border">
                        <table className="w-full text-left text-sm">
                            <caption className="sr-only">API tokens</caption>
                            <thead className="border-b border-border bg-surface text-text-2">
                                <tr>
                                    <th scope="col" className="px-4 py-2 font-medium">Name</th>
                                    <th scope="col" className="px-4 py-2 font-medium">Scopes</th>
                                    <th scope="col" className="px-4 py-2 font-medium">Created</th>
                                    <th scope="col" className="px-4 py-2 font-medium">Last used</th>
                                    <th scope="col" className="px-4 py-2 font-medium">Expires</th>
                                    <th scope="col" className="px-4 py-2 font-medium">Status</th>
                                    <th scope="col" className="px-4 py-2 font-medium">
                                        <span className="sr-only">Actions</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {tokens.map((token) => (
                                    <tr key={token.id} className="border-b border-border-soft last:border-0">
                                        <td className="px-4 py-3 font-medium text-text">{token.name}</td>
                                        <td className="px-4 py-3">
                                            <div className="flex flex-wrap gap-1">
                                                {token.abilities.map((ability) => (
                                                    <code key={ability} className="rounded bg-raised px-1.5 py-0.5 text-xs text-text-2">
                                                        {ability}
                                                    </code>
                                                ))}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3 text-text-2">
                                            <span className="numeric">{formatWhen(token.createdAt)}</span>
                                            {token.createdBy && <div className="text-xs">by {token.createdBy}</div>}
                                        </td>
                                        <td className="px-4 py-3 text-text-2 numeric">{formatWhen(token.lastUsedAt)}</td>
                                        <td className="px-4 py-3 text-text-2 numeric">{formatWhen(token.expiresAt)}</td>
                                        <td className="px-4 py-3">
                                            <StatusBadge tone={statusTones[token.status]}>{token.status}</StatusBadge>
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            {can.revoke && token.status !== 'revoked' && (
                                                <Button
                                                    variant="danger"
                                                    aria-label={`Revoke token ${token.name}`}
                                                    onClick={() => setPendingRevoke(token)}
                                                >
                                                    Revoke
                                                </Button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <EmptyState message="No API tokens yet. Create one to let an outside system talk to this workspace." />
                )}
            </div>

            <ConfirmDialog
                open={pendingRevoke !== null}
                title="Revoke this token?"
                confirmLabel="Revoke"
                confirmVariant="danger"
                processing={revokeForm.processing}
                onClose={() => setPendingRevoke(null)}
                onConfirm={() => {
                    if (pendingRevoke === null) {
                        return;
                    }

                    revokeForm.delete(`${baseUrl}/${pendingRevoke.id}`, {
                        preserveScroll: true,
                        onSuccess: () => setPendingRevoke(null),
                    });
                }}
            >
                <p className="text-sm text-text-2">
                    Any integration still using <strong className="text-text">{pendingRevoke?.name}</strong> stops working
                    immediately. This cannot be undone — issue a new token instead.
                </p>
            </ConfirmDialog>
        </>
    );
}

ApiTokensIndex.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
