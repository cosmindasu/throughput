import { Head, useForm, usePage } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import Button, { ButtonLink } from '@/Components/Button';
import Field, { controlClass } from '@/Components/Form/Field';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import AppLayout from '@/Layouts/AppLayout';
import type { StockShowPageProps } from '@/types/generated';

/**
 * Stock/Show — nivelurile unei variante, per locație, plus formularele de recepție,
 * ajustare și transfer (specs.md §10, US-STOCK-01…03). Formularele lipsesc integral
 * pentru rolurile fără `stock.adjust` (§7.3 — „aplicația stricată" dacă ar apărea
 * dezactivate), nu doar dezactivate.
 */
export default function Show() {
    const { variant, levels, locations, can, workspace } = usePage<StockShowPageProps>().props;
    const base = workspace ? `/${workspace.slug}` : '';

    return (
        <>
            <Head title={`Stock — ${variant.sku}`} />

            <div className="flex flex-col gap-6">
                <PageHeader
                    title={`Stock — ${variant.sku}`}
                    description={
                        variant.isLowStock ? (
                            <span className="flex items-center gap-2">
                                <StatusBadge tone="warning">Low stock</StatusBadge>
                                <span>Available is below the threshold of {variant.lowStockThreshold}.</span>
                            </span>
                        ) : undefined
                    }
                    actions={
                        <ButtonLink href={`${base}/variants/${variant.id}/stock/history`}>View history</ButtonLink>
                    }
                />

                <div className="overflow-hidden rounded-lg border border-border">
                    <table className="w-full text-left text-sm">
                        {/* Convenția implicită de nume pentru un tabel fără heading propriu
                            deasupra: `<caption class="sr-only">` (tehnica H39), nu un
                            `aria-labelledby` către titlul PAGINII. Vezi `.ai/rules/frontend.md`. */}
                        <caption className="sr-only">Stock levels by location</caption>
                        <thead className="bg-raised text-text-2">
                            <tr>
                                <th scope="col" className="px-4 py-2 font-medium">Location</th>
                                <th scope="col" className="px-4 py-2 font-medium">On hand</th>
                                <th scope="col" className="px-4 py-2 font-medium">Reserved</th>
                                <th scope="col" className="px-4 py-2 font-medium">Available</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-border-soft bg-surface">
                            {levels.length === 0 ? (
                                <tr>
                                    <td colSpan={4} className="px-4 py-6 text-center text-text-2">
                                        No stock recorded at any location yet.
                                    </td>
                                </tr>
                            ) : (
                                levels.map((level) => (
                                    <tr key={level.id}>
                                        <td className="px-4 py-2.5 text-text">{level.locationName}</td>
                                        <td className="px-4 py-2.5 tabular-nums text-text-2">{level.onHand}</td>
                                        <td className="px-4 py-2.5 tabular-nums text-text-2">{level.reserved}</td>
                                        <td className="px-4 py-2.5 tabular-nums font-medium text-text">{level.available}</td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                {can.adjust && (
                    <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                        <ReceiveForm action={`${base}/variants/${variant.id}/stock/receive`} locations={locations} />
                        <AdjustForm action={`${base}/variants/${variant.id}/stock/adjust`} locations={locations} />
                        <TransferForm action={`${base}/variants/${variant.id}/stock/transfer`} locations={locations} />
                    </div>
                )}
            </div>
        </>
    );
}

interface LocationOption {
    id: string;
    name: string;
}

function ReceiveForm({ action, locations }: { action: string; locations: LocationOption[] }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        location_id: locations[0]?.id ?? '',
        quantity: '',
        note: '',
    });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        // Butonul e `aria-disabled`, nu `disabled` nativ (rămâne focusabil) — al doilea
        // submit se oprește aici, nu de browser.
        if (processing) {
            return;
        }

        post(action, { preserveScroll: true, onSuccess: () => reset('quantity', 'note') });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4" noValidate>
            <h2 className="text-sm font-medium text-text">Receive stock</h2>

            <Field label="Location" error={errors.location_id}>
                {(control) => (
                    <select {...control} className={controlClass} value={data.location_id} onChange={(event) => setData('location_id', event.target.value)}>
                        {locations.map((location) => (
                            <option key={location.id} value={location.id}>
                                {location.name}
                            </option>
                        ))}
                    </select>
                )}
            </Field>

            <Field label="Quantity" error={errors.quantity} required>
                {(control) => (
                    <input {...control} type="number" min="1" className={controlClass} value={data.quantity} onChange={(event) => setData('quantity', event.target.value)} />
                )}
            </Field>

            <Field label="Note" error={errors.note} hint="Optional — e.g. a PO number.">
                {(control) => (
                    <input {...control} className={controlClass} value={data.note} onChange={(event) => setData('note', event.target.value)} />
                )}
            </Field>

            <Button type="submit" variant="primary" pending={processing} pendingLabel="Receiving…">
                Receive
            </Button>
        </form>
    );
}

function AdjustForm({ action, locations }: { action: string; locations: LocationOption[] }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        location_id: locations[0]?.id ?? '',
        delta: '',
        note: '',
    });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        // Butonul e `aria-disabled`, nu `disabled` nativ (rămâne focusabil) — al doilea
        // submit se oprește aici, nu de browser.
        if (processing) {
            return;
        }

        post(action, { preserveScroll: true, onSuccess: () => reset('delta', 'note') });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4" noValidate>
            <h2 className="text-sm font-medium text-text">Adjust stock</h2>

            <Field label="Location" error={errors.location_id}>
                {(control) => (
                    <select {...control} className={controlClass} value={data.location_id} onChange={(event) => setData('location_id', event.target.value)}>
                        {locations.map((location) => (
                            <option key={location.id} value={location.id}>
                                {location.name}
                            </option>
                        ))}
                    </select>
                )}
            </Field>

            <Field label="Change" error={errors.delta} required hint="Positive to add, negative to remove — e.g. -3.">
                {(control) => (
                    <input {...control} type="number" className={controlClass} value={data.delta} onChange={(event) => setData('delta', event.target.value)} />
                )}
            </Field>

            <Field label="Reason" error={errors.note} required hint="Required — why is this quantity being corrected?">
                {(control) => (
                    <input {...control} className={controlClass} value={data.note} onChange={(event) => setData('note', event.target.value)} />
                )}
            </Field>

            <Button type="submit" variant="primary" pending={processing} pendingLabel="Adjusting…">
                Adjust
            </Button>
        </form>
    );
}

