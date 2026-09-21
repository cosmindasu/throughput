import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
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
    const { t } = useTranslation('products');
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
                <Field label={t('products:form.name.label')} error={errors.name} required>
                    {(control) => (
                        <input
                            {...control}
                            className={controlClass}
                            value={data.name}
                            onChange={(event) => setData('name', event.target.value)}
                        />
                    )}
                </Field>

                <Field label={t('products:form.category.label')} error={errors.category}>
                    {(control) => (
                        <input
                            {...control}
                            className={controlClass}
                            value={data.category}
                            onChange={(event) => setData('category', event.target.value)}
                        />
                    )}
                </Field>

                <Field label={t('products:form.unitOfMeasure.label')} error={errors.unit_of_measure} required>
                    {(control) => (
                        <select
                            {...control}
                            className={controlClass}
                            value={data.unit_of_measure}
                            onChange={(event) => setData('unit_of_measure', event.target.value as UnitOfMeasure)}
                        >
                            <option value="each">{t('products:unitOfMeasure.each')}</option>
                            <option value="box">{t('products:unitOfMeasure.box')}</option>
                            <option value="pallet">{t('products:unitOfMeasure.pallet')}</option>
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
                {t('products:form.active')}
            </label>

            <div className="flex justify-end gap-2">
                <Button type="submit" variant="primary" pending={processing} pendingLabel={t('products:form.saving')}>
                    {mode === 'create' ? t('products:form.createProduct') : t('products:form.saveChanges')}
                </Button>
            </div>
        </form>
    );
}
