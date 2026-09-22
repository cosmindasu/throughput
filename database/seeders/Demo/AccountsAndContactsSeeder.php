<?php

namespace Database\Seeders\Demo;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Tenant;
use Database\Factories\AccountFactory;
use Database\Factories\ContactFactory;
use Database\Seeders\Support\ActivityLogRecorder;
use Database\Seeders\Support\ChunkedWriter;
use Database\Seeders\Support\DemoClock;
use Database\Seeders\Support\DemoId;
use Database\Seeders\Support\DemoNames;
use Database\Seeders\Support\Rand;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Conturi + contacte în bloc (plan §7.8): definițiile plauzibile vin din factories
 * (`definition()`), persistarea e prin `Model::insert()` pe chunk-uri de ~1000 — ocolește
 * evenimentele Eloquent, deci `id`/`tenant_id` se scriu explicit pe fiecare rând.
 */
final class AccountsAndContactsSeeder
{
    /**
     * @param  array{owner_id: string, demo_agent_id: ?string, pool: list<array{id: string, role: string}>}  $staff
     * @return array{
     *   accounts: list<array{id: string, owner_user_id: string, created_at: int, credit_terms: string, status: string}>,
     *   contacts_by_account: array<string, list<string>>,
     * }
     */
    public function run(Tenant $tenant, array $config, array $staff, ?Command $command, ActivityLogRecorder $activityLog): array
    {
        $accountFactory = new AccountFactory;
        $contactFactory = new ContactFactory;

        $total = $config['accounts'];
        $writer = new ChunkedWriter(Account::class, 1000, $command, 'Accounts', $total);
        $contactWriter = (new ChunkedWriter(Contact::class, 1000, $command, 'Contacts', (int) ($total * 1.3)))
            ->dependsOn($writer);   // FK contacts.account_id — vezi ChunkedWriter::dependsOn()

        $agents = array_values(array_filter($staff['pool'], fn ($m) => $m['role'] === 'Agent'));
        $managers = array_values(array_filter($staff['pool'], fn ($m) => $m['role'] === 'Manager'));
        $ownerId = $staff['owner_id'];

        $pickOwner = function () use ($agents, $managers, $ownerId): string {
            $roll = random_int(1, 100);

            if ($roll <= 75 && $agents !== []) {
                return $agents[array_rand($agents)]['id'];
            }

            if ($roll <= 92 && $managers !== []) {
                return $managers[array_rand($managers)]['id'];
            }

            return $ownerId;
        };

        $accounts = [];
        $contactsByAccount = [];

        for ($i = 1; $i <= $total; $i++) {
            $id = DemoId::next();
            // Primele conturi merg garantat la Agentul demo: „My accounts" (US-CRM-02) trebuie să
            // aibă ce arăta la orice scară. La scara E2E (40 de conturi, 5 agenți), distribuția
            // aleatoare l-ar lăsa ocazional fără niciunul — un demo gol și un test instabil.
            $accountOwner = $i <= 5 && $staff['demo_agent_id'] !== null ? $staff['demo_agent_id'] : $pickOwner();
            $createdAt = DemoClock::historicalDate(24);
            // Numele ȘI industria vin împreună: pool-ul francez (FR-I18N-07) le schimbă pe
            // amândouă odată, altfel ar ieși firme cu nume francez și industrie engleză.
            ['name' => $name, 'industry' => $industry] = DemoNames::account($config['vertical'], $config['industry']);
            $domain = DemoNames::domain($name, $i);

            $row = $accountFactory->definition();
            $row['id'] = $id;
            $row['tenant_id'] = $tenant->id;
            $row['name'] = $name;
            $row['domain'] = $domain;
            $row['industry'] = $industry;
            $row['billing_address'] = json_encode($row['billing_address']);
            $row['shipping_address'] = json_encode($row['shipping_address']);
            $row['tags'] = json_encode([]);
            $row['owner_user_id'] = $accountOwner;
            $row['created_by'] = $accountOwner;
            $row['created_at'] = $createdAt;
            $row['updated_at'] = $createdAt;

            $writer->push($row);
            $activityLog->record($tenant->id, $accountOwner, 'created', Account::class, $id, $createdAt, null, ['name' => $name]);

            // Timestamp, nu Carbon: lista trăiește cât tot seed-ul tenantului (deals, comenzi,
            // facturare), iar un obiect Carbon costă ~2 KB — aceeași economie de memorie ca la
            // rezumatele de comenzi din StockAndOrdersSeeder.
            $accounts[] = [
                'id' => $id,
                'owner_user_id' => $accountOwner,
                'created_at' => $createdAt->getTimestamp(),
                'credit_terms' => $row['credit_terms'],
                'status' => $row['status'],
            ];

            $contactCount = Rand::bool(30) ? 2 : 1;
            $contactIds = [];

            for ($c = 1; $c <= $contactCount; $c++) {
                $contactId = DemoId::next();
                $contactRow = $contactFactory->definition();
                $firstName = $contactRow['first_name'];
                $lastName = $contactRow['last_name'];

                $contactRow['id'] = $contactId;
                $contactRow['tenant_id'] = $tenant->id;
                $contactRow['account_id'] = $id;
                $contactRow['is_primary'] = $c === 1;
                $contactRow['email'] = Rand::bool(90)
                    ? strtolower($firstName.'.'.$lastName).'@'.$domain
                    : null;
                $contactRow['created_by'] = $accountOwner;
                $contactRow['created_at'] = $createdAt;
                $contactRow['updated_at'] = $createdAt;

                $contactWriter->push($contactRow);
                $contactIds[] = $contactId;
            }

            $contactsByAccount[$id] = $contactIds;
        }

        $writer->flush();
        $contactWriter->flush();
        $activityLog->flush();

        return ['accounts' => $accounts, 'contacts_by_account' => $contactsByAccount];
    }
}
