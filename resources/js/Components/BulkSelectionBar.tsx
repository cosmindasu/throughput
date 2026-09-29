import type { TFunction } from 'i18next';
import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import Button from '@/Components/Button';
import ConfirmDialog from '@/Components/ConfirmDialog';
import { controlClass } from '@/Components/Form/Field';
import { useBulkActionDispatch } from '@/hooks/useBulkActionDispatch';
import { useLocale } from '@/hooks/useLocale';
import type { DeferredProp } from '@/types/generated';
import { formatNumber } from '@/lib/format';
import type { AppLocale } from '@/lib/i18n';

interface BulkOwnerOption {
    id: string;
    name: string;
}

/**
 * Cheia din catalogul `bulk` (`resource.*`) pentru substantivul pluralizat CLDR al
 * resursei bifate — vezi `resourceLabel()`/`resourcePluralLabel()` mai jos și raportul
 * lotului A2 (Val 3, „Lot I18N") pentru DE CE componenta încă acceptă
 * `resourceNounSingular`/`resourceNounPlural` ca fallback, în loc să ceară DOAR
 * `resourceKey`.
 */
export type BulkResourceKey = 'accounts' | 'deals' | 'orders' | 'products';

/**
 * Deducerea lui `resourceKey` din `resourceNounPlural` când apelantul NU dă `resourceKey`
 * explicit — cazul tuturor celor patru apelanți EXISTENȚI azi (`Accounts/Deals/Orders/
 * Products/Index.tsx`, verificat cu `grep resourceNounSingular resources/js/Pages`),
 * fișiere ale altor agenți, needitate în acest lot (P2-003, brief-ul valului).
 *
 * Un apelant VIITOR cu un substantiv nou, absent din harta asta, cade pe fallback-ul
 * necontextualizat din `resourceLabel()` — corect în engleză, dar cu substantivul englez
 * NETRADUS în franceză. Vezi raportul lotului A2, pct. 3.
 */
const RESOURCE_KEY_BY_PLURAL: Partial<Record<string, BulkResourceKey>> = {
    accounts: 'accounts',
    deals: 'deals',
    orders: 'orders',
    products: 'products',
};

/**
 * Substantivul pluralizat CLDR pentru `count`, din `resource.<resourceKey>` — sau,
 * dacă `resourceKey` e `undefined` (fallback), vechea concatenare
 * `${formatted} ${singular|plural}`, corectă doar în engleză (substantivul rămâne cel
 * englez dat de apelant, indiferent de limba activă).
 */
function resourceLabel(
    t: TFunction,
    locale: AppLocale,
    count: number,
    resourceKey: BulkResourceKey | undefined,
    singular: string,
    plural: string,
): string {
    const formatted = formatNumber(count, locale);

    if (resourceKey) {
        return t(`bulk:resource.${resourceKey}`, { count, formatted });
    }

    return `${formatted} ${count === 1 ? singular : plural}`;
}

/**
 * Ca mai sus, dar FORȚEAZĂ categoria CLDR de plural (`_other`/`_many`), INDIFERENT de
 * `count` — reproduce exact comportamentul vechi al celor trei locuri care foloseau
 * `resourceNounPlural` direct, NICIODATĂ ternarul `noun` (linia ~137 „Select all N…
 * matching this filter", linia ~194 mesajul de plafon, linia ~287 corpul dialogului de
 * reasignare): înainte de acest lot, textul englez zicea „1 accounts” dacă
 * `effectiveCount` era 1 pe oricare din cele trei — comportament păstrat aici INTENȚIONAT
 * (textul englez rămâne identic, vezi raportul), nu „reparat” din mers spre o formă
 * singular-aware, ca să nu schimbe randajul curent al suitei E2E pe aceste trei locuri.
 * `count: 2` e arbitrar — orice valoare diferită de 1 și de un multiplu exact de milion
 * selectează garantat categoria „other” în ambele limbi (`Intl.PluralRules`).
 */
function resourcePluralLabel(t: TFunction, locale: AppLocale, count: number, resourceKey: BulkResourceKey | undefined, plural: string): string {
    const formatted = formatNumber(count, locale);

    if (resourceKey) {
        return t(`bulk:resource.${resourceKey}`, { count: 2, formatted });
    }

    return `${formatted} ${plural}`;
}

