import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import Button from '@/Components/Button';
import Field, { controlClass } from '@/Components/Form/Field';
import type { VariantRow } from '@/types/generated';

interface VariantFormProps {
    mode: 'create' | 'edit';
    variant?: VariantRow;
    action: string;
}

interface VariantFormData {
    sku: string;
    price: string;
    cost: string;
    weight: string;
    is_active: boolean;
    low_stock_threshold: string;
}

/**
 * Formular comun `Variants/Create` și `Variants/Edit` (specs.md §10.2). `attributes`
 * (jsonb liber, ex. `{"size": "M"}`) rămâne neexpus în MVP — nu figurează în task-ul
 * Pachetului A, iar un editor generic de chei/valori ar fi cost fără cerință care să-l
 * ceară explicit.
 */
export default function VariantForm({ mode, variant, action }: VariantFormProps) {
    const { data, setData, post, put, processing, errors } = useForm<VariantFormData>({
        sku: variant?.sku ?? '',
        price: variant ? String(variant.price) : '',
        cost: variant?.cost !== undefined ? String(variant.cost) : '',
        weight: variant?.weight !== null && variant?.weight !== undefined ? String(variant.weight) : '',
        is_active: variant?.isActive ?? true,
        low_stock_threshold: variant?.lowStockThreshold !== null && variant?.lowStockThreshold !== undefined ? String(variant.lowStockThreshold) : '',
    });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (mode === 'create') {
            post(action);
        } else {
            put(action);
        }
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-6" noValidate>
            <section className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Field label="SKU" error={errors.sku} required>
                    {(control) => (
                        <input
                            {...control}
                            className={controlClass}
                            value={data.sku}
                            onChange={(event) => setData('sku', event.target.value)}
                        />
                    )}
                </Field>

                <Field label="Price" error={errors.price} required>
                    {(control) => (
                        <input
                            {...control}
                            type="number"
                            step="0.01"
                            min="0"
                            className={controlClass}
                            value={data.price}
                            onChange={(event) => setData('price', event.target.value)}
                        />
                    )}
                </Field>

                <Field label="Cost" error={errors.cost} required hint="Never shown to Agent or Viewer accounts.">
                    {(control) => (
                        <input
                            {...control}
                            type="number"
                            step="0.01"
                            min="0"
                            className={controlClass}
                            value={data.cost}
                            onChange={(event) => setData('cost', event.target.value)}
                        />
                    )}
                </Field>

                <Field label="Weight" error={errors.weight} hint="Optional — used for shipping calculations later.">
                    {(control) => (
                        <input
                            {...control}
                            type="number"
                            step="0.001"
                            min="0"
                            className={controlClass}
                            value={data.weight}
                            onChange={(event) => setData('weight', event.target.value)}
                        />
                    )}
                </Field>

                <Field
                    label="Low stock threshold"
                    error={errors.low_stock_threshold}
                    hint="Optional. Shows a Low stock indicator when available drops below this number — leave empty for no alert."
                >
                    {(control) => (
                        <input
                            {...control}
                            type="number"
                            step="1"
                            min="0"
                            className={controlClass}
                            value={data.low_stock_threshold}
                            onChange={(event) => setData('low_stock_threshold', event.target.value)}
                        />
                    )}
                </Field>
            </section>

            <label className="flex items-center gap-2 text-sm text-text">
                <input
                    type="checkbox"
                    checked={data.is_active}
                    onChange={(event) => setData('is_active', event.target.checked)}
                    className="h-4 w-4 accent-[var(--accent-fill)]"
                />
                Active
            </label>

            <div className="flex justify-end gap-2">
                <Button type="submit" variant="primary" disabled={processing}>
                    {mode === 'create' ? 'Create variant' : 'Save changes'}
                </Button>
            </div>
        </form>
    );
}
