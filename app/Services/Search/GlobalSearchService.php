<?php

namespace App\Services\Search;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\User;
use App\Support\RecentlyViewed;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * FR-SEARCH-01/02, BR-SEARCH-01 (specs.md §15.5) — căutarea globală (Cmd+K).
 *
 * Izolarea de tenant NU e treaba acestei clase (§1.2 regula din `App\Support\Lists\ResourceList`,
 * aceeași regulă aici): global scope-ul Eloquent + RLS se aplică deja pe fiecare `Model::query()`,
 * deci niciun `where tenant_id` manual. RBAC-ul e verificat o dată per grup, prin Policies
 * (`$user->can('viewAny', Account::class)` etc.) — FR-SEARCH-01 cere „aceleași Policies" cu
 * restul aplicației, nu o citire directă a permisiunii care ar ocoli `AccountPolicy`/
 * `ContactPolicy`/`DealPolicy`: un Agent vede orice cont/contact/deal din tenant (permisiunea
 * `.view` nu are îngustarea „doar ale mele", asta se aplică doar mutațiilor, în Policies).
 *
 * Motorul e `pg_trgm`, nu un serviciu extern (FR-SEARCH-02): operatorul `%` (prag implicit 0.3,
 * NECONFIGURAT — nicio `SET pg_trgm.similarity_threshold`) combinat cu `ILIKE` rămân logica de
 * potrivire — toleranța la greșeli de tastare și potrivirea pe subșiruri.
 *
 * FĂRĂ index GIN trigram (ADR-018): `%`, `similarity()` și `ILIKE` (`texticlike`) nu sunt
 * LEAKPROOF (`proleakproof = false`), deci sub RLS PostgreSQL le evaluează DUPĂ condiția de
 * tenant și nu le poate folosi drept condiție de index — planificatorul cade pe indexul de
 * tenant cu filtru (`accounts`/`deals`) sau pe Seq Scan (`contacts`), măsurat pe tenantul
 * Marlin. Interogările de aici rămân neschimbate față de ce ar rula cu index: filtrul e
 * oricum evaluat pe toate rândurile tenantului curent. Vezi ADR-018 și
 * `ExplainCriticalQueries`, care acceptă explicit acest plan pentru intrările de căutare,
 * sub un buget de timp.
 */
final class GlobalSearchService
{
    public const MIN_QUERY_LENGTH = 2;

    public const MAX_QUERY_LENGTH = 100;

    private const RESULTS_PER_GROUP = 5;

    /**
     * Starea inițială, înainte de a tipări (FR-SEARCH-01): recent accesate din acest workspace
     * + acțiuni frecvente permise. Niciodată gol prin construcție — dacă utilizatorul n-are
     * nimic recent și nicio permisiune de creare, `groups` iese `[]`, iar interfața arată un
     * îndemn de tipărire, nu o eroare (§7.3: un grup ABSENT, nu un buton dezactivat).
     *
     * @return array{query: string, groups: list<array{type: string, label: string, results: list<array<string, mixed>>}>}
     */
    public function initialState(Request $request): array
    {
        $groups = [];

        $recent = RecentlyViewed::all($request);

        if ($recent !== []) {
            $groups[] = [
                'type' => 'recent',
                'label' => 'Recent',
                'results' => collect($recent)->map(fn (array $item) => [
                    'type' => $item['type'],
                    'id' => $item['id'],
                    'label' => $item['label'],
                    'sublabel' => null,
                    'url' => $item['url'],
                ])->all(),
            ];
        }

        /** @var User $user */
        $user = $request->user();
        $actions = $this->frequentActions($user);

        if ($actions !== []) {
            $groups[] = ['type' => 'actions', 'label' => 'Actions', 'results' => $actions];
        }

        return ['query' => '', 'groups' => $groups];
    }

    /**
     * Căutare propriu-zisă. Grupurile fără permisiune de `.view` sunt SĂRITE înainte de orice
     * interogare (§7.3) — nu doar ascunse după ce s-au dus la bază de date degeaba. Un grup cu
     * zero rezultate e omis (nu afișat gol): tipărirea unui termen care nu se potrivește cu
     * nimic dintr-un tip dat nu trebuie să arate o etichetă fără conținut sub ea.
     *
     * @return array{query: string, groups: list<array{type: string, label: string, results: list<array<string, mixed>>}>}
     */
    public function search(User $user, string $term): array
    {
        $groups = [];

        if ($user->can('viewAny', Account::class)) {
            $results = $this->accountResults($term);

            if ($results !== []) {
                $groups[] = ['type' => 'accounts', 'label' => 'Accounts', 'results' => $results];
            }
        }

        if ($user->can('viewAny', Contact::class)) {
            $results = $this->contactResults($term);

            if ($results !== []) {
                $groups[] = ['type' => 'contacts', 'label' => 'Contacts', 'results' => $results];
            }
        }

        if ($user->can('viewAny', Deal::class)) {
            $results = $this->dealResults($term);

            if ($results !== []) {
                $groups[] = ['type' => 'deals', 'label' => 'Deals', 'results' => $results];
            }
        }

        $actions = $this->searchActions($user, $term);

        if ($actions !== []) {
            $groups[] = ['type' => 'actions', 'label' => 'Actions', 'results' => $actions];
        }

        return ['query' => $term, 'groups' => $groups];
    }

    /**
     * @return list<array{type: string, id: string, label: string, sublabel: string|null, url: string}>
     */
    private function accountResults(string $term): array
    {
        $like = $this->likePattern($term);

        return Account::query()
            ->select(['id', 'name', 'domain', 'status'])
            ->where(fn (Builder $q) => $q->whereRaw('name % ?', [$term])->orWhere('name', 'ilike', $like))
            ->orderByRaw('similarity(name, ?) desc', [$term])
            ->limit(self::RESULTS_PER_GROUP)
            ->get()
            ->map(fn (Account $account) => [
                'type' => 'account',
                'id' => $account->id,
                'label' => $account->name,
                'sublabel' => $account->domain ?? ucfirst($account->status),
                'url' => "/{$this->tenantSlug()}/accounts/{$account->id}",
            ])
            ->all();
    }

    /**
     * @return list<array{type: string, id: string, label: string, sublabel: string|null, url: string}>
     */
    private function contactResults(string $term): array
    {
        $like = $this->likePattern($term);

        return Contact::query()
            ->select(['id', 'first_name', 'last_name', 'account_id', 'email'])
            ->with('account:id,name')
            ->where(fn (Builder $q) => $q
                ->whereRaw("(first_name || ' ' || last_name) % ?", [$term])
                ->orWhereRaw("(first_name || ' ' || last_name) ilike ?", [$like]))
            ->orderByRaw("similarity(first_name || ' ' || last_name, ?) desc", [$term])
            ->limit(self::RESULTS_PER_GROUP)
            ->get()
            ->map(fn (Contact $contact) => [
                'type' => 'contact',
                'id' => $contact->id,
                'label' => trim("{$contact->first_name} {$contact->last_name}"),
                'sublabel' => $contact->account?->name ?? $contact->email,
                'url' => "/{$this->tenantSlug()}/contacts/{$contact->id}",
            ])
            ->all();
    }

    /**
     * @return list<array{type: string, id: string, label: string, sublabel: string|null, url: string}>
     */
    private function dealResults(string $term): array
    {
        $like = $this->likePattern($term);

        return Deal::query()
            ->select(['id', 'title', 'account_id'])
            ->with('account:id,name')
            ->where(fn (Builder $q) => $q->whereRaw('title % ?', [$term])->orWhere('title', 'ilike', $like))
            ->orderByRaw('similarity(title, ?) desc', [$term])
            ->limit(self::RESULTS_PER_GROUP)
            ->get()
            ->map(fn (Deal $deal) => [
                'type' => 'deal',
                'id' => $deal->id,
                'label' => $deal->title,
                'sublabel' => $deal->account?->name,
                'url' => "/{$this->tenantSlug()}/deals/{$deal->id}",
            ])
            ->all();
    }

    /**
     * Acțiuni generice, pentru starea inițială (fără termen de căutare încă).
     *
     * @return list<array{type: string, id: string, label: string, sublabel: null, url: string}>
     */
    private function frequentActions(User $user): array
    {
        $actions = [];

        if ($user->can('create', Account::class)) {
            $actions[] = $this->action('create-account', 'Create account', "/{$this->tenantSlug()}/accounts/create");
        }

        if ($user->can('create', Contact::class)) {
            $actions[] = $this->action('create-contact', 'Create contact', "/{$this->tenantSlug()}/contacts/create");
        }

        if ($user->can('create', Deal::class)) {
            $actions[] = $this->action('create-deal', 'Create deal', "/{$this->tenantSlug()}/deals/create");
        }

        return $actions;
    }

    /**
     * Acțiuni cu termenul tipărit (FR-SEARCH-01): doar contul primește varianta „named '…'",
     * cu `?name=` — singura formă din contractul de URL convenit pentru Faza 2 (raportul
     * agentului). Contacte/deals rămân pe formele generice: nu există un query param de
     * prefill convenit pentru ele, iar „title"/„first_name"+„last_name" nu se mapează 1:1 pe
     * un singur `name`.
     *
     * @return list<array{type: string, id: string, label: string, sublabel: null, url: string}>
     */
    private function searchActions(User $user, string $term): array
    {
        $actions = [];

        if ($user->can('create', Account::class)) {
            $actions[] = $this->action(
                'create-account',
                sprintf('Create account named "%s"', $term),
                "/{$this->tenantSlug()}/accounts/create?name=".urlencode($term)
            );
        }

        if ($user->can('create', Contact::class)) {
            $actions[] = $this->action('create-contact', 'Create contact', "/{$this->tenantSlug()}/contacts/create");
        }

        if ($user->can('create', Deal::class)) {
            $actions[] = $this->action('create-deal', 'Create deal', "/{$this->tenantSlug()}/deals/create");
        }

        return $actions;
    }

    /**
     * @return array{type: string, id: string, label: string, sublabel: null, url: string}
     */
    private function action(string $id, string $label, string $url): array
    {
        return ['type' => 'action', 'id' => $id, 'label' => $label, 'sublabel' => null, 'url' => $url];
    }

    /**
     * Caractere speciale LIKE escapate (§ instrucțiuni pachet G) — un `%` sau `_` tastat de
     * utilizator nu trebuie citit ca metacaracter de pattern.
     */
    private function likePattern(string $term): string
    {
        return '%'.addcslashes($term, '%_\\').'%';
    }

    private function tenantSlug(): string
    {
        return app('tenant')->slug;
    }
}
