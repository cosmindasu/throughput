import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState, type DragEvent, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import AnimatedNumber from '@/Components/AnimatedNumber';
import { ButtonLink } from '@/Components/Button';
import DealCard, { dealCardDomId } from '@/Components/Deals/DealCard';
import LostReasonDialog from '@/Components/Deals/LostReasonDialog';
import ViewSwitcher from '@/Components/Deals/ViewSwitcher';
import { useAnnounce } from '@/hooks/useAnnounce';
import { useLocale } from '@/hooks/useLocale';
import AppLayout from '@/Layouts/AppLayout';
import { formatMoney } from '@/lib/money';
import { stageColors, summarizePipeline } from '@/lib/stageColor';
import { TONE, type Tone } from '@/lib/tone';
import type { DealsBoardColumn, DealsKanbanPageProps, DealStage, DealSummary, LostReason } from '@/types/generated';

interface PendingLostMove {
    deal: DealSummary;
    targetStage: DealStage;
}

/**
 * `Deals/Kanban` — §9.3. Coloane = etapele pipeline-ului implicit, după `position`.
 * Drag & drop HTML5 nativ, optimist, cu revenire vizuală + mesaj explicit la respingere
 * server (ex: „Set a deal value before marking as Won"). Alternativa de tastatură
 * (`MoveStageMenu`, FR-DEAL-01) e pe fiecare card, nu doar pe unele.
 */
