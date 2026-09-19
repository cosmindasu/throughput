<?php

namespace App\Http\Controllers\Web\Settings;

use App\Http\Controllers\Controller;
use App\Http\Resources\Settings\SentEmailResource;
use App\Models\SentEmail;
use App\Support\Lists\CursorPage;
use App\Support\Lists\SentEmailList;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Sent Emails (BR-DEMO-02, specs.md §22.3) — jurnalul emailurilor văzute de
 * `App\Mail\Transport\DemoInterceptingTransport`, indiferent de sursă (rapoarte programate
 * azi — Faza 4, invitații de membri din Faza 5 — „reutilizat neschimbat", plan §10).
 *
 * Gardă pe `sent_emails.view` (Owner/Manager, §7.4), NU pe `settings.view` (au și
 * Agent/Viewer — `App\Support\Permissions::forRoles()`): vezi `App\Policies\SentEmailPolicy`
 * pentru motivarea completă.
 */
final class SentEmailController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', SentEmail::class);

        $list = new SentEmailList;
        $listQuery = $list->parse($request);
        $user = $request->user();

        return Inertia::render('Settings/SentEmails/Index', [
            // FR-PERF-01/03 — deferred + cursor, ca orice listă cu volum potențial mare
            // (jurnalul de activitate e analogul cel mai apropiat, §17.3).
            'sentEmails' => Inertia::defer(fn () => CursorPage::make(
                $listQuery->paginate($list->query($listQuery, $user)),
                SentEmailResource::class,
            )),
            'list' => $listQuery->toArray(),
            'statuses' => SentEmailList::STATUSES,
        ]);
    }
}
