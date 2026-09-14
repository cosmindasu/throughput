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
    total: number;
    selectedCount: number;
    allOnPageSelected: boolean;
    matchingFilter: boolean;
    selectedIds: string[];
    owners: BulkOwnerOption[];
    confirmationThreshold: number;
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
    onSelectAllMatching,
    onClearSelection,
}: BulkSelectionBarProps) {
    const [ownerId, setOwnerId] = useState('');
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    const noun = selectedCount === 1 ? resourceNounSingular : resourceNounPlural;
    const ownerName = owners.find((owner) => owner.id === ownerId)?.name ?? '';

    const dispatch = () => {
        setProcessing(true);

        router.post(
            dispatchUrl,
            {
                selectAllMatching: matchingFilter,
                ids: matchingFilter ? [] : selectedIds,
                owner_user_id: ownerId,
            },
            {
                onFinish: () => {
                    setProcessing(false);
                    setConfirmOpen(false);
                },
            },
        );
    };

    const handleReassignClick = () => {
        if (!ownerId) {
            return;
        }

        if (selectedCount > confirmationThreshold) {
            setConfirmOpen(true);
            return;
        }

        dispatch();
    };

    return (
        <div aria-live="polite" aria-atomic="true">
            {selectedCount > 0 && (
                <div className="flex flex-wrap items-center gap-3 rounded-lg border border-border bg-raised px-4 py-2.5 text-sm">
                    <span className="font-medium text-text">
                        {selectedCount.toLocaleString('en-US')} {noun} selected
                    </span>

                    {allOnPageSelected && !matchingFilter && total > selectedCount && (
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

                    <Button variant="primary" disabled={!ownerId || processing} onClick={handleReassignClick}>
                        Reassign owner
                    </Button>

                    <Button onClick={onClearSelection} disabled={processing}>
                        Clear selection
                    </Button>

                    <ConfirmDialog
                        open={confirmOpen}
                        title={`Reassign ${selectedCount.toLocaleString('en-US')} ${noun}?`}
                        onConfirm={dispatch}
                        onClose={() => setConfirmOpen(false)}
                        processing={processing}
                        confirmLabel="Reassign"
                    >
                        {`This changes the owner of ${selectedCount.toLocaleString('en-US')} ${resourceNounPlural} to ${ownerName}. It runs in the background — you'll land on a status page and can cancel it while it's running.`}
                    </ConfirmDialog>
                </div>
            )}
        </div>
    );
}
