<?php

namespace App\Http\Controllers\Web\Contacts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Contacts\StoreContactRequest;
use App\Http\Requests\Contacts\UpdateContactRequest;
use App\Http\Resources\ContactResource;
use App\Models\Account;
use App\Models\Contact;
use App\Models\User;
use App\Support\Contacts\PrimaryContactAssignment;
use App\Support\Exports\ListExport;
use App\Support\ListQuery;
use App\Support\Lists\ContactList;
use App\Support\RecentlyViewed;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CRUD de contacte — FR-CRM-01…02, US-CRM-01, plan §8.
 *
 * Thin controller: regula „un singur primary per cont" trăiește în
 * `PrimaryContactAssignment` și avertismentul de email duplicat în
 * `DuplicateContactEmail` (validat în FormRequests) — ambele testabile izolat de
 * HTTP, plan §1.2.
 */
class ContactController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Contact::class);

        $list = new ContactList;
        $listQuery = $list->parse($request);
        $user = $request->user();

        return Inertia::render('Contacts/Index', [
            'contacts' => Inertia::defer(fn () => $this->paginatedContacts($list, $listQuery, $user)),
            'list' => $listQuery->toArray(),
            'can' => [
                'create' => Gate::forUser($user)->allows('create', Contact::class),
                'export' => Gate::forUser($user)->allows('export', Contact::class),
            ],
        ]);
    }

    /**
     * §13.5 — export CSV al listei filtrate curent, prin mecanismul comun (`ListExport`).
     */
    public function export(Request $request): RedirectResponse|HttpResponse
    {
        Gate::authorize('export', Contact::class);

        return app(ListExport::class)->respond($request, 'contacts');
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', Contact::class);

        // FR-CRM-02, point 5 — precompletare `?account={id}` din linkul „Add contact"
        // al paginii de cont. `Account::query()->find()` respectă global scope-ul de
        // tenant: un id din alt tenant sau inexistent redă pur și simplu `null`, ca un
        // formular de lead brut, nu o eroare.
        $accountId = $request->query('account');
        $account = is_string($accountId) ? Account::query()->select(['id', 'name'])->find($accountId) : null;

        return Inertia::render('Contacts/Create', [
            'account' => $account === null ? null : ['id' => $account->id, 'name' => $account->name],
        ]);
    }

    public function store(StoreContactRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $contact = DB::transaction(function () use ($data, $request) {
            if (($data['is_primary'] ?? false) && $data['account_id'] !== null) {
                PrimaryContactAssignment::apply($data['account_id']);
            }

            $contact = new Contact($data);
            $contact->created_by = $request->user()->getKey();
            $contact->save();

            return $contact;
        });

        return redirect()
            ->route('contacts.show', $contact)
            ->with('success', 'Contact created.');
    }

    public function show(Request $request, Contact $contact): Response
    {
        Gate::authorize('view', $contact);

        // `owner_user_id` intră în select DELIBERAT deși ContactResource nu-l expune:
        // ContactPolicy::isWithinOwnRecords() îl citește pe relația deja încărcată — un
        // select mai îngust ar întoarce `null` acolo (nu o eroare), iar `can.edit` al
        // unui Agent responsabil de cont ar ieși fals negativ (§7.5).
        $contact->load([
            'account:id,name,owner_user_id',
            'deals' => fn ($query) => $query->select(['id', 'account_id', 'primary_contact_id', 'title', 'status', 'value'])->latest(),
        ]);

        RecentlyViewed::record(
            $request,
            'contact',
            $contact->getKey(),
            trim("{$contact->first_name} {$contact->last_name}"),
            $request->fullUrl(),
        );

        return Inertia::render('Contacts/Show', [
            'contact' => ContactResource::make($contact),
            'can' => [
                'edit' => Gate::forUser($request->user())->allows('update', $contact),
                'delete' => Gate::forUser($request->user())->allows('delete', $contact),
            ],
        ]);
    }

    public function edit(Request $request, Contact $contact): Response
    {
        Gate::authorize('update', $contact);

        $contact->load('account:id,name,owner_user_id');

        return Inertia::render('Contacts/Edit', [
            'contact' => ContactResource::make($contact),
        ]);
    }

    public function update(UpdateContactRequest $request, Contact $contact): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $contact): void {
            if (($data['is_primary'] ?? false) && $data['account_id'] !== null) {
                PrimaryContactAssignment::apply($data['account_id'], $contact->getKey());
            }

            $contact->fill($data)->save();
        });

        return redirect()
            ->route('contacts.show', $contact)
            ->with('success', 'Contact updated.');
    }

    public function destroy(Contact $contact): RedirectResponse
    {
        Gate::authorize('delete', $contact);

        $contact->delete();

        return redirect()->route('contacts.index')->with('success', 'Contact deleted.');
    }

    /**
     * @return array{data: list<array<string, mixed>>, nextCursor: ?string, prevCursor: ?string}
     */
    private function paginatedContacts(ContactList $list, ListQuery $listQuery, User $user): array
    {
        $paginator = $listQuery->paginate($list->query($listQuery, $user));

        return [
            'data' => ContactResource::collection($paginator->items())->resolve(),
            'nextCursor' => $paginator->nextCursor()?->encode(),
            'prevCursor' => $paginator->previousCursor()?->encode(),
        ];
    }
}
