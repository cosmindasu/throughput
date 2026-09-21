import { Link, usePage } from '@inertiajs/react';
import { Trans, useTranslation } from 'react-i18next';

interface DuplicateEmailNoticeProps {
    /** Cheia câmpului din formular, identică cu cea validată pe server (ex: `contact.email`). */
    field: string;
    confirmed: boolean;
    onConfirmedChange: (confirmed: boolean) => void;
}

/**
 * US-CRM-01 — avertismentul pentru un email deja legat de alt cont. Mesajul de eroare
 * vine pe câmp (validarea serverului); componenta adaugă ce mesajul nu poate purta: linkul
 * către contul existent și confirmarea explicită care deblochează salvarea.
 *
 * Datele vin din flash-ul Inertia (`App\Support\Contacts\DuplicateContactEmail`), deci
 * dispar la următoarea navigare — un avertisment vechi nu rămâne lipit de formular.
 */
export default function DuplicateEmailNotice({ field, confirmed, onConfirmedChange }: DuplicateEmailNoticeProps) {
    const { t } = useTranslation('common');
    const { flash, props } = usePage();
    const duplicate = flash.duplicateEmail;

    if (!duplicate || duplicate.field !== field || !props.workspace) {
        return null;
    }

    return (
        <div className="flex flex-col gap-2 rounded-md bg-warning-tint px-3 py-2 text-sm text-warning">
            <p>
                {/* `duplicate.accountName` e conținut scris de utilizator (FR-I18N-06) —
                    interpolat prin `values`, niciodată tradus. `<link>` e singurul element
                    JSX din mijlocul propoziției, deci `Trans`, nu `t()`. */}
                <Trans
                    i18nKey="common:duplicateEmail.notice"
                    values={{ accountName: duplicate.accountName }}
                    components={{
                        link: (
                            <Link
                                href={`/${props.workspace.slug}/accounts/${duplicate.accountId}`}
                                className="font-medium underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                            />
                        ),
                    }}
                />
            </p>
            <label className="flex items-center gap-2 text-text">
                <input
                    type="checkbox"
                    checked={confirmed}
                    onChange={(event) => onConfirmedChange(event.target.checked)}
                    className="h-4 w-4 accent-[var(--accent-fill)]"
                />
                {t('common:duplicateEmail.saveAnyway')}
            </label>
        </div>
    );
}
