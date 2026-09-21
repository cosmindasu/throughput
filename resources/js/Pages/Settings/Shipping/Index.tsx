import { Head, useForm, usePage } from '@inertiajs/react';
import { useRef, type FormEvent, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import Button from '@/Components/Button';
import Field, { controlClass } from '@/Components/Form/Field';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import AppLayout from '@/Layouts/AppLayout';
import type { CarrierSettingRow, SettingsShippingIndexPageProps } from '@/types/generated';

/**
 * Un card per furnizor CUNOSCUT (nu per rând existent în `tenant_carrier_settings`) —
 * un tenant care n-a configurat încă Shippo tot trebuie să-l poată activa de aici.
 * `canManage` vine din server (§7.3, FR-RBAC-01): niciun buton dezactivat, absent dacă
 * lipsește dreptul — practic mereu `true` aici, pagina însăși fiind Owner-only, dar
 * verificat oricum, ca restul aplicației.
 */
function ProviderCard({ provider, canManage, action }: { provider: CarrierSettingRow; canManage: boolean; action: string }) {
    const { t } = useTranslation('settings');
    const form = useForm({ provider: provider.provider, credentials: { api_key: '' } });
    const apiKeyRef = useRef<HTMLInputElement>(null);

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        // Audit de accesibilitate P1 — retur timpuriu, simetric cu `aria-disabled` de mai
        // jos: un al doilea submit (dublu-click, Enter repetat) nu trebuie să pornească o
        // a doua cerere doar pentru că butonul nu mai e `disabled` nativ.
        if (form.processing) {
            return;
        }

        // Capcană măsurată în acest proiect: `useForm().post()` IGNORĂ `options.data` —
        // `transform()` e singura formă corectă de a reshape payload-ul chiar înainte de
        // trimitere. Aici transformă starea locală (`credentials.api_key`, mereu un
        // string) în forma pe care o cere `UpdateCarrierSettingRequest`: `credentials`
        // complet ABSENT dacă utilizatorul n-a scris o cheie nouă, ca formularul mascat
        // să nu retrimită (și deci să nu poată șterge din greșeală) o cheie deja salvată.
        form.transform((data) => {
            const payload: Record<string, unknown> = { provider: data.provider };

            if (data.credentials.api_key.trim() !== '') {
                payload.credentials = { api_key: data.credentials.api_key.trim() };
            }

            return payload;
        });

        form.post(action, {
            preserveScroll: true,
            onSuccess: () => form.reset('credentials'),
            // Audit de accesibilitate P2 — `aria-describedby` se anunță la FOCUS, iar
            // fără asta focusul rămâne pe butonul de submit (sau, cu `disabled` nativ, ar
            // fi căzut pe `<body>` — P1 de mai sus). Fără mutare explicită, eroarea de
            // business („Add a Shippo API key…") e legată corect, dar nimeni n-o aude.
            onError: () => apiKeyRef.current?.focus(),
        });
    };

    const actionLabel = provider.isActive
        ? t('settings:shipping.save')
        : provider.requiresApiKey
          ? t('settings:shipping.saveAndActivate')
          : t('settings:shipping.activate');
    const fieldError = form.errors['credentials.api_key'] ?? provider.credentialError ?? undefined;

    // Audit de accesibilitate P2 — starea „cheie deja configurată"/„lipsă" trăia într-un
    // paragraf separat, doar POZIȚIONAT lângă câmp: cine navighează cu rotorul/Tab între
    // câmpuri nu-l aude niciodată. Mutat integral în `hint`-ul lui `Field`, deja legat
    // prin `aria-describedby`.
    const apiKeyHint = provider.configured
        ? t('settings:shipping.apiKeyConfiguredHint', { last: provider.credentialPreview?.replace('•••• ', '') })
        : t('settings:shipping.apiKeyMissingHint');

    return (
        <form onSubmit={submit} noValidate className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4">
            <div className="flex items-center justify-between gap-2">
                <h2 className="text-sm font-semibold text-text">{provider.label}</h2>
                <StatusBadge tone={provider.isActive ? 'success' : 'neutral'}>
                    {provider.isActive ? t('settings:shipping.active') : t('settings:shipping.notActive')}
                </StatusBadge>
            </div>

            <p className="text-sm text-text-2">{provider.description}</p>

            {provider.requiresApiKey && (
                <Field label={t('settings:shipping.apiKeyLabel')} hint={apiKeyHint} error={fieldError}>
                    {(control) => (
                        <input
                            {...control}
                            ref={apiKeyRef}
                            type="password"
                            autoComplete="off"
                            spellCheck={false}
                            className={controlClass}
                            value={form.data.credentials.api_key}
                            onChange={(event) => form.setData('credentials.api_key', event.target.value)}
                            placeholder={provider.configured ? '••••••••' : 'shippo_test_...'}
                        />
                    )}
                </Field>
            )}

            {canManage && (
                <Button
                    type="submit"
                    variant="primary"
                    className={`w-fit ${form.processing ? 'cursor-not-allowed opacity-60' : ''}`}
                    // Audit de accesibilitate P1 — `.ai/rules/frontend.md`: `disabled`
                    // nativ pe butonul care ARE focusul (exact acesta, tocmai apăsat) îl
                    // blurează — browserul mută focusul pe `<body>`, iar Tab-ul următor
                    // pornește de la „Skip to content". `aria-disabled` + returul timpuriu
                    // din `submit()` de mai sus dau același efect fără să rupă tab-order-ul.
                    aria-disabled={form.processing || undefined}
                    aria-label={t('settings:shipping.actionAriaLabel', { action: actionLabel, label: provider.label })}
                >
                    {form.processing ? t('settings:shipping.saving') : actionLabel}
                </Button>
            )}
        </form>
    );
}

/**
 * Settings → Shipping (FR-ORD-06, §7.4 „Setări curierat", ADR-010) — Owner-only.
 * Regula BR-ORD-03 (exact un furnizor `is_active` per tenant) e server-side
 * (`App\Actions\Shipping\ActivateCarrierAction`): apăsând „Activate"/„Save & activate" pe
 * un card, celălalt trece automat „Not active" la următoarea reîncărcare — niciun toggle
 * dublu de gestionat aici, în client.
 */
export default function Index() {
    const { providers, can, workspace } = usePage<SettingsShippingIndexPageProps>().props;
    const { t } = useTranslation('settings');
    const action = workspace ? `/${workspace.slug}/settings/shipping` : '/settings/shipping';

    return (
        <>
            <Head title={t('settings:shipping.title')} />

            <div className="flex flex-col gap-6">
                <PageHeader title={t('settings:shipping.title')} description={t('settings:shipping.description')} />

                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                    {providers.map((provider) => (
                        <ProviderCard key={provider.provider} provider={provider} canManage={can.manage} action={action} />
                    ))}
                </div>
            </div>
        </>
    );
}

Index.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
