<?php

namespace Database\Factories;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    protected $model = Contact::class;

    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->safeEmail(),
            'phone' => fake()->numerify('(###) ###-####'),
            'title' => fake()->randomElement([
                'Purchasing Manager', 'Operations Director', 'Owner', 'Buyer',
                'Warehouse Manager', 'Controller', 'Plant Manager', 'Procurement Lead',
            ]),
            'is_primary' => false,
            'opt_out' => false,
        ];
    }

    public function primary(): static
    {
        return $this->state(fn () => ['is_primary' => true]);
    }
}
