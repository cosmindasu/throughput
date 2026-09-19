import { useRef, useState } from 'react';
import Button from '@/Components/Button';
import ConfirmDialog from '@/Components/ConfirmDialog';
import { controlClass } from '@/Components/Form/Field';
import { useBulkActionDispatch } from '@/hooks/useBulkActionDispatch';

interface BulkOwnerOption {
    id: string;
    name: string;
}

interface BulkSelectionBarProps {
    /** Singular, ex. „account" — pentru „1 account selected". */
    resourceNounSingular: string;
    /** Plural, ex. „accounts". */
    resourceNounPlural: string;
    /**
     * N-ul EXACT pe care operația l-ar atinge pe modul „select all matching filter"
     * (`App\Support\Bulk\BulkMatchingRowCount`, P2-003 — restricția Agentului aplicată).
     * Prop DEFERRED (Inertia 3): poate să nu fi sosit încă la primul randaj, chiar dacă
     * tipul din `generated.d.ts` îl declară mereu `number` — verificat aici cu
     * `typeof`, nu presupus (P1-002, code review).
     */
    total: number;
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
 */
export default function BulkSelectionBar({
    resourceNounSingular,
    resourceNounPlural,
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
    const wrapperRef = useRef<HTMLDivElement>(null);
    const totalKnown = typeof total === 'number';

    // P1-002 (code review) — pe modul „select all matching filter", numărul care contează
    // e N-ul EXACT al operației (P2-003), NICIODATĂ `selected.size` (plafonat la o pagină,
    // `useBulkSelection`): altfel dialogul arăta „50 accounts" pentru o operație pe mii de
    // rânduri, iar comparația cu pragul de confirmare pornea de la numărul greșit.
    const effectiveCount = matchingFilter && totalKnown ? total : selectedCount;
    const noun = effectiveCount === 1 ? resourceNounSingular : resourceNounPlural;
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
                        {effectiveCount.toLocaleString('en-US')} {noun} selected
                    </span>

                    {allOnPageSelected && !matchingFilter && totalKnown && total > selectedCount && (
                        <button
                            type="button"
                            onClick={onSelectAllMatching}
                            className="text-accent-text underline decoration-dotted underline-offset-2 hover:no-underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                        >
                            Select all {total.toLocaleString('en-US')} {resourceNounPlural} matching this filter
                        </button>
                    )}

                    {dispatchUrl && owners && (
                        <ReassignOwnerControl
                            dispatchUrl={dispatchUrl}
                            owners={owners}
                            matchingFilter={matchingFilter}
                            selectedIds={selectedIds}
                            effectiveCount={effectiveCount}
                            noun={noun}
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
                            noun={noun}
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
                            noun={noun}
                            confirmationThreshold={confirmationThreshold}
                            overRowCap={overRowCap}
                        />
                    )}

                    <Button onClick={handleClear}>Clear selection</Button>

                    {overRowCap && (
                        <p role="alert" className="w-full rounded-md bg-danger-tint px-3 py-2 text-sm text-danger">
                            {`This would affect ${effectiveCount.toLocaleString('en-US')} ${resourceNounPlural}, above your role's limit of ${rowCap?.toLocaleString('en-US')} rows per operation.`}
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

function ReassignOwnerControl({
    dispatchUrl,
    owners,
    matchingFilter,
    selectedIds,
    effectiveCount,
    noun,
    resourceNounPlural,
    confirmationThreshold,
    overRowCap,
}: ActionControlBaseProps & { owners: BulkOwnerOption[]; noun: string; resourceNounPlural: string }) {
    const [ownerId, setOwnerId] = useState('');
    const ownerName = owners.find((owner) => owner.id === ownerId)?.name ?? '';
    const { processing, error, confirmOpen, closeConfirm, run, confirmAndDispatch } = useBulkActionDispatch(
        dispatchUrl,
        matchingFilter,
        selectedIds,
        effectiveCount,
        confirmationThreshold,
    );

    return (
        <>
            <label className="flex items-center gap-1.5 text-text-2">
                Reassign to
                <select value={ownerId} onChange={(event) => setOwnerId(event.target.value)} className={controlClass}>
                    <option value="">Choose owner…</option>
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
                {processing ? 'Starting…' : 'Reassign owner'}
            </Button>

            <ConfirmDialog
                open={confirmOpen}
                title={`Reassign ${effectiveCount.toLocaleString('en-US')} ${noun}?`}
                onConfirm={confirmAndDispatch}
                onClose={closeConfirm}
                processing={processing}
                confirmLabel="Reassign"
            >
                <>
                    {`This changes the owner of ${effectiveCount.toLocaleString('en-US')} ${resourceNounPlural} to ${ownerName}. It runs in the background — you'll land on a status page and can cancel it while it's running.`}
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
 */
function CancelDraftsControl({ dispatchUrl, matchingFilter, selectedIds, effectiveCount, confirmationThreshold, overRowCap }: ActionControlBaseProps) {
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

    const noun = effectiveCount === 1 ? 'draft order' : 'draft orders';

    return (
        <>
            <Button
                variant="danger"
                disabled={overRowCap}
                aria-disabled={processing || undefined}
                className={processing ? 'cursor-not-allowed opacity-60' : ''}
                onClick={processing ? undefined : () => run()}
            >
                {processing ? 'Starting…' : `Cancel ${effectiveCount.toLocaleString('en-US')} ${noun}`}
            </Button>

            <ConfirmDialog
                open={confirmOpen}
                title={`Cancel ${effectiveCount.toLocaleString('en-US')} ${noun}?`}
                onConfirm={confirmAndDispatch}
                onClose={closeConfirm}
                processing={processing}
                confirmLabel="Cancel orders"
                confirmVariant="danger"
            >
                <>
                    {`Only draft orders in your selection are affected — confirmed orders and anything already shipped are left exactly as they are. It runs in the background — you'll land on a status page and can cancel it while it's running.`}
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
    noun,
    confirmationThreshold,
    overRowCap,
}: ActionControlBaseProps & { noun: string }) {
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
    const summary = `${direction === 'increase' ? 'Increase' : 'Decrease'} price by ${amount || '0'}${mode === 'percent' ? '%' : ''}`;

    return (
        <>
            <label className="flex items-center gap-1.5 text-text-2">
                <select value={direction} onChange={(event) => setDirection(event.target.value as 'increase' | 'decrease')} className={controlClass}>
                    <option value="increase">Increase</option>
                    <option value="decrease">Decrease</option>
                </select>
                price by
                <input
                    type="number"
                    min="0.01"
                    step="0.01"
                    value={amount}
                    onChange={(event) => setAmount(event.target.value)}
                    aria-label="Amount"
                    className={`${controlClass} w-24`}
                />
                <select value={mode} onChange={(event) => setMode(event.target.value as 'percent' | 'fixed')} className={controlClass}>
                    <option value="percent">%</option>
                    <option value="fixed">currency</option>
                </select>
            </label>

            <Button
                variant="primary"
                disabled={!validAmount || overRowCap}
                aria-disabled={processing || undefined}
                className={processing ? 'cursor-not-allowed opacity-60' : ''}
                onClick={processing ? undefined : () => run({ mode, direction, amount: parsedAmount })}
            >
                {processing ? 'Starting…' : 'Update price'}
            </Button>

            <ConfirmDialog
                open={confirmOpen}
                title={`Update the price of ${effectiveCount.toLocaleString('en-US')} ${noun}?`}
                onConfirm={confirmAndDispatch}
                onClose={closeConfirm}
                processing={processing}
                confirmLabel="Update price"
            >
                <>
                    {`${summary} on every variant of ${effectiveCount.toLocaleString('en-US')} ${noun}. Prices never go below zero. It runs in the background — you'll land on a status page and can cancel it while it's running.`}
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
    noun,
    confirmationThreshold,
    overRowCap,
}: ActionControlBaseProps & { noun: string }) {
    const { processing, error, confirmOpen, closeConfirm, run, confirmAndDispatch } = useBulkActionDispatch(
        dispatchUrl,
        matchingFilter,
        selectedIds,
        effectiveCount,
        confirmationThreshold,
    );
    const [pendingActive, setPendingActive] = useState(true);

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
                {processing && pendingActive ? 'Starting…' : 'Activate'}
            </Button>
            <Button
                disabled={overRowCap}
                aria-disabled={processing || undefined}
                className={processing ? 'cursor-not-allowed opacity-60' : ''}
                onClick={processing ? undefined : () => trigger(false)}
            >
                {processing && !pendingActive ? 'Starting…' : 'Deactivate'}
            </Button>

            <ConfirmDialog
                open={confirmOpen}
                title={`${pendingActive ? 'Activate' : 'Deactivate'} ${effectiveCount.toLocaleString('en-US')} ${noun}?`}
                onConfirm={confirmAndDispatch}
                onClose={closeConfirm}
                processing={processing}
                confirmLabel={pendingActive ? 'Activate' : 'Deactivate'}
            >
                <>
                    {`This runs in the background — you'll land on a status page and can cancel it while it's running.`}
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
