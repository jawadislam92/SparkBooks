<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class TaxRateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'GST '.fake()->randomElement(['5', '10', '18']).'%',
            'rate' => fake()->randomElement(['5.0000', '10.0000', '18.0000']),
            'is_archived' => false,
        ];
    }
}
