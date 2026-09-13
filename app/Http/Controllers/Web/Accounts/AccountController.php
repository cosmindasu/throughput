<?php

namespace App\Http\Controllers\Web\Accounts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounts\StoreAccountRequest;
use App\Http\Requests\Accounts\UpdateAccountRequest;
use App\Http\Resources\Accounts\AccountContactResource;
use App\Http\Resources\Accounts\AccountDealResource;
use App\Http\Resources\Accounts\AccountDetailResource;
use App\Http\Resources\Accounts\AccountResource;
use App\Jobs\Exports\ExportListJob;
use App\Models\Account;
use App\Models\BulkOperation;
use App\Models\Contact;
use App\Models\Membership;
use App\Support\Accounts\AccountActivityTimeline;
use App\Support\DemoMode;
use App\Support\Exports\CsvExporter;
use App\Support\Lists\AccountList;
use App\Support\Lists\CursorPage;
use App\Support\RecentlyViewed;
use App\Support\SavedViews\SavedViewDefaultRedirect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Conturi — FR-CRM-01…04, US-CRM-01…03 (specs.md §8). Controller subțire: validarea stă
 * în FormRequests, forma de ieșire în Resources, regulile de ownership în `AccountPolicy` +
 * `Account::deletionBlockedReason()`.
 */
