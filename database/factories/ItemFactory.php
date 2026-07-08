<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'description' => null,
            'unit_price' => 0,
            'default_income_account_id' => null,
            'default_tax_rate_id' => null,
            'is_archived' => false,
        ];
    }
}
