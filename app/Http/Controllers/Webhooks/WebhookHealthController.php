<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\WebhookEvent;
use App\Support\SingleOwnerDeployment;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ecranul „Webhook health" — specs.md §25.2 („Ecran «Webhook health» (intern, §12.3) |
 * Evenimente Stripe `failed` | Alertă: orice eveniment `failed` neresolvat > 1 oră") și
 * criteriul de acceptanță din §12.3 („`status = failed` cu `error_message` populat, VIZIBIL
 * ÎNTR-UN ECRAN DE OPERARE"). Până acum ecranul nu exista în cod — singurul loc unde starea
 * unui webhook se putea vedea era baza de date.
 *
 * DE CE E AICI, și nu în `App\Http\Controllers\Web\Settings` (unde stau celelalte ecrane de
 * Settings): disciplina de fișiere a acestui lot — `Web/Settings/**` aparține altui lot care
 * rulează ÎN PARALEL pe același worktree. Ecranul e al modulului de webhook-uri, deci stă
 * lângă controllerul care scrie rândurile pe care le afișează. Mutarea lui e o redenumire de
 * namespace, semnalată în raport.
 *
 * GARDĂ: `billing.view` — permisiunea EXISTENTĂ a ecranului de abonament (Owner-only, §7.4
 * „Abonament & billing Throughput": CRUD doar Owner, `—` pentru restul). Nicio permisiune
 * nouă: `App\Support\Permissions` e fișier de integrare, neatins de acest lot, iar
 * evenimentele afișate aici sunt exclusiv evenimente de abonament Stripe (§12.3) — exact
 * aceeași suprafață de date ca pagina de billing, deci același drept.
 *
 * `webhook_events` NU are `tenant_id` și NU are RLS (§19.1, decizie de model: e o coadă de
 * deduplicare la nivel de DEPLOYMENT, iar ruta de webhook e publică, fără context). Deci
 * lista nu poate fi „a workspace-ului curent". Consecința e tratată explicit, nu ignorată:
 * ecranul NU expune niciodată `payload`-ul (singurul loc cu date de business), ci doar
 * momentul, tipul evenimentului, statusul și `error_message`-ul SCRIS DE NOI. În acest
 * deployment, cei trei tenanți sunt demo-urile aceluiași proprietar, iar evenimentele
 * `ignored` vin din sandbox-ul Stripe împărțit cu alt proiect al aceluiași proprietar —
 * niciun terț nu are date aici. Dacă vreodată tenanții devin organizații independente,
 * ecranul trebuie mutat în afara workspace-ului (super-admin), nu filtrat pe `stripe_id`:
 * exact rândurile fără mapare sunt cele care interesează operațional.
 *
 * GARDĂ EXPLICITĂ (SEC-02, audit 2026-09-23, `docs/reviews/2026-09-23_audit/01-securitate.md`):
 * `App\Support\SingleOwnerDeployment::active()` verifică, la FIECARE cerere, dacă premisa
 * de mai sus ține — un singur Owner comun tuturor tenanților existenți (FR-TEN-01) — și
 * refuză cu 403 (fail-closed, aceeași formă ca `billing.view` mai jos, nu 404: nu ascundem
 * EXISTENȚA ecranului, doar dreptul de a-l vedea cross-tenant) din clipa în care apare un
 * tenant fără Owner comun cu restul. NU e un flag manual: se derivă din `model_has_roles`,
 * deci nu depinde de cineva care-și amintește să-l comute la primul tenant plătitor real —
 * vezi docblock-ul clasei pentru semnal și motivare.
 */
final class WebhookHealthController extends Controller
{
    /**
     * Ultimele N evenimente. Fără paginare pe cursor (deci fără `App\Support\Lists`, fișier
     * comun): un ecran de operare se uită la ce s-a întâmplat recent, iar retenția reală a
     * tabelei e dată de reset-ul zilnic al demo-ului, nu de acest ecran.
     */
    private const RECENT_LIMIT = 100;

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('billing.view'), 403);
        abort_unless(SingleOwnerDeployment::active(), 403);

        $status = $request->string('status')->toString();
        $status = in_array($status, self::statuses(), true) ? $status : null;

        $events = WebhookEvent::query()
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            // `received_at` e `timestamp(0)` ca tot restul proiectului — secunde întregi,
            // deci două evenimente livrate în aceeași secundă nu se ordonează singure.
            // Tiebreaker pe `id` (ULID, sortabil cronologic), regula din `.ai/rules/tenancy.md`.
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->limit(self::RECENT_LIMIT)
            ->get(['id', 'source', 'event_id', 'type', 'status', 'received_at', 'processed_at', 'error_message']);

        return Inertia::render('Settings/WebhookHealth/Index', [
            // Contract de props explicit, construit AICI: `app/Http/Resources/**` aparține
            // altui lot din acest val (un `WebhookEventResource` e propus în raport).
            // NICIODATĂ `payload` — vezi docblock-ul clasei.
            'events' => $events->map(fn (WebhookEvent $event) => [
                'id' => $event->id,
                'source' => $event->source,
                'eventId' => $event->event_id,
                'type' => $event->type,
                'status' => $event->status,
                'receivedAt' => $event->received_at?->toIso8601String(),
                'processedAt' => $event->processed_at?->toIso8601String(),
                'message' => $event->error_message,
            ])->all(),
            'counts' => $this->counts(),
            'statuses' => self::statuses(),
            'filter' => ['status' => $status],
        ]);
    }

    /**
     * Rezumatul de sus al ecranului. Numărul care declanșează alerta din §25.2 e cel de
     * `failed` — `ignored` e numărat SEPARAT tocmai ca să nu-l umfle (decizia din
     * 2026-09-20: evenimentele altui proiect din sandbox-ul comun nu sunt eșecuri).
     *
     * @return array<string, int>
     */
    private function counts(): array
    {
        $counts = WebhookEvent::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return collect(self::statuses())
            ->mapWithKeys(fn (string $status) => [$status => (int) ($counts[$status] ?? 0)])
            ->all();
    }

    /** @return list<string> */
    private static function statuses(): array
    {
        return [
            WebhookEvent::STATUS_RECEIVED,
            WebhookEvent::STATUS_PROCESSING,
            WebhookEvent::STATUS_PROCESSED,
            WebhookEvent::STATUS_FAILED,
            WebhookEvent::STATUS_IGNORED,
        ];
    }
}
