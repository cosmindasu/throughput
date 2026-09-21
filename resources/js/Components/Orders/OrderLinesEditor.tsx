import { useId } from 'react';
import { useTranslation } from 'react-i18next';
import VariantCombobox from '@/Components/Orders/VariantCombobox';
import { controlClass } from '@/Components/Form/Field';
import { useLocale } from '@/hooks/useLocale';
import { formatMoney } from '@/lib/money';
import type { OrderVariantOption } from '@/types/generated';

export interface OrderLineFormRow {
    /** Identificator DOAR local (React key) — nu se trimite la server. */
    key: string;
    variant_id: string;
    label: string;
    quantity: string;
    unit_price: string;
    discount: string;
    /** `null` cât timp linia n-a fost aleasă prin combobox (nu ar trebui să se întâmple). */
    available: number | null;
}

let counter = 0;

export function newLineFromVariant(variant: OrderVariantOption): OrderLineFormRow {
    counter += 1;

    return {
        key: `${variant.id}-${counter}`,
        variant_id: variant.id,
        label: `${variant.name} · ${variant.sku}`,
        quantity: '1',
        unit_price: String(variant.price),
        discount: '0',
        available: variant.available,
    };
}

/**
 * US-ORD-01/US-STOCK-02 — editorul de linii comun `Orders/Create` și `Orders/Edit`:
 * căutare de variantă (`VariantCombobox`), cantitate/preț/discount editabile,
 * „Available" afișat per linie (§10.5) și un total live (§11.1 — „recalculat live pe
 * măsură ce modific cantitățile").
 */
export default function OrderLinesEditor({
    currency,
    lines,
    onChange,
    error,
}: {
    currency: string;
    lines: OrderLineFormRow[];
    onChange: (lines: OrderLineFormRow[]) => void;
    error?: string;
}) {
    const { t } = useTranslation('orders');
    const locale = useLocale();
    const tableId = useId();

    const addLine = (variant: OrderVariantOption) => {
        onChange([...lines, newLineFromVariant(variant)]);
    };

    const updateLine = (key: string, patch: Partial<OrderLineFormRow>) => {
        onChange(lines.map((line) => (line.key === key ? { ...line, ...patch } : line)));
    };

    const removeLine = (key: string) => {
        onChange(lines.filter((line) => line.key !== key));
    };

    const lineTotal = (line: OrderLineFormRow): number => {
        const quantity = Number(line.quantity) || 0;
        const unitPrice = Number(line.unit_price) || 0;
        const discount = Number(line.discount) || 0;

        return Math.max(0, quantity * unitPrice - discount);
    };

    const grandTotal = lines.reduce((sum, line) => sum + lineTotal(line), 0);

    return (
        <div className="flex flex-col gap-3">
            <VariantCombobox onSelect={addLine} />

            {error && (
                <p role="alert" className="text-xs text-danger">
                    {error}
                </p>
            )}

            {lines.length === 0 ? (
                <p className="text-sm text-text-3">{t('linesEditor.empty')}</p>
            ) : (
                <div className="overflow-x-auto rounded-lg border border-border bg-surface">
                    <table id={tableId} className="w-full text-left text-sm">
                        <caption className="sr-only">{t('linesEditor.table.caption')}</caption>
                        <thead>
                            <tr className="border-b border-border-soft text-xs text-text-3">
                                <th scope="col" className="px-3 py-2 font-medium">
                                    {t('linesEditor.table.variant')}
                                </th>
                                <th scope="col" className="px-3 py-2 text-right font-medium">
                                    {t('linesEditor.table.available')}
                                </th>
                                <th scope="col" className="px-3 py-2 text-right font-medium">
                                    {t('linesEditor.table.quantity')}
                                </th>
                                <th scope="col" className="px-3 py-2 text-right font-medium">
                                    {t('linesEditor.table.unitPrice')}
                                </th>
                                <th scope="col" className="px-3 py-2 text-right font-medium">
                                    {t('linesEditor.table.discount')}
                                </th>
                                <th scope="col" className="px-3 py-2 text-right font-medium">
                                    {t('linesEditor.table.lineTotal')}
                                </th>
                                <th scope="col" className="px-3 py-2">
                                    <span className="sr-only">{t('linesEditor.table.removeColumn')}</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {lines.map((line) => {
                                const quantity = Number(line.quantity) || 0;
                                const exceedsAvailable = line.available !== null && quantity > line.available;

                                return (
                                    <tr key={line.key} className="border-b border-border-soft last:border-b-0">
                                        <td className="px-3 py-2">{line.label}</td>
                                        <td className={`numeric px-3 py-2 text-right ${exceedsAvailable ? 'text-warning' : 'text-text-2'}`}>
                                            {line.available ?? '—'}
                                        </td>
                                        <td className="px-3 py-2 text-right">
                                            <input
                                                type="number"
                                                min="1"
                                                step="1"
                                                aria-invalid={exceedsAvailable ? true : undefined}
                                                aria-label={t('linesEditor.quantityLabel', { label: line.label })}
                                                className={`${controlClass} w-24 text-right numeric`}
                                                value={line.quantity}
                                                onChange={(event) => updateLine(line.key, { quantity: event.target.value })}
                                            />
                                        </td>
                                        <td className="px-3 py-2 text-right">
                                            <input
                                                type="number"
                                                min="0"
                                                step="0.01"
                                                aria-label={t('linesEditor.unitPriceLabel', { label: line.label })}
                                                className={`${controlClass} w-28 text-right numeric`}
                                                value={line.unit_price}
                                                onChange={(event) => updateLine(line.key, { unit_price: event.target.value })}
                                            />
                                        </td>
                                        <td className="px-3 py-2 text-right">
                                            <input
                                                type="number"
                                                min="0"
                                                step="0.01"
                                                aria-label={t('linesEditor.discountLabel', { label: line.label })}
                                                className={`${controlClass} w-24 text-right numeric`}
                                                value={line.discount}
                                                onChange={(event) => updateLine(line.key, { discount: event.target.value })}
                                            />
                                        </td>
                                        <td className="numeric whitespace-nowrap px-3 py-2 text-right">{formatMoney(lineTotal(line), currency, locale)}</td>
                                        <td className="px-3 py-2 text-right">
                                            {/* SC 2.4.4 / 4.1.2 — „Remove" identic pe fiecare linie. */}
                                            <button
                                                type="button"
                                                onClick={() => removeLine(line.key)}
                                                className="rounded text-xs text-danger underline underline-offset-2 hover:no-underline"
                                            >
                                                {t('linesEditor.remove')}<span className="sr-only"> {line.label}</span>
                                            </button>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colSpan={5} className="px-3 py-2 text-right text-sm font-medium text-text-2">
                                    {t('linesEditor.total')}
                                </td>
                                <td className="numeric whitespace-nowrap px-3 py-2 text-right text-sm font-semibold text-text">
                                    {formatMoney(grandTotal, currency, locale)}
                                </td>
                                <td />
                            </tr>
                        </tfoot>
                    </table>
                </div>
            )}

            {lines.some((line) => line.available !== null && (Number(line.quantity) || 0) > (line.available ?? 0)) && (
                <p className="rounded-md bg-warning-tint px-3 py-2 text-xs text-warning">{t('linesEditor.warning')}</p>
            )}
        </div>
    );
}
