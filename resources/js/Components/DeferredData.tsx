import { Deferred, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';

/**
 * Prop-urile cerute, cu `undefined`-ul scos: forma pe care o au DUPĂ ce Inertia le-a adus.
 */
type Resolved<P, K extends keyof P> = { [Key in K]-?: NonNullable<P[Key]> };

interface DeferredDataProps<P, K extends keyof P & string> {
    /** Numele prop-urilor amânate, exact ca în `Inertia::defer(...)` din controller. */
    keys: readonly K[];
    /** Ce se vede cât timp datele sunt pe drum — de regulă un `<TableSkeleton />`. */
    fallback: ReactNode;
    /** Primește prop-urile GARANTAT prezente. */
    children: (resolved: Resolved<P, K>) => ReactNode;
}

/**
 * `<Deferred>` de la Inertia, dar care își **predă** datele copilului, tipate ca prezente.
 *
 * ## De ce există
 *
 * `DeferredProp<T>` (vezi `types/generated.d.ts`) face ca `tsc` să respingă orice citire a
 * unui prop amânat — corect, fiindcă la prima randare chiar lipsește. Dar atunci și citirile
 * LEGITIME, din interiorul unui `<Deferred>`, sunt respinse: componenta care le face nu are
 * cum să-i spună compilatorului „pe mine mă montează un `<Deferred>`, deci datele au sosit".
 *
 * Fără o construcție ca asta, singurul răspuns ar fi `!` sau un cast la fiecare sit —
 * adică exact tăcerea pe care `DeferredProp` a fost introdus s-o spargă.
 *
 * ## De ce garanția e reală, nu o convenție
 *
 * `children` e o FUNCȚIE, nu un element. Diferența e tot ce contează aici:
 *
 * ```tsx
 * // Nu ar merge — `f(props)` se evaluează când se construiește elementul, adică în
 * // părinte, ÎNAINTE ca `<Deferred>` să decidă ceva. Exact bug-ul, cu alt chip.
 * <Deferred data={keys} fallback={fallback}>{f(props)}</Deferred>
 * ```
 *
 * De aceea apelul stă în `<ResolvedChildren>`, o componentă pe care `<Deferred>` o
 * montează **doar după** ce prop-urile au sosit. Nicio cale de a obține tipul rezolvat nu
 * ocolește montarea aia: tipul `Resolved<P, K>` se produce numai din `children`, iar
 * `children` e chemat numai de acolo. Nu e disciplină de echipă, e singurul drum.
 *
 * ## Castul
 *
 * Există unul singur, în `ResolvedChildren`, și e justificat de construcție: componenta nu
 * se montează decât cu prop-urile prezente. Dacă ajunge să fie greșit, a fost mutată de sub
 * `<Deferred>` — iar `e2e/specs/routes-smoke.spec.ts` prinde asta la rulare.
 *
 * ## Exemplu
 *
 * ```tsx
 * <DeferredData<DealsIndexPageProps, 'deals' | 'total'>
 *     keys={['deals', 'total']}
 *     fallback={<TableSkeleton columns={6} />}
 * >
 *     {({ deals, total }) => <DealsTable deals={deals} total={total} />}
 * </DeferredData>
 * ```
 *
 * Pe paginile unde `<Deferred>` învelește direct JSX care se apără deja singur
 * (`{accounts && …}`, ca în `Accounts/Index.tsx`), `<Deferred>` simplu rămâne potrivit —
 * acolo `tsc` are deja din ce îngusta tipul.
 */
export default function DeferredData<P, K extends keyof P & string>({
    keys,
    fallback,
    children,
}: DeferredDataProps<P, K>) {
    return (
        <Deferred data={keys as unknown as string[]} fallback={fallback}>
            <ResolvedChildren keys={keys}>{children}</ResolvedChildren>
        </Deferred>
    );
}

/**
 * Montată de `<Deferred>` DOAR după sosirea prop-urilor din `keys` — vezi docblock-ul de
 * mai sus. Aici, și numai aici, îngustarea la `NonNullable` e adevărată.
 */
function ResolvedChildren<P, K extends keyof P & string>({
    keys,
    children,
}: {
    keys: readonly K[];
    children: (resolved: Resolved<P, K>) => ReactNode;
}) {
    const pageProps = usePage().props as unknown as P;

    const resolved = Object.fromEntries(
        keys.map((key) => [key, pageProps[key]]),
    ) as Resolved<P, K>;

    return <>{children(resolved)}</>;
}