interface BulkSelectionBarProps {
    /** Singular, ex. „account" — pentru „1 account selected". */
    resourceNounSingular: string;
    /** Plural, ex. „accounts". */
    resourceNounPlural: string;
    /**
     * Cheia CLDR a resursei (`resource.<cheie>` din catalogul `bulk`), preferată în locul
     * lui `resourceNounSingular`/`resourceNounPlural` pentru pluralizarea francă — vezi
     * docblock-ul `RESOURCE_KEY_BY_PLURAL` de mai sus. NICIUNUL dintre cei patru apelanți
     * actuali n-o dă încă (needitați în acest lot); dedusă automat din `resourceNounPlural`
     * pentru ei. Un apelant NOU ar trebui s-o dea explicit.
     */
    resourceKey?: BulkResourceKey;
    /**
     * N-ul EXACT pe care operația l-ar atinge pe modul „select all matching filter"
     * (`App\Support\Bulk\BulkMatchingRowCount`, P2-003 — restricția Agentului aplicată).
     * Prop DEFERRED (Inertia 3): poate să nu fi sosit încă la primul randaj — bara se
     * randează ÎNAINTE de `<Deferred>` pe Accounts/Orders/Products, deci chiar lipsește
     * la prima trecere. Verificat mai jos cu `typeof`, nu presupus (P1-002, code review).
     *
     * Tipul spunea `number` și mințea; nota asta o semnala din P1-002, dar declarația a
     * rămas. De la auditul din 2026-09-29 e `DeferredProp<number>` — aceeași informație,
     * mutată acolo unde `tsc` o poate folosi. Garda cu `typeof` de mai jos NU e redundantă
     * acum: ea e motivul pentru care tipul are voie să admită `undefined`.
     */
    total: DeferredProp<number>;
    /** Rândurile bifate explicit pe pagina curentă (`useBulkSelection`), NICIODATĂ tot filtrul. */
    selectedCount: number;
    allOnPageSelected: boolean;
    matchingFilter: boolean;
    selectedIds: string[];
    confirmationThreshold: number;
    /** `App\Support\Bulk\BulkConfirmationThreshold::rowCapForRole()` — `null` = fără plafon (Owner/Manager). */
    rowCap: number | null;
    onSelectAllMatching: () => void;
    onClearSelection: () => void;

    /**
     * Reasignare owner (Accounts, Deals, Orders) — blocul apare doar când `dispatchUrl` e
     * dat. Aditiv față de forma originală a componentei: Accounts/Deals continuă s-o
     * folosească cu exact aceste două props, neschimbate.
     */
    dispatchUrl?: string;
    owners?: BulkOwnerOption[];

    /**
     * Anulare în masă a comenzilor `draft` (Orders, §13.5) — blocul apare doar când
     * `cancelDraftsUrl` e dat. `draftTotal`/`draftSelectedCount` sunt N-ul EXACT pe care
     * ANULAREA l-ar atinge (doar draft-urile din filtru/selecție) — distinct de `total` de
     * mai sus, care e N-ul brut al filtrului/selecției. Vezi
     * `App\Support\Bulk\BulkChunkActions::narrowQuery()`.
     */
    cancelDraftsUrl?: string;
    draftTotal?: number;
    draftSelectedCount?: number;

    /** Preț în masă pe variantele produselor selectate (Products, §13.5). */
    priceUpdateUrl?: string;

    /** Activare/dezactivare în masă (Products, §13.5). */
    toggleActiveUrl?: string;
}

