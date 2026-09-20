<?php

namespace App\Http\Controllers\Web\Settings;

use App\Actions\Gdpr\DataExportPaths;
use App\Actions\Gdpr\RequestDataExportAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Gdpr\DataExportRequestResource;
use App\Models\DataExportRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Settings → Export data (FR-GDPR-01/02, BR-GDPR-01/02, US-GDPR-01, specs.md §20.5).
 *
 * Owner declanșează, Manager vede doar istoricul — diferența e în
 * `App\Policies\DataExportRequestPolicy`, peste permisiunile `data_exports.view` /
 * `data_exports.create` existente din Faza 1, nu recalculată din rol aici.
 *
 * ADR-013 — în cererea HTTP nu se întâmplă NIMIC din munca de export: `store()` scrie un
 * rând și pune un job în coadă. Interogarea celor șapte entități, serializarea și compresia
 * aparțin cozii; middleware-ul de context ține o tranzacție deschisă pe toată durata
 * cererii, pe un container cu `max_connections=30`.
 */
final class DataExportController extends Controller
{
    /**
     * Istoricul e mărginit: FR-GDPR-02 cere „status, dată, cine a declanșat", iar o cerere
     * de export e un eveniment rar (una activă per workspace). 50 de rânduri acoperă ani de
     * folosire fără să ceară paginare pe cursor — dacă vreodată n-ar mai ajunge, lista are
     * deja ordinea stabilă de care ar avea nevoie o paginare.
     */
    private const HISTORY_LIMIT = 50;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', DataExportRequest::class);

        $requests = DataExportRequest::query()
            ->with('requestedBy:id,name')
            // `.ai/rules/tenancy.md` — `requested_at` e `timestamp(0)`, deci două cereri
            // din aceeași secundă n-ar avea o ordine deterministă fără tiebreaker; ULID-ul
            // e sortabil cronologic, deci îl dă gratuit.
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->get();

        return Inertia::render('Settings/DataExport/Index', [
            'requests' => DataExportRequestResource::collection($requests),
            'can' => [
                // BR-GDPR-01 — Managerul vede ecranul, dar nu vede butonul: un buton care
                // duce la 403 se citește ca „aplicație stricată" (FR-RBAC-01).
                'create' => $request->user()->can('create', DataExportRequest::class),
            ],
            'retentionDays' => (int) config('throughput.limits.export_retention_days'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', DataExportRequest::class);

        app(RequestDataExportAction::class)->execute($request->user());

        return back()->with('success', __('flash.data_export.queued'));
    }

    public function download(DataExportRequest $dataExportRequest): StreamedResponse|RedirectResponse
    {
        // Policy-ul verifică autorul, starea ȘI expirarea (vezi `download()` acolo) — un
        // link vechi dintr-un email nu are voie să ocolească retenția de 7 zile.
        $this->authorize('download', $dataExportRequest);

        $disk = Storage::disk(DataExportPaths::DISK);

        // Fereastra dintre expirare și rularea jobului de curățare are și reversul ei: un
        // fișier șters manual, sau pierdut la un reset de demo, cu rândul încă valabil.
        if ($dataExportRequest->file_path === null || ! $disk->exists($dataExportRequest->file_path)) {
            return back()->with('error', __('flash.data_export.file_unavailable'));
        }

        return $disk->download(
            $dataExportRequest->file_path,
            DataExportPaths::downloadName(
                app('tenant')->slug,
                $dataExportRequest->requested_at?->format('Y-m-d') ?? now()->format('Y-m-d'),
            ),
        );
    }
}
