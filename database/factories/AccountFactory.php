<?php

namespace Database\Factories;

use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    protected $model = Account::class;

    /**
     * Definiție generică, plauzibilă (specs.md §21.2). Seed-ul de volum
     * (Database\Seeders\Demo\AccountsAndContactsSeeder) suprascrie `name`/`domain`/
     * `industry` cu generatoare specifice verticalei tenantului — aici rămân valori
     * rezonabile pentru folosire independentă a factory-ei (teste, tinker).
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'domain' => fake()->domainName(),
            'industry' => fake()->randomElement(['Distribution', 'Manufacturing', 'Retail', 'Construction']),
            'billing_address' => $this->address(),
            'shipping_address' => $this->address(),
            'phone' => fake()->numerify('(###) ###-####'),
            'tags' => [],
            'status' => fake()->randomElement(['prospect', 'active', 'active', 'active', 'inactive']),
            'credit_terms' => fake()->randomElement(['net_15', 'net_30', 'net_30', 'net_60', 'prepaid']),
            'source' => fake()->randomElement(['referral', 'trade_show', 'cold_outreach', 'website', 'partner']),
        ];
    }

    public function prospect(): static
    {
        return $this->state(fn () => ['status' => Account::STATUS_PROSPECT]);
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => Account::STATUS_ACTIVE]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => Account::STATUS_INACTIVE]);
    }

    /** @return array<string, string> */
    private function address(): array
    {
        return [
            'line1' => fake()->streetAddress(),
            'city' => fake()->city(),
            'state' => fake()->stateAbbr(),
            'postal_code' => fake()->postcode(),
            'country' => 'US',
        ];
    }
}
