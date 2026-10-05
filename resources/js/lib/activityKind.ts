import type { IconName } from '@/Components/Icon';
import type { Tone } from '@/lib/tone';

/**
 * CE s-a întâmplat, nu doar ce verb scrie în coloana `action`. `action` e un enum închis cu 9
 * valori; „etapă schimbată" și „factură plătită" sunt toate `updated` în baza de date, deci
 * diferența trebuie derivată pe server (`ActivityKind::of()`), unde se văd `auditable_type` și
 * `new_values` — NU ghicită în React din chei de coloană.
 *
 * Culoarea codifică CATEGORIA (creat / schimbat / progres / distrugere / securitate / rutină),
 * iconul codifică TIPUL. Șase tente nu pot da o culoare unică la 13 tipuri, și nu trebuie
 * forțate: două rânduri verzi cu iconuri diferite (plus / bifă) se citesc corect, șase nuanțe
 * de verde nu.
 */
export type ActivityKind =
    | 'created'
    | 'updated'
    | 'deleted'
    | 'stage_moved'
    | 'invoice_paid'
    | 'order_shipped'
    | 'login'
    | 'login_failed'
    | 'exported'
    | 'imported'
    | 'bulk_action'
    | 'role_changed'
    | 'member_deactivated';

export interface KindVisual {
    icon: IconName;
    tone: Tone;
}

export const KIND_VISUAL: Record<ActivityKind, KindVisual> = {
    created: { icon: 'created', tone: 'success' },
    updated: { icon: 'updated', tone: 'info' },
    deleted: { icon: 'deleted', tone: 'danger' },
    stage_moved: { icon: 'stageMoved', tone: 'accent' },
    invoice_paid: { icon: 'paid', tone: 'success' },
    order_shipped: { icon: 'shipment', tone: 'info' },
    login: { icon: 'login', tone: 'neutral' },
    login_failed: { icon: 'loginFailed', tone: 'warning' },
    exported: { icon: 'exported', tone: 'neutral' },
    imported: { icon: 'imports', tone: 'success' },
    bulk_action: { icon: 'bulk', tone: 'info' },
    role_changed: { icon: 'roleChanged', tone: 'warning' },
    member_deactivated: { icon: 'unassigned', tone: 'warning' },
};

const FALLBACK: KindVisual = { icon: 'activity', tone: 'neutral' };

/** `kind` necunoscut (rând vechi, enum extins mai târziu) → neutru, nu excepție. */
export function kindVisual(kind: string | undefined): KindVisual {
    return (kind && KIND_VISUAL[kind as ActivityKind]) || FALLBACK;
}
