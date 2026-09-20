<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Contacts\StoreContactRequest;
use App\Http\Resources\Api\V1\ContactResource;
use App\Models\Contact;
use App\Support\Contacts\PrimaryContactAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET|POST /api/v1/contacts` — scopuri `contacts:read` / `contacts:write` (FR-API-01).
 *
 * `StoreContactRequest` e REUTILIZAT din fluxul web, nu rescris: regulile de validare
 * (cont din tenantul curent, contact principal unic, avertismentul de email duplicat)
 * sunt reguli de business, nu ale unui canal anume. O a doua copie pentru API ar fi
 * însemnat că un `FR-CRM-*` schimbat trebuie găsit în două locuri.
 */
final class ContactController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Contact::class);

        $contacts = Contact::query()
            ->when($request->filled('accountId'), fn ($query) => $query->where('account_id', $request->string('accountId')))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return $this->paginated($request, $contacts, ContactResource::class);
    }

    public function show(Request $request, string $contact): JsonResponse
    {
        $model = $this->findForTenant(Contact::query(), $contact);

        $this->authorize('view', $model);

        return $this->item($request, new ContactResource($model));
    }

    public function store(StoreContactRequest $request): JsonResponse
    {
        $data = $request->validated();

        $contact = DB::transaction(function () use ($data, $request): Contact {
            if (($data['is_primary'] ?? false) && ($data['account_id'] ?? null) !== null) {
                PrimaryContactAssignment::apply($data['account_id']);
            }

            $contact = new Contact($data);
            $contact->created_by = $request->user()->getKey();
            $contact->save();

            return $contact;
        });

        return $this->item($request, new ContactResource($contact), Response::HTTP_CREATED);
    }
}
