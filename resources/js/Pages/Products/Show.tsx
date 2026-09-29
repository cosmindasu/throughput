import { Head, router, usePage } from '@inertiajs/react';
import { Fragment, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import Button, { ButtonLink } from '@/Components/Button';
import ConfirmDialog from '@/Components/ConfirmDialog';
import EmptyState from '@/Components/EmptyState';
import HistoryTab from '@/Components/History/HistoryTab';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { useLocale } from '@/hooks/useLocale';
import AppLayout from '@/Layouts/AppLayout';
import { formatDecimal } from '@/lib/format';
import type { ProductsShowPageProps, VariantRow } from '@/types/generated';

/**
 * Products/Show — produsul + variantele lui (specs.md §10.2). Fiecare variantă are
 * link direct spre stocul ei (`Stock/Show`) și istoric (`Stock/History`), plus editare.
 */
export default function Show() {
    const { t } = useTranslation('products');
    const locale = useLocale();
    const { product, deletionBlockedReason, can, workspace } = usePage<ProductsShowPageProps>().props;
    const base = workspace ? `/${workspace.slug}` : '';
    const [confirmingDelete, setConfirmingDelete] = useState(false);
    const [deleting, setDeleting] = useState(false);
    // FE-01 (audit) — dialogul se închide DOAR la succes; la eroare rămâne deschis, cu
    // mesajul afișat în `role="alert"` chiar în el (`.ai/rules/frontend.md`, „Dialogul
    // închis și la eroare”).
    const [deleteError, setDeleteError] = useState<string | null>(null);
    // FR-AUD-02, §17.3 — istoricul unei variante (US-AUD-01: „prețul variantei X a fost
    // schimbat de 3 ori") se arată inline, sub rândul ei, nu pe o pagină separată (variantele
    // n-au propriul Show — vezi raportul lotului). Un singur rând deschis o dată.
    const [expandedVariantId, setExpandedVariantId] = useState<string | null>(null);

    const destroy = () => {
        setDeleting(true);
        setDeleteError(null);
        router.delete(`${base}/products/${product.id}`, {
            onSuccess: () => setConfirmingDelete(false),
            onError: (errors) => setDeleteError(Object.values(errors)[0] ?? t('products:show.deleteError')),
            onFinish: () => setDeleting(false),
        });
    };

    return (
        <>
            <Head title={product.name} />

            <div className="flex flex-col gap-6">
                <PageHeader
                    title={product.name}
                    description={
                        <span className="flex items-center gap-2">
                            <StatusBadge tone={product.isActive ? 'success' : 'neutral'}>
                                {product.isActive ? t('products:badges.active') : t('products:badges.inactive')}
                            </StatusBadge>
                            {product.category && <span>{product.category}</span>}
                            <span>·</span>
                            <span>{t(`products:unitOfMeasure.${product.unitOfMeasure}`)}</span>
                        </span>
                    }
                    actions={
                        <>
                            {can.createVariant && (
                                <ButtonLink href={`${base}/products/${product.id}/variants/create`}>{t('products:show.addVariant')}</ButtonLink>
                            )}
                            {can.edit && <ButtonLink href={`${base}/products/${product.id}/edit`}>{t('products:actions.edit')}</ButtonLink>}
                            {can.delete && (
                                <Button variant="danger" onClick={() => setConfirmingDelete(true)}>
                                    {t('products:actions.delete')}
                                </Button>
                            )}
                        </>
                    }
                />

                <section aria-label={t('products:show.variantsHeading')} className="flex flex-col gap-3">
                    <h2 id="variants-heading" className="text-sm font-medium text-text">
                        {t('products:show.variantsHeading')}
                    </h2>

                    {product.variants.length === 0 ? (
                        <EmptyState message={t('products:show.empty')} />
                    ) : (
                        <div className="overflow-hidden rounded-lg border border-border">
                            {/* Excepția de la convenția cu `<caption>`: tabelul are un heading
                                vizibil DEDICAT chiar deasupra, deci se leagă de el — o singură
                                sursă de adevăr pentru nume, nu un caption invizibil care poate
                                diverge de heading. Vezi `.ai/rules/frontend.md`. */}
                            <table className="data-table w-full text-left text-sm" aria-labelledby="variants-heading">
                                <thead className="bg-raised text-text-2">
                                    <tr>
                                        <th scope="col" className="px-4 py-2 font-medium">
                                            {t('products:show.columns.sku')}
                                        </th>
                                        <th scope="col" className="px-4 py-2 font-medium">
                                            {t('products:show.columns.price')}
                                        </th>
                                        {can.edit && (
                                            <th scope="col" className="px-4 py-2 font-medium">
                                                {t('products:show.columns.cost')}
                                            </th>
                                        )}
                                        <th scope="col" className="px-4 py-2 font-medium">
                                            {t('products:show.columns.available')}
                                        </th>
                                        <th scope="col" className="px-4 py-2 font-medium">
                                            <span className="sr-only">{t('products:badges.lowStock')}</span>
                                        </th>
                                        <th scope="col" className="px-4 py-2 font-medium">
                                            {t('products:labels.status')}
                                        </th>
                                        <th scope="col" className="px-4 py-2 font-medium">
                                            <span className="sr-only">{t('products:show.columns.actions')}</span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-border-soft bg-surface">
                                    {product.variants.map((variant: VariantRow) => (
                                        <Fragment key={variant.id}>
                                            <tr>
                                                <td className="px-4 py-2.5 font-medium text-text">{variant.sku}</td>
                                                <td className="px-4 py-2.5 tabular-nums text-text-2">{formatDecimal(variant.price, locale)}</td>
                                                {can.edit && (
                                                    <td className="px-4 py-2.5 tabular-nums text-text-2">
                                                        {variant.cost !== undefined ? formatDecimal(variant.cost, locale) : '—'}
                                                    </td>
                                                )}
                                                <td className="px-4 py-2.5 tabular-nums text-text-2">
                                                    {variant.available !== undefined ? variant.available : '—'}
                                                </td>
                                                <td className="px-4 py-2.5">
                                                    {variant.isLowStock && <StatusBadge tone="warning">{t('products:badges.lowStock')}</StatusBadge>}
                                                </td>
                                                <td className="px-4 py-2.5">
                                                    <StatusBadge tone={variant.isActive ? 'success' : 'neutral'}>
                                                        {variant.isActive ? t('products:badges.active') : t('products:badges.inactive')}
                                                    </StatusBadge>
                                                </td>
                                                <td className="px-4 py-2.5 text-right">
                                                    <div className="flex justify-end gap-3">
                                                        {/* SC 2.4.4 / 4.1.2 — „Stock"/„History"/„Change log"/„Edit"
                                                            se repetă identic pe fiecare variantă. Discriminator
                                                            `sr-only` DUPĂ textul vizibil (SC 2.5.3 Label in Name). */}
                                                        <a href={`${base}/variants/${variant.id}/stock`} className="text-accent-text hover:underline">
                                                            {t('products:actions.stock')}
                                                            <span className="sr-only"> {t('products:show.forSku', { sku: variant.sku })}</span>
                                                        </a>
                                                        <a
                                                            href={`${base}/variants/${variant.id}/stock/history`}
                                                            className="text-accent-text hover:underline"
                                                        >
                                                            {t('products:actions.history')}
                                                            <span className="sr-only"> {t('products:show.forSku', { sku: variant.sku })}</span>
                                                        </a>
                                                        {/* FR-AUD-02 — "Change log" (nu "History", deja folosit mai sus
                                                            pentru istoricul de STOC) — jurnalul de modificări ale
                                                            variantei înseși (preț, stare), US-AUD-01. */}
                                                        <button
                                                            type="button"
                                                            onClick={() => setExpandedVariantId((current) => (current === variant.id ? null : variant.id))}
                                                            aria-expanded={expandedVariantId === variant.id}
                                                            className="text-accent-text hover:underline"
                                                        >
                                                            {t('products:actions.changeLog')}
                                                            <span className="sr-only"> {t('products:show.forSku', { sku: variant.sku })}</span>
                                                        </button>
                                                        {can.edit && (
                                                            <a href={`${base}/variants/${variant.id}/edit`} className="text-accent-text hover:underline">
                                                                {t('products:actions.edit')}
                                                                <span className="sr-only"> {variant.sku}</span>
                                                            </a>
                                                        )}
                                                    </div>
                                                </td>
                                            </tr>
                                            {expandedVariantId === variant.id && (
                                                <tr>
                                                    <td colSpan={can.edit ? 7 : 6} className="bg-raised px-4 py-3">
                                                        <HistoryTab entityType="variant" entityId={variant.id} />
                                                    </td>
                                                </tr>
                                            )}
                                        </Fragment>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>

                {/* FR-AUD-02, §17.3 — istoricul PRODUSULUI însuși (nume, categorie, activ/
                    inactiv); istoricul per variantă e „Change log", pe rândul ei, mai sus. */}
                <section aria-label={t('products:show.historyHeading')} className="flex flex-col gap-3">
                    <h2 className="text-sm font-medium text-text">{t('products:show.historyHeading')}</h2>
                    <HistoryTab entityType="product" entityId={product.id} />
                </section>
            </div>

            <ConfirmDialog
                open={confirmingDelete}
                title={deletionBlockedReason ? t('products:show.deleteBlockedTitle') : t('products:show.deleteConfirmTitle', { name: product.name })}
                onConfirm={deletionBlockedReason ? undefined : destroy}
                confirmVariant="danger"
                confirmLabel={t('products:actions.delete')}
                processing={deleting}
                onClose={() => {
                    setConfirmingDelete(false);
                    setDeleteError(null);
                }}
            >
                {deleteError && (
                    <p role="alert" className="mb-2 rounded-md bg-danger-tint px-2 py-1.5 text-danger">
                        {deleteError}
                    </p>
                )}
                {/* `deletionBlockedReason` vine deja tradus din backend (mesaj Laravel,
                    App::setLocale() din LocalePreference) — nu se retraduce aici. */}
                {deletionBlockedReason ?? t('products:show.deleteWarning')}
            </ConfirmDialog>
        </>
    );
}

Show.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