final class AccountController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        $this->authorize('viewAny', Account::class);

        // FR-VIEW-02 — o vedere salvată setată ca implicit câștigă în fața filtrului de rol
        // (`owner=me` pentru Agent, mai jos) când URL-ul nu are NIMIC explicit încă.
        if (($redirect = SavedViewDefaultRedirect::resolve($request, 'accounts')) !== null) {
            return $redirect;
        }

        $list = new AccountList;
        $listQuery = $list->parse($request);
        $user = $request->user();

        return Inertia::render('Accounts/Index', [
            'accounts' => Inertia::defer(fn () => CursorPage::make(
                $listQuery->paginate($list->query($listQuery, $user)),
                AccountResource::class,
            )),
            'list' => $listQuery->toArray(),
            'owners' => $this->ownerOptions(),
            'can' => [
                'create' => $user->can('create', Account::class),
                'export' => $user->can('export', Account::class),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Account::class);

        return Inertia::render('Accounts/Create', [
            'owners' => $this->ownerOptions(),
            'prefill' => ['name' => (string) $request->query('name', '')],
        ]);
    }

    public function store(StoreAccountRequest $request): RedirectResponse
    {
        $data = $request->safe()->except(['contact', 'confirm_duplicate_email']);
        $data['owner_user_id'] ??= $request->user()->getKey();
        $contact = $request->validated('contact');

        $account = DB::transaction(function () use ($data, $contact, $request): Account {
            $account = new Account($data);
            $account->created_by = $request->user()->getKey();
            $account->save();

            if ($contact !== null && trim((string) ($contact['first_name'] ?? '')) !== '') {
                $primaryContact = new Contact([
                    'account_id' => $account->getKey(),
                    'first_name' => $contact['first_name'],
                    'last_name' => $contact['last_name'],
                    'email' => filled($contact['email'] ?? null) ? Str::lower(trim($contact['email'])) : null,
                    'phone' => $contact['phone'] ?? null,
                    'title' => $contact['title'] ?? null,
                    'is_primary' => true,
                ]);
                $primaryContact->created_by = $request->user()->getKey();
                $primaryContact->save();
            }

            return $account;
        });

        return redirect()->route('accounts.show', $account)->with('success', 'Account created.');
    }

    public function show(Request $request, Account $account): Response
    {
        $this->authorize('view', $account);

        $account->load('owner:id,name');
        $user = $request->user();

        RecentlyViewed::record($request, 'account', $account->getKey(), $account->name, route('accounts.show', $account));

        return Inertia::render('Accounts/Show', [
            'account' => new AccountDetailResource($account),
            'contacts' => AccountContactResource::collection(
                $account->contacts()->orderByDesc('is_primary')->orderBy('first_name')->get()
            ),
            'deals' => AccountDealResource::collection(
                $account->deals()->with('stage:id,name')->latest()->limit(20)->get()
            ),
            'activity' => Inertia::defer(fn () => AccountActivityTimeline::build($account)),
            'deletionBlockedReason' => $account->deletionBlockedReason(),
            'can' => [
                'edit' => $user->can('update', $account),
                'delete' => $user->can('delete', $account),
                'createDeal' => $user->can('deals.create'),
                'createContact' => $user->can('contacts.create'),
            ],
        ]);
    }

    public function edit(Account $account): Response
    {
        $this->authorize('update', $account);

        return Inertia::render('Accounts/Edit', [
            'account' => new AccountDetailResource($account),
            'owners' => $this->ownerOptions(),
        ]);
    }

    public function update(UpdateAccountRequest $request, Account $account): RedirectResponse
    {
        $account->update($request->validated());

        return redirect()->route('accounts.show', $account)->with('success', 'Account updated.');
    }

    public function destroy(Account $account): RedirectResponse
    {
        $this->authorize('delete', $account);

        if (($reason = $account->deletionBlockedReason()) !== null) {
            return back()->with('error', $reason);
        }

        $account->delete();

        return redirect()->route('accounts.index')->with('success', 'Account deleted.');
    }

    /**
     * US-CRM-03, §13.2 — exact interogarea ecranului curent (`AccountList` pe URL-ul
     * curent), sincron sub prag, job în coadă peste el. Ruta e declarată ÎNAINTEA
     * `accounts/{account}` (routes/web/accounts.php), altfel „export" ar fi interpretat
     * ca un id de cont.
     */
    public function export(Request $request): RedirectResponse|HttpResponse
    {
        $this->authorize('export', Account::class);

        $list = new AccountList;
        $listQuery = $list->parse($request);
        $user = $request->user();
        $query = $list->query($listQuery, $user);

        $total = (clone $query)->toBase()->getCountForPagination();
        $threshold = (int) config('throughput.limits.export_sync_max_rows');

        if ($total <= $threshold) {
            // NU `response()->streamDownload()`: callback-ul lui rulează DUPĂ ce
            // răspunsul a fost trimis, adică după închiderea tranzacției cererii — fără
            // context de tenant (capcană cunoscută, `.ai/rules/tenancy.md`). Sub prag,
            // fișierul e mic — se construiește întreg în memorie, ca orice alt răspuns.
            $csv = CsvExporter::toString($list, $query);

            return response($csv, 200, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="accounts-'.now()->format('Y-m-d-His').'.csv"',
            ]);
        }

        if (DemoMode::exceedsBulkRowCap($total)) {
            return back()->with('error', 'This export exceeds the demo limit and cannot be started.');
        }

        $tenantId = app('tenant')->getKey();

        $operation = BulkOperation::create([
            'user_id' => $user->getKey(),
            'resource_type' => 'accounts',
            'action' => 'export',
            'filter_snapshot' => [...$listQuery->toArray(), 'userId' => $user->getKey()],
            'total_rows' => $total,
            'status' => BulkOperation::STATUS_PENDING,
        ]);

        ExportListJob::dispatch($tenantId, $operation->getKey())->onQueue('bulk');

        return redirect()->route('exports.show', $operation)->with('success', 'Export started — this page will update automatically.');
    }

    /**
     * Membrii activi ai tenantului curent — dropdown de owner (Create/Edit) și filtru
     * „membru anume" pe Index. Array simplu, nu Resource: doar id/name, deja selectate,
     * nu un model brut (regula 1 din plan §1.2 vizează expunerea necontrolată de coloane).
     *
     * @return list<array{id: string, name: string}>
     */
    private function ownerOptions(): array
    {
        return Membership::query()
            ->where('status', Membership::STATUS_ACTIVE)
            ->with('user:id,name')
            ->get()
            ->map(fn (Membership $membership) => ['id' => $membership->user->id, 'name' => $membership->user->name])
            ->values()
            ->all();
    }
}