export default function Kanban() {
    const { t } = useTranslation('deals');
    const { props } = usePage<DealsKanbanPageProps>();
    const { pipeline, columns: serverColumns, ownerFilter, can, workspace } = props;
    const workspaceSlug = workspace?.slug ?? '';

    const [columns, setColumns] = useState<DealsBoardColumn[]>(serverColumns);
    // Sincronizarea cu props-ul de server NU se face într-un `useEffect` (ar declanșa un
    // al doilea randare, cascadat, doar ca să copieze un prop în state — React 19
    // semnalează exact acest anti-pattern). Tiparul recomandat: comparăm referința în
    // timpul randării și „ajustăm" state-ul direct — React reia randarea, fără commit
    // intermediar. Necesar fiindcă state-ul local ține și mutările optimiste ale
    // drag & drop, deci nu poate fi doar `serverColumns` direct.
    const [syncedServerColumns, setSyncedServerColumns] = useState(serverColumns);
    if (serverColumns !== syncedServerColumns) {
        setSyncedServerColumns(serverColumns);
        setColumns(serverColumns);
    }

    const [errorMessage, setErrorMessage] = useState<string | null>(null);
    // FE-03 (audit) — `useAnnounce` garantează tranziția REALĂ `'' → text` la fiecare
    // mutare, chiar dacă două anunțuri consecutive ar produce ACELAȘI șir (ex. două
    // deal-uri cu titlu identic mutate consecutiv pe aceeași etapă) — vezi
    // `.ai/rules/frontend.md`, „O regiune aria-live nu reacționează la setState, ci la
    // mutația DOM-ului”.
    const { announcement, announce } = useAnnounce();
    const [draggedDeal, setDraggedDeal] = useState<DealSummary | null>(null);
    // Coloana peste care se află cardul tras și cardul tocmai aterizat: feedback VIZUAL, nu
    // stare de business — niciuna nu influențează ce se trimite serverului.
    const [overStageId, setOverStageId] = useState<string | null>(null);
    const [lastMovedId, setLastMovedId] = useState<string | null>(null);
    // Separat de `draggedDeal`, deși amândouă descriu același gest: ăsta e DOAR aspectul
    // (cardul estompat), iar el singur se amână cu un cadru. Vezi `onDragStart` mai jos.
    const [dimmedDealId, setDimmedDealId] = useState<string | null>(null);
    const dimFrame = useRef(0);
    const locale = useLocale();
    // Miezul nopții local, o singură dată pentru tot board-ul: `Date.now()` direct în corpul
    // componentei e impur (`react-hooks/purity`), iar per card ar fi și risipă.
    const [today] = useState(() => new Date().setHours(0, 0, 0, 0));
    const colors = useMemo(() => stageColors(columns.map((column) => column.stage)), [columns]);
    const summary = useMemo(() => summarizePipeline(columns), [columns]);
    const boardCurrency = workspace?.currency ?? 'USD';
    const money = useMemo(() => (value: number) => formatMoney(value, boardCurrency, locale, { maximumFractionDigits: 0 }), [boardCurrency, locale]);
    const [pendingLostMove, setPendingLostMove] = useState<PendingLostMove | null>(null);
    const [dialogProcessing, setDialogProcessing] = useState(false);
    // Id-ul cardului al cărui focus trebuie restaurat explicit, ODATĂ ce coloanele
    // reflectă deja mutarea (P2-002) — cardul se remontează într-o altă coloană (părinte
    // VDOM diferit), deci React nu-l reconciliază după `key`, iar elementul care avea
    // focus rămâne detașat din DOM. Într-un `ref`, nu `useState`: efectul de mai jos DOAR
    // citește valoarea și mută focusul (o sincronizare cu DOM-ul, nu o schimbare de
    // stare React), deci n-are ce `setState` să declanșeze randări în cascadă.
    const pendingFocusDealId = useRef<string | null>(null);
    const [focusTick, setFocusTick] = useState(0);

    const allStages = columns.map((column) => column.stage);

    useEffect(() => {
        if (!pendingFocusDealId.current) {
            return;
        }

        document.getElementById(dealCardDomId(pendingFocusDealId.current))?.focus();
        pendingFocusDealId.current = null;
    }, [focusTick, columns]);

    const requestFocus = (dealId: string) => {
        pendingFocusDealId.current = dealId;
        setFocusTick((tick) => tick + 1);
    };

    const move = (deal: DealSummary, targetStage: DealStage, lostReason?: LostReason) => {
        // Focusul se restaurează DOAR dacă era deja pe cardul ăsta (drag & drop e
        // mouse-driven, dar un utilizator poate avea focusul pe declanșatorul „Move to
        // stage…" de la o interacțiune anterioară) — altfel am fura focusul de pe un alt
        // element al paginii pe care utilizatorul îl folosea în timp ce cererea era în zbor.
        const cardElement = document.getElementById(dealCardDomId(deal.id));
        const restoreFocus = cardElement !== null && cardElement.contains(document.activeElement);

        setColumns((current) => applyOptimisticMove(current, deal, targetStage));
        setErrorMessage(null);
        setDialogProcessing(true);

        router.patch(
            `/${workspaceSlug}/deals/${deal.id}/stage`,
            { to_stage_id: targetStage.id, ...(lostReason ? { lost_reason: lostReason } : {}) },
            {
                preserveScroll: true,
                onError: (errors) => {
                    // Revert PUNCTUAL, doar pentru ACEST deal, aplicat pe starea CURENTĂ
                    // printr-un updater funcțional — NU pe un instantaneu de dinaintea
                    // mutării (P2-001): dacă între timp un ALT card a fost mutat (reușit
                    // sau încă în zbor), un `setColumns(previousColumns)` l-ar șterge
                    // vizual, deși respingerea asta nu-l privește.
                    setColumns((current) => revertOptimisticMove(current, deal, targetStage));
                    setErrorMessage(errors.to_stage_id ?? errors.lost_reason ?? t('kanban.error'));
                },
                onSuccess: () => {
                    setPendingLostMove(null);
                    // Cardul tocmai aterizat primește animația de așezare — se vede UNDE a
                    // ajuns, nu doar că lista s-a schimbat.
                    setLastMovedId(deal.id);
                    announce(t('kanban.moved', { title: deal.title, stage: targetStage.name }));
                    if (restoreFocus) {
                        requestFocus(deal.id);
                    }
                },
                onFinish: () => setDialogProcessing(false),
            },
        );
    };

    const handleCardMoved = (deal: DealSummary, targetStage: DealStage) => {
        setLastMovedId(deal.id);
        announce(t('kanban.moved', { title: deal.title, stage: targetStage.name }));
        requestFocus(deal.id);
    };

    const handleDrop = (event: DragEvent<HTMLElement>, targetStage: DealStage) => {
        event.preventDefault();

        if (!draggedDeal || draggedDeal.stage.id === targetStage.id) {
            setDraggedDeal(null);
            return;
        }

        if (targetStage.isLost) {
            setPendingLostMove({ deal: draggedDeal, targetStage });
            setDraggedDeal(null);
            return;
        }

        move(draggedDeal, targetStage);
        setDraggedDeal(null);
    };

    const ownerFilterUrl = (value: 'me' | 'all') => `/${workspaceSlug}/deals/board?owner=${value}`;
    const viewAllUrl = (stageId: string) =>
        `/${workspaceSlug}/deals?filter[stage]=${stageId}${ownerFilter === 'me' ? '&filter[owner]=me' : ''}`;

    return (
        <>
            <Head title={t('kanban.title')} />

            <div className="flex flex-col gap-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-xl font-semibold text-text">{pipeline.name}</h1>
                        <p className="mt-1 text-sm text-text-2">{t('kanban.hint')}</p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <ViewSwitcher workspaceSlug={workspaceSlug} active="board" />

                        <div className="flex overflow-hidden rounded-md border border-control text-sm">
                            <OwnerToggleLink href={ownerFilterUrl('me')} active={ownerFilter === 'me'}>
                                {t('kanban.owner.mine')}
                            </OwnerToggleLink>
                            <OwnerToggleLink href={ownerFilterUrl('all')} active={ownerFilter === 'all'}>
                                {t('kanban.owner.all')}
                            </OwnerToggleLink>
                        </div>

                        {can.managePipeline && (
                            <ButtonLink href={`/${workspaceSlug}/pipeline`}>{t('kanban.managePipeline')}</ButtonLink>
                        )}
                    </div>
                </div>

                {errorMessage && (
                    <p role="alert" className="rounded-md bg-danger-tint px-3 py-2 text-sm text-danger">
                        {errorMessage}
                    </p>
                )}

                {/* Regiune SEPARATĂ de alerta de eroare de mai sus (P2-003): o alertă
                    întrerupe imediat cititorul de ecran, o anunțare „polite" așteaptă o
                    pauză — succesul unei mutări nu justifică întreruperea. */}
                <p aria-live="polite" role="status" className="sr-only">
                    {announcement}
                </p>

                {/*
                    Sinteza se calculează din ACELEAȘI coloane pe care pagina le desenează
                    (`summarizePipeline`), nu dintr-o a doua interogare: n-are cum să
                    divergă de ce se vede, și se actualizează singură după o mutare
                    optimistă, înainte ca serverul să confirme.
                */}
                <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <SummaryStat label={t('kanban.summary.open')} tone="accent">
                        <AnimatedNumber value={summary.openValue} format={money} />
                    </SummaryStat>
                    <SummaryStat label={t('kanban.summary.weighted')} tone="info">
                        <AnimatedNumber value={summary.weightedValue} format={money} />
                    </SummaryStat>
                    <SummaryStat label={t('kanban.summary.deals')} tone="neutral">
                        {summary.openCount}
                    </SummaryStat>
                    {/* `null` = nu s-a închis încă nimic; „0%" ar afirma că se pierde tot. */}
                    <SummaryStat label={t('kanban.summary.winRate')} tone={summary.winRate !== null && summary.winRate >= 0.5 ? 'success' : 'warning'}>
                        {summary.winRate === null ? '—' : `${Math.round(summary.winRate * 100)}%`}
                    </SummaryStat>
                </dl>

                <div className="flex gap-4 overflow-x-auto pb-4">
                    {columns.map((column) => (
                        <section
                            key={column.stage.id}
                            aria-label={column.stage.name}
                            onDragOver={(event) => event.preventDefault()}
                            onDragEnter={() => setOverStageId(column.stage.id)}
                            // `dragleave` se declanșează și la trecerea peste COPII, nu doar
                            // la ieșirea din coloană: fără verificarea de conținere, evidența
                            // zonei de drop ar clipi la fiecare card survolat.
                            onDragLeave={(event) => {
                                if (!event.currentTarget.contains(event.relatedTarget as Node | null)) {
                                    setOverStageId(null);
                                }
                            }}
                            onDrop={(event) => {
                                setOverStageId(null);
                                // Și estomparea: după un `drop` sintetic (sau pe unele căi de
                                // drag asistiv) `dragend` nu mai vine, iar cardul ar rămâne
                                // translucid la destinație.
                                cancelAnimationFrame(dimFrame.current);
                                setDimmedDealId(null);
                                handleDrop(event, column.stage);
                            }}
                            // Coloana de PLECARE nu se evidențiază: a lăsa cardul unde era nu e o mutare.
                            data-over={overStageId === column.stage.id && draggedDeal?.stage.id !== column.stage.id ? true : undefined}
                            className="flex w-72 shrink-0 flex-col gap-3 rounded-lg border border-border border-t-[3px] bg-raised p-3 transition-colors duration-150 motion-reduce:transition-none data-[over]:border-accent-fill data-[over]:bg-accent-tint"
                            style={{ borderTopColor: colors.get(column.stage.id) }}
                        >
                            <header className="flex flex-col gap-1">
                                <div className="flex items-center justify-between gap-2">
                                    <h2 className="text-sm font-semibold text-text">{column.stage.name}</h2>
                                    <span className="numeric rounded-full bg-surface px-2 py-0.5 text-xs font-medium text-text-2 ring-1 ring-inset ring-border">{column.total}</span>
                                </div>
                                {/*
                                    Suma e pe TOATĂ etapa, nu pe cardurile vizibile (plafonate
                                    la 50) — altfel antetul ar descrie fereastra, nu etapa.
                                    Ponderarea cu probabilitatea stă lângă ea fiindcă asta e
                                    întrebarea reală a unui pipeline: nu „cât e pe masă", ci
                                    „cât se așteaptă să intre".
                                */}
                                <p className="numeric text-sm font-medium text-text">
                                    {money(column.valueTotal)}
                                    {!column.stage.isWon && !column.stage.isLost && column.stage.probability !== null && (
                                        <span className="ml-2 text-xs font-normal text-text-3">
                                            {column.stage.probability}% · {money((column.valueTotal * column.stage.probability) / 100)}
                                        </span>
                                    )}
                                </p>
                                {/* Cota coloanei din pipeline-ul deschis — DECOR: valoarea e deja text, deasupra. */}
                                {!column.stage.isWon && !column.stage.isLost && summary.openValue > 0 && (
                                    <span aria-hidden="true" className="block h-1 overflow-hidden rounded-full bg-border-soft">
                                        <span
                                            className="block h-full rounded-full transition-[width] duration-500 motion-reduce:transition-none"
                                            style={{ width: `${(column.valueTotal / summary.openValue) * 100}%`, backgroundColor: colors.get(column.stage.id) }}
                                        />
                                    </span>
                                )}
                            </header>

                            <div className="flex flex-col gap-2">
                                {column.deals.map((deal) => (
                                    <DealCard
                                        key={deal.id}
                                        deal={deal}
                                        stages={allStages}
                                        workspaceSlug={workspaceSlug}
                                        today={today}
                                        dragging={dimmedDealId === deal.id}
                                        justMoved={lastMovedId === deal.id}
                                        /*
                                            Cardul tras se înregistrează SINCRON, estomparea
                                            lui pe cadrul următor — două stări, fiindcă au
                                            constrângeri contrare.

                                            Amânarea există fiindcă browserul fotografiază
                                            cardul pentru imaginea de drag la sfârșitul lui
                                            `dragstart`: o clasă de opacitate aplicată acolo se
                                            „coace" în fotografie, iar utilizatorul trage un
                                            card deja translucid.

                                            Dar `draggedDeal` NU poate aștepta: `handleDrop` îl
                                            citește, iar o lăsare rapidă — sau un `drop`
                                            sintetic, ca în `deals-pipeline.spec.ts` — ajunge
                                            înaintea cadrului și găsește `null`, deci mutarea
                                            se pierde în tăcere. Prins de suita e2e după ce
                                            amânasem AMBELE: a doua mutare din trei pica.
                                            Într-un tab de fundal, unde `requestAnimationFrame`
                                            nu se execută deloc, nu s-ar fi mutat nimic
                                            niciodată.
                                        */
                                        onDragStart={(_event, draggedDealCard) => {
                                            setDraggedDeal(draggedDealCard);
                                            // Id-ul cadrului se păstrează: dacă `dragend` vine
                                            // ÎNAINTEA lui (drag foarte scurt, sau sintetic),
                                            // fără anulare `setDimmedDealId(id)` ar rula după
                                            // `setDimmedDealId(null)` și cardul ar rămâne
                                            // translucid la nesfârșit.
                                            dimFrame.current = requestAnimationFrame(() => setDimmedDealId(draggedDealCard.id));
                                        }}
                                        onDragEnd={() => {
                                            cancelAnimationFrame(dimFrame.current);
                                            setDraggedDeal(null);
                                            setDimmedDealId(null);
                                            setOverStageId(null);
                                        }}
                                        onError={setErrorMessage}
                                        onMoved={(stage) => handleCardMoved(deal, stage)}
                                    />
                                ))}

                                {/* `text-text-2`, nu `text-text-3`: pe tenta de drop
                                    (`--accent-tint`) al doilea dă 4,34:1 pe tema închisă — sub
                                    pragul de 4,5:1 exact în starea în care textul contează. */}
                                {column.deals.length === 0 && (
                                    <p className="rounded-md border border-dashed border-border px-3 py-6 text-center text-xs text-text-2">
                                        {t('kanban.emptyStage')}
                                    </p>
                                )}
                            </div>

                            {column.hasMore && (
                                <Link
                                    href={viewAllUrl(column.stage.id)}
                                    className="text-center text-xs font-medium text-accent-text hover:underline"
                                >
                                    {t('kanban.viewAll', { count: column.total })}
                                </Link>
                            )}
                        </section>
                    ))}
                </div>
            </div>

            <LostReasonDialog
                open={pendingLostMove !== null}
                processing={dialogProcessing}
                onCancel={() => setPendingLostMove(null)}
                onConfirm={(reason) => pendingLostMove && move(pendingLostMove.deal, pendingLostMove.targetStage, reason)}
            />
        </>
    );
}