/**
 * Bara de acțiuni în masă (§13.1/§13.2) — apare sub tabel doar cât timp există o selecție.
 * Generalizată (lotul E, valul „bulk" pe Orders/Products) peste patru acțiuni posibile —
 * fiecare bloc e opțional și apare doar când URL-ul lui e dat, ca fiecare pagină să
 * activeze doar ce are dreptul (Accounts/Deals: doar reasignare; Orders: reasignare +
 * anulare de draft-uri; Products: preț + activare/dezactivare).
 *
 * `aria-live="polite"` rămâne montat indiferent de `selectedCount` (nu doar cât bara e
 * vizibilă), ca trecerea la 0 selectate — bara dispărând — să fie ANUNȚATĂ, nu doar tăcută.
 *
 * Capcană de accesibilitate (găsită de mai multe ori în acest val, pe ecrane diferite):
 * „Clear selection" e chiar butonul care ARE focusul când e apăsat, iar apăsarea lui
 * scoate din DOM toată bara care-l conține (`selectedCount` ajunge la 0) — fără nimic
 * care să mute focusul explicit, el ar cădea pe `<body>`. Wrapper-ul exterior (montat
 * mereu, cu `aria-live`) primește focusul programatic după golire — rămâne stabil în DOM
 * indiferent de starea selecției, deci e o țintă sigură.
 *
 * Val 3 („Lot I18N", ADR-022, lotul A2) — namespace `bulk`. Unsprezece formatări
 * `toLocaleString('en-US')` (măsurat pe disc, nu cele „10" anticipate în brief) și DOUĂ
 * tipare de pluralizare independente (linia ~107 istorică, ternarul `noun` de mai jos, și
 * linia ~321 istorică, `CancelDraftsControl`) au trecut pe motorul CLDR — vezi raportul
 * lotului pentru lista completă, fișier + linie.
 */
