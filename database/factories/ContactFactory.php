<?php

namespace Database\Factories;

use App\Enums\ContactType;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContactFactory extends Factory
{
    public function definition(): array
    {
        return [
            'type' => ContactType::Customer->value,
            'name' => fake()->company(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'billing_address' => null,
            'tax_number' => null,
            'notes' => null,
            'is_archived' => false,
        ];
    }
}
