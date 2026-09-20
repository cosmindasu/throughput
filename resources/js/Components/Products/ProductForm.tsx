import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import Button from '@/Components/Button';
import Field, { controlClass } from '@/Components/Form/Field';
import type { ProductDetail, UnitOfMeasure } from '@/types/generated';

interface ProductFormProps {
    mode: 'create' | 'edit';
    product?: ProductDetail;
    action: string;
}

interface ProductFormData {
    name: string;
    category: string;
    unit_of_measure: UnitOfMeasure;
    is_active: boolean;
}

/**
 * Formular comun `Products/Create` și `Products/Edit` (specs.md §10.2). Fără variante
 * aici — o variantă e o resursă separată, adăugată din `Products/Show` (`VariantForm`).
 */
export default function ProductForm({ mode, product, action }: ProductFormProps) {
    const { data, setData, post, put, processing, errors } = useForm<ProductFormData>({
        name: product?.name ?? '',
        category: product?.category ?? '',
        unit_of_measure: product?.unitOfMeasure ?? 'each',
        is_active: product?.isActive ?? true,
    });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        // Butonul de submit e `aria-disabled`, nu `disabled` nativ (`Button`, prop
        // `pending`) — al doilea submit se oprește AICI, nu de browser.
        if (processing) {
            return;
        }

        if (mode === 'create') {
            post(action);
        } else {
            put(action);
        }
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-6" noValidate>
            <section className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Field label="Product name" error={errors.name} required>
                    {(control) => (
                        <input
                            {...control}
                            className={controlClass}
                            value={data.name}
                            onChange={(event) => setData('name', event.target.value)}
                        />
                    )}
                </Field>

                <Field label="Category" error={errors.category}>
                    {(control) => (
                        <input
                            {...control}
                            className={controlClass}
                            value={data.category}
                            onChange={(event) => setData('category', event.target.value)}
                        />
                    )}
                </Field>

                <Field label="Unit of measure" error={errors.unit_of_measure} required>
                    {(control) => (
                        <select
                            {...control}
                            className={controlClass}
                            value={data.unit_of_measure}
                            onChange={(event) => setData('unit_of_measure', event.target.value as UnitOfMeasure)}
                        >
                            <option value="each">Each</option>
                            <option value="box">Box</option>
                            <option value="pallet">Pallet</option>
                        </select>
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
                <Button type="submit" variant="primary" pending={processing} pendingLabel="Saving…">
                    {mode === 'create' ? 'Create product' : 'Save changes'}
                </Button>
            </div>
        </form>
    );
}
