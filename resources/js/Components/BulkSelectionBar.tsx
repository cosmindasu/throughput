import { router } from '@inertiajs/react';
import { useState } from 'react';
import Button from '@/Components/Button';
import ConfirmDialog from '@/Components/ConfirmDialog';
import { controlClass } from '@/Components/Form/Field';

interface BulkOwnerOption {
    id: string;
    name: string;
}

interface BulkSelectionBarProps {
    /** Singular, ex. „account" — pentru „1 account selected". */
    resourceNounSingular: string;
    /** Plural, ex. „accounts". */
    resourceNounPlural: string;
    /** URL complet (cu prefixul de workspace și querystring-ul filtrului curent) al rutei `bulk.reassign-owner`. */
    dispatchUrl: string;
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
    owners: BulkOwnerOption[];
    confirmationThreshold: number;
    /** `App\Support\Bulk\BulkConfirmationThreshold::rowCapForRole()` — `null` = fără plafon (Owner/Manager). */
    rowCap: number | null;
    onSelectAllMatching: () => void;
    onClearSelection: () => void;
}

/**
 * Bara de acțiuni în masă (§13.1/§13.2) — apare sub tabel doar cât timp există o selecție.
 * Randată de `Accounts/Index` și `Deals/Index` DOAR când `can.bulkWrite` e adevărat: Viewer
 * nu vede nici checkbox-urile, nici bara asta (BR-BULK-03) — butonul de scriere LIPSEȘTE,
 * nu apare dezactivat (§7.3).
 *
 * `aria-live="polite"` rămâne montat indiferent de `selectedCount` (nu doar cât bara e
 * vizibilă), ca trecerea la 0 selectate — bara dispărând — să fie ANUNȚATĂ, nu doar tăcută.
 */
export default function BulkSelectionBar({
    resourceNounSingular,
    resourceNounPlural,
    dispatchUrl,
    total,
    selectedCount,
    allOnPageSelected,
    matchingFilter,
    selectedIds,
    owners,
    confirmationThreshold,
    rowCap,
    onSelectAllMatching,
    onClearSelection,
}: BulkSelectionBarProps) {
    const [ownerId, setOwnerId] = useState('');
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const totalKnown = typeof total === 'number';

    // P1-002 (code review) — pe modul „select all matching filter", numărul care contează
    // e N-ul EXACT al operației (P2-003), NICIODATĂ `selected.size` (plafonat la o pagină,
    // `useBulkSelection`): altfel dialogul arăta „50 accounts" pentru o operație pe mii de
    // rânduri, iar comparația cu pragul de confirmare pornea de la numărul greșit.
    const effectiveCount = matchingFilter && totalKnown ? total : selectedCount;
    const noun = effectiveCount === 1 ? resourceNounSingular : resourceNounPlural;
    const ownerName = owners.find((owner) => owner.id === ownerId)?.name ?? '';
    // P2-001 (code review) — plafonul Agentului verificat ȘI client-side, înainte de
    // submit: serverul (`DispatchBulkOperationAction`) rămâne sursa de adevăr, dar
    // dezactivarea aici scutește un drum dus-întors doar ca să afli refuzul.
    const overRowCap = rowCap !== null && effectiveCount > rowCap;

    const dispatch = (confirmed: boolean) => {
        setProcessing(true);
        setError(null);

        router.post(
            dispatchUrl,
            {
                selectAllMatching: matchingFilter,
                ids: matchingFilter ? [] : selectedIds,
                owner_user_id: ownerId,
                confirmed,
            },
            {
                // P2-001 (code review) — refuzurile serverului (plafonul de rol, DEMO_MODE,
                // pragul de confirmare fără `confirmed`) ajungeau pe `errors.selection`, dar
                // nimic nu le citea: dialogul se închidea fără nicio explicație.
                onError: (errors) => setError(errors.selection ?? 'This operation could not be started.'),
                onFinish: () => {
                    setProcessing(false);
                    setConfirmOpen(false);
                },
            },
        );
    };

    const handleReassignClick = () => {
        if (!ownerId || overRowCap) {
            return;
        }

        if (effectiveCount > confirmationThreshold) {
            setConfirmOpen(true);
            return;
        }

        // Sub prag — serverul nu cere `confirmed` (FR-BULK-01: doar peste prag).
        dispatch(false);
    };

    return (
        <div aria-live="polite" aria-atomic="true">
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

                    <label className="flex items-center gap-1.5 text-text-2">
                        Reassign to
                        <select
                            value={ownerId}
                            onChange={(event) => setOwnerId(event.target.value)}
                            className={controlClass}
                        >
                            <option value="">Choose owner…</option>
                            {owners.map((owner) => (
                                <option key={owner.id} value={owner.id}>
                                    {owner.name}
                                </option>
                            ))}
                        </select>
                    </label>

                    <Button variant="primary" disabled={!ownerId || processing || overRowCap} onClick={handleReassignClick}>
                        Reassign owner
                    </Button>

                    <Button onClick={onClearSelection} disabled={processing}>
                        Clear selection
                    </Button>

                    <ConfirmDialog
                        open={confirmOpen}
                        title={`Reassign ${effectiveCount.toLocaleString('en-US')} ${noun}?`}
                        onConfirm={() => dispatch(true)}
                        onClose={() => setConfirmOpen(false)}
                        processing={processing}
                        confirmLabel="Reassign"
                    >
                        {`This changes the owner of ${effectiveCount.toLocaleString('en-US')} ${resourceNounPlural} to ${ownerName}. It runs in the background — you'll land on a status page and can cancel it while it's running.`}
                    </ConfirmDialog>

                    {overRowCap && (
                        <p role="alert" className="w-full rounded-md bg-danger-tint px-3 py-2 text-sm text-danger">
                            {`This would affect ${effectiveCount.toLocaleString('en-US')} ${resourceNounPlural}, above your role's limit of ${rowCap?.toLocaleString('en-US')} rows per operation.`}
                        </p>
                    )}

                    {error && (
                        <p role="alert" className="w-full rounded-md bg-danger-tint px-3 py-2 text-sm text-danger">
                            {error}
                        </p>
                    )}
                </div>
            )}
        </div>
    );
}