export default function BulkSelectionBar({
    resourceNounSingular,
    resourceNounPlural,
    resourceKey,
    total,
    selectedCount,
    allOnPageSelected,
    matchingFilter,
    selectedIds,
    confirmationThreshold,
    rowCap,
    onSelectAllMatching,
    onClearSelection,
    dispatchUrl,
    owners,
    cancelDraftsUrl,
    draftTotal,
    draftSelectedCount,
    priceUpdateUrl,
    toggleActiveUrl,
}: BulkSelectionBarProps) {
    const { t } = useTranslation('bulk');
    const locale = useLocale();
    const wrapperRef = useRef<HTMLDivElement>(null);
    const totalKnown = typeof total === 'number';
    const resolvedResourceKey = resourceKey ?? RESOURCE_KEY_BY_PLURAL[resourceNounPlural];

    // P1-002 (code review) — pe modul „select all matching filter", numărul care contează
    // e N-ul EXACT al operației (P2-003), NICIODATĂ `selected.size` (plafonat la o pagină,
    // `useBulkSelection`): altfel dialogul arăta „50 accounts" pentru o operație pe mii de
    // rânduri, iar comparația cu pragul de confirmare pornea de la numărul greșit.
    const effectiveCount = matchingFilter && totalKnown ? total : selectedCount;
    // P2-001 (code review) — plafonul Agentului verificat ȘI client-side, înainte de
    // submit: serverul (`DispatchBulkOperationAction`) rămâne sursa de adevăr, dar
    // dezactivarea aici scutește un drum dus-întors doar ca să afli refuzul.
    const overRowCap = rowCap !== null && effectiveCount > rowCap;

    const draftEffectiveCount = matchingFilter ? (draftTotal ?? 0) : (draftSelectedCount ?? 0);
    const draftOverRowCap = rowCap !== null && draftEffectiveCount > rowCap;

    const handleClear = () => {
        onClearSelection();
        // Vezi docblock-ul componentei — focus explicit pe wrapper-ul stabil, nu lăsat
        // pe `<body>` odată ce bara (și butonul apăsat) dispare din DOM.
        wrapperRef.current?.focus();
    };

    return (
        <div ref={wrapperRef} tabIndex={-1} aria-live="polite" aria-atomic="true" className="focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">
            {selectedCount > 0 && (
                <div className="flex flex-wrap items-center gap-3 rounded-lg border border-border bg-raised px-4 py-2.5 text-sm">
                    <span className="font-medium text-text">
                        {t('bulk:selectionBar.selected', {
                            resource: resourceLabel(t, locale, effectiveCount, resolvedResourceKey, resourceNounSingular, resourceNounPlural),
                        })}
                    </span>

                    {allOnPageSelected && !matchingFilter && totalKnown && total > selectedCount && (
                        <button
                            type="button"
                            onClick={onSelectAllMatching}
                            className="text-accent-text underline decoration-dotted underline-offset-2 hover:no-underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                        >
                            {t('bulk:selectionBar.selectAllMatching', {
                                resource: resourcePluralLabel(t, locale, total, resolvedResourceKey, resourceNounPlural),
                            })}
                        </button>
                    )}

                    {dispatchUrl && owners && (
                        <ReassignOwnerControl
                            dispatchUrl={dispatchUrl}
                            owners={owners}
                            matchingFilter={matchingFilter}
                            selectedIds={selectedIds}
                            effectiveCount={effectiveCount}
                            resourceKey={resolvedResourceKey}
                            resourceNounSingular={resourceNounSingular}
                            resourceNounPlural={resourceNounPlural}
                            confirmationThreshold={confirmationThreshold}
                            overRowCap={overRowCap}
                        />
                    )}

                    {cancelDraftsUrl && (
                        <CancelDraftsControl
                            dispatchUrl={cancelDraftsUrl}
                            matchingFilter={matchingFilter}
                            selectedIds={selectedIds}
                            effectiveCount={draftEffectiveCount}
                            confirmationThreshold={confirmationThreshold}
                            overRowCap={draftOverRowCap}
                        />
                    )}

                    {priceUpdateUrl && (
                        <PriceUpdateControl
                            dispatchUrl={priceUpdateUrl}
                            matchingFilter={matchingFilter}
                            selectedIds={selectedIds}
                            effectiveCount={effectiveCount}
                            resourceKey={resolvedResourceKey}
                            resourceNounSingular={resourceNounSingular}
                            resourceNounPlural={resourceNounPlural}
                            confirmationThreshold={confirmationThreshold}
                            overRowCap={overRowCap}
                        />
                    )}

                    {toggleActiveUrl && (
                        <ToggleActiveControl
                            dispatchUrl={toggleActiveUrl}
                            matchingFilter={matchingFilter}
                            selectedIds={selectedIds}
                            effectiveCount={effectiveCount}
                            resourceKey={resolvedResourceKey}
                            resourceNounSingular={resourceNounSingular}
                            resourceNounPlural={resourceNounPlural}
                            confirmationThreshold={confirmationThreshold}
                            overRowCap={overRowCap}
                        />
                    )}

                    <Button onClick={handleClear}>{t('bulk:selectionBar.clearSelection')}</Button>

                    {overRowCap && (
                        <p role="alert" className="w-full rounded-md bg-danger-tint px-3 py-2 text-sm text-danger">
                            {t('bulk:selectionBar.overRowCap', {
                                resource: resourcePluralLabel(t, locale, effectiveCount, resolvedResourceKey, resourceNounPlural),
                                limit: formatNumber(rowCap ?? 0, locale),
                            })}
                        </p>
                    )}
                </div>
            )}
        </div>
    );
}

/**
 * O eroare a serverului (plafon, prag fără `confirmed`, câmp invalid) — `role="alert"`
 * indiferent de unde e randată (bara sau, cât dialogul de confirmare e deschis, ÎN
 * dialog, ca să rămână vizibilă și legată de context, nu ascunsă sub `backdrop`-ul unui
 * `<dialog>` modal).
 *
 * `message` vine din `useBulkActionDispatch` (`errors.selection` server-side, sau
 * fallback-ul lui hardcodat `'This operation could not be started.'`) — fișier al altui
 * lot, needitat aici; fallback-ul lui rămâne netradus. Vezi raportul lotului A2, pct. 6.
 */
function ErrorAlert({ message }: { message: string }) {
    return (
        <p role="alert" className="w-full rounded-md bg-danger-tint px-3 py-2 text-sm text-danger">
            {message}
        </p>
    );
}

interface ActionControlBaseProps {
    dispatchUrl: string;
    matchingFilter: boolean;
    selectedIds: string[];
    effectiveCount: number;
    confirmationThreshold: number;
    overRowCap: boolean;
}

interface ResourceNounProps {
    resourceKey: BulkResourceKey | undefined;
    resourceNounSingular: string;
    resourceNounPlural: string;
}