/**
 * O placă din banda de sinteză. Aceeași gramatică vizuală ca `KpiTile` de pe dashboard (muchie
 * tentată, etichetă mică, cifră mare), dar `<dt>`/`<dd>` într-un `<dl>`: aici perechile
 * etichetă-valoare sunt chiar structura, nu patru carduri independente.
 */
function SummaryStat({ label, tone, children }: { label: string; tone: Tone; children: ReactNode }) {
    return (
        // `min-w-0` + `truncate`: o celulă de grid nu coboară sub lățimea conținutului ei, iar
        // o sumă de pipeline cu cifre tabulare („$41.612.798") depășește cele ~170px pe care
        // le are la 375px pe două coloane — împingea DOCUMENTUL lateral, nu doar celula.
        // Defectul exista de la început, dar era ascuns: `AnimatedNumber` scria „$0" în DOM
        // înainte de primul cadru, deci lățimea reală apărea abia la sfârșitul numărătorii,
        // după ce testul măsurase. Odată pusă valoarea corectă în markup de la randare (ca un
        // tab de fundal să n-o rateze), a ieșit la suprafață.
        //
        // Cifra scade și cu o treaptă de corp sub `sm`, ca trunchierea să rămână ultima plasă,
        // nu mecanismul obișnuit — un total de bani tăiat la jumătate n-ar fi o informație.
        <div className={`min-w-0 rounded-lg border border-l-4 border-border bg-surface px-4 py-3 ${TONE[tone].edge}`}>
            <dt className="truncate text-xs text-text-2">{label}</dt>
            <dd className="numeric mt-0.5 truncate text-base font-semibold text-text sm:text-lg">{children}</dd>
        </div>
    );
}

