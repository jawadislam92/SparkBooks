<?php

namespace Database\Factories;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use Illuminate\Database\Eloquent\Factories\Factory;

class AccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->numerify('8###'),
            'name' => fake()->words(3, true),
            'type' => AccountType::Expense->value,
            'subtype' => AccountSubtype::OperatingExpense->value,
            'is_system' => false,
            'is_archived' => false,
            'is_postable' => true,
            'description' => null,
        ];
    }
}