function TransferForm({ action, locations }: { action: string; locations: LocationOption[] }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        from_location_id: locations[0]?.id ?? '',
        to_location_id: locations[1]?.id ?? locations[0]?.id ?? '',
        quantity: '',
        note: '',
    });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        // Butonul e `aria-disabled`, nu `disabled` nativ (rămâne focusabil) — al doilea
        // submit se oprește aici, nu de browser.
        if (processing) {
            return;
        }

        post(action, { preserveScroll: true, onSuccess: () => reset('quantity', 'note') });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4" noValidate>
            <h2 className="text-sm font-medium text-text">Transfer stock</h2>

            <Field label="From" error={errors.from_location_id}>
                {(control) => (
                    <select {...control} className={controlClass} value={data.from_location_id} onChange={(event) => setData('from_location_id', event.target.value)}>
                        {locations.map((location) => (
                            <option key={location.id} value={location.id}>
                                {location.name}
                            </option>
                        ))}
                    </select>
                )}
            </Field>

            <Field label="To" error={errors.to_location_id}>
                {(control) => (
                    <select {...control} className={controlClass} value={data.to_location_id} onChange={(event) => setData('to_location_id', event.target.value)}>
                        {locations.map((location) => (
                            <option key={location.id} value={location.id}>
                                {location.name}
                            </option>
                        ))}
                    </select>
                )}
            </Field>

            <Field label="Quantity" error={errors.quantity} required>
                {(control) => (
                    <input {...control} type="number" min="1" className={controlClass} value={data.quantity} onChange={(event) => setData('quantity', event.target.value)} />
                )}
            </Field>

            <Field label="Note" error={errors.note} hint="Optional.">
                {(control) => (
                    <input {...control} className={controlClass} value={data.note} onChange={(event) => setData('note', event.target.value)} />
                )}
            </Field>

            <Button type="submit" variant="primary" pending={processing} pendingLabel="Transferring…">
                Transfer
            </Button>
        </form>
    );
}

Show.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