function ReassignOwnerControl({
    dispatchUrl,
    owners,
    matchingFilter,
    selectedIds,
    effectiveCount,
    resourceKey,
    resourceNounSingular,
    resourceNounPlural,
    confirmationThreshold,
    overRowCap,
}: ActionControlBaseProps & ResourceNounProps & { owners: BulkOwnerOption[] }) {
    const { t } = useTranslation('bulk');
    const locale = useLocale();
    const [ownerId, setOwnerId] = useState('');
    const ownerName = owners.find((owner) => owner.id === ownerId)?.name ?? '';
    const { processing, error, confirmOpen, closeConfirm, run, confirmAndDispatch } = useBulkActionDispatch(
        dispatchUrl,
        matchingFilter,
        selectedIds,
        effectiveCount,
        confirmationThreshold,
    );
    // Titlul/butonul folosesc pluralizarea REALĂ (count-aware, `noun` istoric); corpul
    // dialogului folosea `resourceNounPlural` NECONDIȚIONAT (niciodată ternarul) —
    // comportament păstrat identic, vezi `resourcePluralLabel`.
    const resourceCountAware = resourceLabel(t, locale, effectiveCount, resourceKey, resourceNounSingular, resourceNounPlural);
    const resourceAlwaysPlural = resourcePluralLabel(t, locale, effectiveCount, resourceKey, resourceNounPlural);

    return (
        <>
            <label className="flex items-center gap-1.5 text-text-2">
                {t('bulk:selectionBar.reassignToLabel')}
                <select value={ownerId} onChange={(event) => setOwnerId(event.target.value)} className={controlClass}>
                    <option value="">{t('bulk:selectionBar.chooseOwnerOption')}</option>
                    {owners.map((owner) => (
                        <option key={owner.id} value={owner.id}>
                            {owner.name}
                        </option>
                    ))}
                </select>
            </label>

            {/*
                `disabled` nativ rămâne pentru condiții STATICE (fără owner ales, peste
                plafon) — un buton indisponibil dinainte, niciodată blurat de propria
                apăsare. `aria-disabled` + `onClick` no-op acoperă DOAR `processing`
                (capcana de accesibilitate: nu bloca nativ butonul care tocmai a primit
                focusul din propria apăsare).
            */}
            <Button
                variant="primary"
                disabled={!ownerId || overRowCap}
                aria-disabled={processing || undefined}
                className={processing ? 'cursor-not-allowed opacity-60' : ''}
                onClick={processing ? undefined : () => run({ owner_user_id: ownerId })}
            >
                {processing ? t('bulk:selectionBar.starting') : t('bulk:selectionBar.reassignOwnerButton')}
            </Button>

            <ConfirmDialog
                open={confirmOpen}
                title={t('bulk:selectionBar.reassignConfirmTitle', { resource: resourceCountAware })}
                onConfirm={confirmAndDispatch}
                onClose={closeConfirm}
                processing={processing}
                confirmLabel={t('bulk:selectionBar.reassignConfirmLabel')}
            >
                <>
                    {t('bulk:selectionBar.reassignConfirmBody', { resource: resourceAlwaysPlural, owner: ownerName })}
                    {error && (
                        <div className="mt-3">
                            <ErrorAlert message={error} />
                        </div>
                    )}
                </>
            </ConfirmDialog>

            {!confirmOpen && error && <ErrorAlert message={error} />}
        </>
    );
}

/**
 * §13.5, §11.3 — anulare în masă a comenzilor `draft`. `effectiveCount` (prop, calculat de
 * apelant din `draftTotal`/`draftSelectedCount`) e DELIBERAT diferit de `total`-ul
 * resursei: anularea atinge doar subsetul `draft` al selecției curente, iar „Select all N"
 * și pragul de confirmare trebuie să reflecte exact acel subset, nu filtrul brut (defectul
 * (g) din v1.24, reprodus altfel pe un tip nou de acțiune dacă am fi refolosit `total`).
 *
 * Substantivul (fost `effectiveCount === 1 ? 'draft order' : 'draft orders'`, ternar
 * hardcodat, independent de `resourceNounSingular`/`resourceNounPlural` ale bării) trece
 * acum pe `bulk:resource.draftOrders` — CLDR real, singurul din acest fișier care nu
 * depinde deloc de props-ul de resursă al apelantului (repară complet tiparul, nu doar
 * parțial, spre deosebire de resursele generice de mai sus).
 */