function OwnerToggleLink({ href, active, children }: { href: string; active: boolean; children: ReactNode }) {
    return (
        <Link
            href={href}
            preserveScroll
            aria-current={active ? 'page' : undefined}
            className={`px-3 py-1.5 transition-colors ${active ? 'bg-accent-fill text-accent-on' : 'bg-surface text-text-2 hover:bg-row-hover'}`}
        >
            {children}
        </Link>
    );
}

function applyOptimisticMove(columns: DealsBoardColumn[], deal: DealSummary, target: DealStage): DealsBoardColumn[] {
    const movedDeal: DealSummary = {
        ...deal,
        stage: { id: target.id, name: target.name, isWon: target.isWon, isLost: target.isLost },
        status: target.isWon ? 'won' : target.isLost ? 'lost' : 'open',
    };

    return columns.map((column) => {
        if (column.stage.id === deal.stage.id) {
            return { ...column, deals: column.deals.filter((item) => item.id !== deal.id), total: Math.max(column.total - 1, 0), valueTotal: Math.max(column.valueTotal - (deal.value ?? 0), 0) };
        }

        if (column.stage.id === target.id) {
            return { ...column, deals: [movedDeal, ...column.deals], total: column.total + 1, valueTotal: column.valueTotal + (deal.value ?? 0) };
        }

        return column;
    });
}

/**
 * Inversul PUNCTUAL al `applyOptimisticMove`, pentru un deal respins de server (P2-001).
 * Operează pe `columns` dat — apelantul îl aplică printr-un updater funcțional pe starea
 * CURENTĂ, nu pe un instantaneu vechi, ca să compună corect cu mutări concurente ale
 * altor carduri.
 *
 * Verifică ÎNTÂI dacă deal-ul mai e, de fapt, în `targetStage` — o resincronizare din
 * props-urile serverului (declanșată de succesul ALTEI mutări, care reîncarcă tot
 * board-ul) poate fi ajuns între timp și poate fi mutat deja deal-ul înapoi pe baza
 * stării reale din DB. Fără verificarea asta, revenirea ar decrementa `total` a doua
 * oară pentru un card care nu mai e acolo.
 */
function revertOptimisticMove(columns: DealsBoardColumn[], deal: DealSummary, targetStage: DealStage): DealsBoardColumn[] {
    const targetColumn = columns.find((column) => column.stage.id === targetStage.id);
    const stillOptimisticallyThere = targetColumn?.deals.some((item) => item.id === deal.id) ?? false;

    if (!stillOptimisticallyThere) {
        return columns;
    }

    return columns.map((column) => {
        if (column.stage.id === targetStage.id) {
            return { ...column, deals: column.deals.filter((item) => item.id !== deal.id), total: Math.max(column.total - 1, 0), valueTotal: Math.max(column.valueTotal - (deal.value ?? 0), 0) };
        }

        if (column.stage.id === deal.stage.id) {
            return { ...column, deals: [deal, ...column.deals], total: column.total + 1, valueTotal: column.valueTotal + (deal.value ?? 0) };
        }

        return column;
    });
}

Kanban.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