function CancelDraftsControl({ dispatchUrl, matchingFilter, selectedIds, effectiveCount, confirmationThreshold, overRowCap }: ActionControlBaseProps) {
    const { t } = useTranslation('bulk');
    const locale = useLocale();
    const { processing, error, confirmOpen, closeConfirm, run, confirmAndDispatch } = useBulkActionDispatch(
        dispatchUrl,
        matchingFilter,
        selectedIds,
        effectiveCount,
        confirmationThreshold,
    );

    if (effectiveCount === 0) {
        return null;
    }

    const draftOrders = t('bulk:resource.draftOrders', { count: effectiveCount, formatted: formatNumber(effectiveCount, locale) });

    return (
        <>
            <Button
                variant="danger"
                disabled={overRowCap}
                aria-disabled={processing || undefined}
                className={processing ? 'cursor-not-allowed opacity-60' : ''}
                onClick={processing ? undefined : () => run()}
            >
                {processing ? t('bulk:selectionBar.starting') : t('bulk:selectionBar.cancelDraftsButton', { resource: draftOrders })}
            </Button>

            <ConfirmDialog
                open={confirmOpen}
                title={t('bulk:selectionBar.cancelDraftsConfirmTitle', { resource: draftOrders })}
                onConfirm={confirmAndDispatch}
                onClose={closeConfirm}
                processing={processing}
                confirmLabel={t('bulk:selectionBar.cancelDraftsConfirmLabel')}
                confirmVariant="danger"
            >
                <>
                    {t('bulk:selectionBar.cancelDraftsConfirmBody')}
                    {error && (
                        <div className="mt-3">
                            <ErrorAlert message={error} />
                        </div>
                    )}
                </>
            </ConfirmDialog>

            {!confirmOpen && error && <ErrorAlert message={error} />}
        </>
    );
}

/** §13.5 — preț în masă (procent sau sumă fixă, +/-) pe variantele produselor selectate. */
function PriceUpdateControl({
    dispatchUrl,
    matchingFilter,
    selectedIds,
    effectiveCount,
    resourceKey,
    resourceNounSingular,
    resourceNounPlural,
    confirmationThreshold,
    overRowCap,
}: ActionControlBaseProps & ResourceNounProps) {
    const { t } = useTranslation('bulk');
    const locale = useLocale();
    const [mode, setMode] = useState<'percent' | 'fixed'>('percent');
    const [direction, setDirection] = useState<'increase' | 'decrease'>('increase');
    const [amount, setAmount] = useState('');
    const { processing, error, confirmOpen, closeConfirm, run, confirmAndDispatch } = useBulkActionDispatch(
        dispatchUrl,
        matchingFilter,
        selectedIds,
        effectiveCount,
        confirmationThreshold,
    );

    const parsedAmount = Number(amount);
    const validAmount = amount !== '' && Number.isFinite(parsedAmount) && parsedAmount > 0 && (mode === 'fixed' || parsedAmount <= 100);
    const amountDisplay = amount || '0';
    const suffix = mode === 'percent' ? t('bulk:selectionBar.percentOption') : '';
    const summary = t(direction === 'increase' ? 'bulk:selectionBar.priceSummaryIncrease' : 'bulk:selectionBar.priceSummaryDecrease', {
        amount: amountDisplay,
        suffix,
    });
    const resource = resourceLabel(t, locale, effectiveCount, resourceKey, resourceNounSingular, resourceNounPlural);

    return (
        <>
            <label className="flex items-center gap-1.5 text-text-2">
                <select value={direction} onChange={(event) => setDirection(event.target.value as 'increase' | 'decrease')} className={controlClass}>
                    <option value="increase">{t('bulk:selectionBar.increaseOption')}</option>
                    <option value="decrease">{t('bulk:selectionBar.decreaseOption')}</option>
                </select>
                {t('bulk:selectionBar.priceByLabel')}
                <input
                    type="number"
                    min="0.01"
                    step="0.01"
                    value={amount}
                    onChange={(event) => setAmount(event.target.value)}
                    aria-label={t('bulk:selectionBar.amountAriaLabel')}
                    className={`${controlClass} w-24`}
                />
                <select value={mode} onChange={(event) => setMode(event.target.value as 'percent' | 'fixed')} className={controlClass}>
                    <option value="percent">{t('bulk:selectionBar.percentOption')}</option>
                    <option value="fixed">{t('bulk:selectionBar.currencyOption')}</option>
                </select>
            </label>

            <Button
                variant="primary"
                disabled={!validAmount || overRowCap}
                aria-disabled={processing || undefined}
                className={processing ? 'cursor-not-allowed opacity-60' : ''}
                onClick={processing ? undefined : () => run({ mode, direction, amount: parsedAmount })}
            >
                {processing ? t('bulk:selectionBar.starting') : t('bulk:selectionBar.updatePriceButton')}
            </Button>

            <ConfirmDialog
                open={confirmOpen}
                title={t('bulk:selectionBar.priceConfirmTitle', { resource })}
                onConfirm={confirmAndDispatch}
                onClose={closeConfirm}
                processing={processing}
                confirmLabel={t('bulk:selectionBar.updatePriceButton')}
            >
                <>
                    {t('bulk:selectionBar.priceConfirmBody', { summary, resource })}
                    {error && (
                        <div className="mt-3">
                            <ErrorAlert message={error} />
                        </div>
                    )}
                </>
            </ConfirmDialog>

            {!confirmOpen && error && <ErrorAlert message={error} />}
        </>
    );
}

/** §13.5 — activare/dezactivare în masă a produselor selectate. */
function ToggleActiveControl({
    dispatchUrl,
    matchingFilter,
    selectedIds,
    effectiveCount,
    resourceKey,
    resourceNounSingular,
    resourceNounPlural,
    confirmationThreshold,
    overRowCap,
}: ActionControlBaseProps & ResourceNounProps) {
    const { t } = useTranslation('bulk');
    const locale = useLocale();
    const { processing, error, confirmOpen, closeConfirm, run, confirmAndDispatch } = useBulkActionDispatch(
        dispatchUrl,
        matchingFilter,
        selectedIds,
        effectiveCount,
        confirmationThreshold,
    );
    const [pendingActive, setPendingActive] = useState(true);
    const resource = resourceLabel(t, locale, effectiveCount, resourceKey, resourceNounSingular, resourceNounPlural);

    const trigger = (active: boolean) => {
        setPendingActive(active);
        run({ active });
    };

    return (
        <>
            <Button
                disabled={overRowCap}
                aria-disabled={processing || undefined}
                className={processing ? 'cursor-not-allowed opacity-60' : ''}
                onClick={processing ? undefined : () => trigger(true)}
            >
                {processing && pendingActive ? t('bulk:selectionBar.starting') : t('bulk:selectionBar.activateButton')}
            </Button>
            <Button
                disabled={overRowCap}
                aria-disabled={processing || undefined}
                className={processing ? 'cursor-not-allowed opacity-60' : ''}
                onClick={processing ? undefined : () => trigger(false)}
            >
                {processing && !pendingActive ? t('bulk:selectionBar.starting') : t('bulk:selectionBar.deactivateButton')}
            </Button>

            <ConfirmDialog
                open={confirmOpen}
                title={t(pendingActive ? 'bulk:selectionBar.activateConfirmTitle' : 'bulk:selectionBar.deactivateConfirmTitle', { resource })}
                onConfirm={confirmAndDispatch}
                onClose={closeConfirm}
                processing={processing}
                confirmLabel={pendingActive ? t('bulk:selectionBar.activateButton') : t('bulk:selectionBar.deactivateButton')}
            >
                <>
                    {t('bulk:selectionBar.toggleConfirmBody')}
                    {error && (
                        <div className="mt-3">
                            <ErrorAlert message={error} />
                        </div>
                    )}
                </>
            </ConfirmDialog>

            {!confirmOpen && error && <ErrorAlert message={error} />}
        </>
    );
}
